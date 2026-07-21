<?php

declare(strict_types=1);

namespace Claw\Run;

use Claw\Agent\AgentInterface;
use Claw\Agent\AgentSpeaker;
use Claw\Agent\Budget;
use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\EscalatingSpeaker;
use Claw\Agent\Message;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Claw\Config;
use Claw\Exceptions\AgentException;
use Claw\Exceptions\ClawException;
use Claw\Exceptions\WorkflowException;
use Claw\Http\CurlHttpClient;
use Claw\Knowledge\KnowledgeIndex;
use Claw\Knowledge\OpenAiEmbedder;
use Claw\Project\Issue;
use Claw\Project\IssueStatus;
use Claw\Project\IssueType;
use Claw\Project\ProjectStoreInterface;
use Claw\Project\RunStatus;
use Claw\Project\Strategy;
use Claw\Tool\RecallTool;
use Claw\Tool\Secrets;
use Claw\Tool\ToolFactory;
use Claw\Tool\Workspace;
use Claw\Trace\Tracer;
use Claw\Trace\TraceReader;
use Claw\Workflow\BudgetPolicy;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;
use Claw\Workflow\GenerateIssueWorkflow;
use Claw\Workflow\SqliteStateStore;
use Claw\Workflow\SuperviseWorkflow;
use Claw\Workflow\WorkflowAbstract;
use Claw\Workflow\WorkflowStore;

/**
 * Runs one issue's solver workflow to completion, headless. The shared run engine behind both
 * `claw run` (console) and the dashboard server's POST /start (a coroutine on the event loop): it
 * wires the run environment, generates or reuses the solver, runs it, and on a runtime crash asks
 * the supervisor to repair it and resumes the same run from its durable snapshot.
 *
 * Everything that differs between the console and the server is behind one {@see RunFrontendInterface}
 * (the human tier, the solver-approval decision, progress reporting, the live trace sink), so the
 * pipeline itself holds no I/O opinion.
 */
final readonly class IssueRunner
{
    /** How many times the supervisor may repair-and-resume a crashing solver before giving up. */
    private const int MAX_REPAIRS = 2;

    /**
     * The standing brief for the `direct` path. Short on purpose: this path is chosen for work that
     * needs no plan, and a long prompt would talk a model into ceremony the ProjectManager already
     * decided against. The one thing it insists on is the same thing the critic insists on elsewhere
     * — verify by running something, not by asserting.
     */
    private const string DIRECT_SYSTEM = <<<'PROMPT'
        You are solving one small, self-contained ticket in this project, directly. It was judged not
        to need a plan or a workflow, so do the work: read what you need, make the change, and prove
        it holds — run the linter or the tests and read their output before you believe yourself.

        A clean syntax check is not proof that a task is finished. If the project has tests covering
        what you touched, run them.

        When the change exists and you have SEEN it work, stop and say in one line what you changed
        and what you ran to verify it. Whether the ticket counts as solved is then checked against the
        project by someone else — so there is nothing to be gained by claiming more than you did, and
        a claim that does not hold simply sends the ticket back.

        If you CANNOT finish — the ticket is unclear, it asks for something that is not there, or it
        turns out to be far bigger than it looked — say so using the `[question]` protocol described
        above: it reaches a person, and your reply comes back so you can carry on.
        PROMPT;

    /**
     * The completion judge's brief. It decides one thing and reports it in one word, because the code
     * branches on that word — prose about how nearly finished the work is has closed tickets before.
     */
    private const string JUDGE_SYSTEM = <<<'PROMPT'
        You are checking whether a ticket has ACTUALLY been solved. Someone has just worked on this
        project, claiming to address the ticket below. Your job is to establish whether they did.

        Do not take anyone's word for it, and do not reason from what a sensible worker would have
        done. Look: read the files the ticket is about, and RUN the thing that would prove it — the
        project's tests, the command in the ticket, whatever settles the question. A clean syntax
        check proves nothing. An unread file proves nothing.

        Judge only the ticket in front of you. A test suite that was already failing for unrelated
        reasons is not this ticket's problem; say so and judge what this ticket asked for.

        You may read and run things. You must NOT change anything — no edits, no state-changing git or
        shell commands. A project you modified is a verdict you invalidated.

        Your FIRST LINE is the verdict, and it must be one of these two words and NOTHING else on
        that line — no backticks, no bold, no preamble, no trailing sentence:

            SOLVED
            UNSOLVED

        Put what you checked and what you saw on the lines AFTER it: one or two sentences naming the
        command you ran and its result, or what is still missing. On an UNSOLVED verdict those lines
        are what the next attempt is given to work from, so say what is wrong, not that it is wrong.

        The first line is read by code, not by a person, and a line that is neither word counts as
        UNSOLVED. If you cannot establish it either way, that is UNSOLVED — say what you could not
        check.
        PROMPT;

    /** The supervisor agent's standing role — it settles in-run escalations or defers to the human. */
    private const string SUPERVISOR_SYSTEM = <<<'PROMPT'
        You are the SUPERVISOR of an autonomous coding workflow. You are consulted when a step is stuck:
        a worker pauses with a question, or a step's work failed review and the run asks whether to keep
        going. Your job is to UNBLOCK with the smallest sound decision, so the run does not churn.

        THE CONTROL WORDS ARE WHOLE REPLIES. `accept`, `stop` and `ESCALATE` count only when the word is
        the ENTIRE answer — nothing before it, nothing after it. A reply that merely contains one is read
        as guidance and sent back to the step, which is deliberate: "Stop rerunning the whole suite, run
        only the failing test" is advice, not an order to abandon the run.

        How to answer (reply with ONLY the decision, no preamble):
        - To resolve a "did not pass review / is this OK?" escalation, reply with exactly one of:
          `accept` — the work is good enough as is, stop reworking;
          `stop`   — the goal cannot be reached here (e.g. a required tool is missing, the gate is
                     unsatisfiable in this environment) or it is looping with no progress — abort the step;
          or a short, concrete GUIDANCE for ONE more attempt (only if a specific fix is likely to work).
        - To answer a worker's question, give the briefest concrete answer that lets it proceed.

        LOOK BEFORE YOU ACCEPT. You have the project's tools — read the files the step changed, run the
        tests or the linter yourself. `accept` is a claim that the work is good, and the only things in
        front of you are the critic's complaint and a summary the step wrote about ITSELF. Accept on the
        strength of what you observed, or because the critic's objection is about form rather than
        substance — never because the step says so.

        A blocker you cannot verify is `ESCALATE`, not `accept`. That includes every claim that the
        environment is at fault: "the test runner is missing", "the dependency is not installed". Go and
        check, and if you cannot, say so upward. A finding of the class "this claim has no evidence
        behind it" is never grounds to accept.

        Bias to ending churn: if a step has failed several times for the SAME reason and you have seen
        why, choose `accept` (when you have verified the work is right) or `stop` — do NOT keep saying
        "try again".

        Reply exactly `ESCALATE` only when the decision genuinely needs a human (a scope or product call
        you must not make alone); it will then be passed up to the person.
        PROMPT;

    public function __construct(
        private string $projectsDir,
        private ProjectStoreInterface $store,
        private Config $config,
        private AgentInterface $agent,
        private RunFrontendInterface $frontend,
    ) {
    }

    /**
     * Generate/reuse the solver for the issue and run it. Returns a process-style exit code: 0 on a
     * finished run (or a solver saved-but-not-run), 1 on a failed generation/run. The issue moves to
     * InProgress at the start and Done when every step has run.
     */
    public function run(Issue $issue): int
    {
        $project = $this->store->project();

        // The palette acts on the REAL project folder: this run works on the user's repo.
        $workspace = new Workspace($project->path);
        $workflowStore = new WorkflowStore($this->projectsDir . '/' . $project->id . '-workflows', $project->id);
        $projectDb = $this->store->pdo();   // the one open connection: shared by the state store + trace
        // The project's credentials, read from beside its state database — never from inside the project
        // itself, which is both within the run's reach and within the project's git.
        $secretsPath = Secrets::pathFor($this->projectsDir, $project->id);
        Secrets::assertOutside($secretsPath, $project->path);
        // The knowledge base lives beside the project's state database like everything else durable
        // about it, and is built only when the project actually has notes — see ToolFactory::knowledge().
        $index = new KnowledgeIndex(new \PDO('sqlite:' . $this->projectsDir . '/' . $project->id . '.kb.db'));
        $embedder = $this->config->baseUrl === null
            ? null
            : new OpenAiEmbedder(new CurlHttpClient(), $this->config->baseUrl, $this->config->apiKey);

        $registry = ToolFactory::forRun(
            $project,
            $workspace,
            Secrets::fromFile($secretsPath),
            $index,
            $embedder,
            $this->config->turnTimeoutMs,
        );

        // The store is durable (a killed run resumes from its snapshot); budgets cap the run total and
        // each exchange (0 = unlimited); named agent roles share the access and override only the model.
        // NO system prompt is set, and that is the decision. A run used to carry `Config::DEFAULT_SYSTEM`
        // — "You are Claw, a helpful coding assistant. Be concise." — under every model call of every
        // step of every solver. Two sentences, both working against what the pipeline is for: the eager
        // persona is the one behind successes this project was told about and did not get, and "be
        // concise" is an instruction to summarise, which is the shape of the step that recorded "All
        // tests passed." while the suite was erroring. The step's own prompt says what the step must do,
        // and the tool briefing is appended by the loop; nothing above them needs to add a character.
        $env = new Environment()
            ->set(EnvKey::Worker, $this->agent)
            ->set(EnvKey::Registry, $registry)
            ->set(EnvKey::ModelId, $this->config->model)
            ->set(EnvKey::MaxHistory, $this->config->maxHistory)
            ->set(EnvKey::Store, new SqliteStateStore($projectDb))
            ->set(EnvKey::Agents, $this->config->agents)
            ->set(EnvKey::Budget, new Budget($this->treeAllowance($issue), (float) $this->config->budgetSeconds))
            ->set(EnvKey::TurnTokenLimit, $this->config->turnTokens)
            ->set(EnvKey::TurnTimeLimit, (float) $this->config->turnSeconds)
            ->set(EnvKey::BudgetPolicy, BudgetPolicy::from($this->config->budgetPolicy))
            // The same cap the chat path has always put on a tool run, finally on the path that needs it
            // more: here nobody is watching, so a `bash` waiting forever on a prompt nobody will type
            // holds the run open with no clock ticking against it.
            ->set(EnvKey::ToolTimeoutMs, $this->config->turnTimeoutMs);

        // The verdict is read BEFORE the run is recorded, because for a `library` ticket it decides what
        // the run IS: the ledger row, the resume lookup and the trace all key off the workflow's name, and
        // recording a ready-made run under a generated solver's name would misfile all three.
        $verdict = $this->store->currentStrategy($issue->id);
        $strategy = $verdict['strategy'] ?? null;

        if ($strategy === Strategy::Library) {
            $library = $this->libraryWorkflow($issue, (string) ($verdict['workflow'] ?? ''));

            if ($library === null) {
                return 1;   // the recorded workflow is gone; the ticket is a person's now, see libraryWorkflow()
            }

            [$solverName, $solverClass] = $library;
        } else {
            $solverName = 'Issue' . (string) preg_replace('/[^A-Za-z0-9]/', '', $issue->id) . 'Solver';
            $solverClass = $workflowStore->classFor($solverName, true);
        }

        // Resume an interrupted run (status still 'running') for this issue's solver, else start a new
        // one. The run id ties the ledger row, the trace, and the durable state snapshot together — so
        // resuming reuses it: the solver restores its saved state and re-runs only the unfinished tail.
        $runId = $this->store->resumableRun($issue->id, $solverName);
        $resuming = $runId !== null;

        if ($runId === null) {
            $runId = $this->store->recordRun($issue->id, $solverName);
        }
        $this->store->setIssueStatus($issue->id, IssueStatus::InProgress);

        $tracer = new Tracer($runId, ...$this->frontend->traceSinks($projectDb));
        $env->set(EnvKey::Tracer, $tracer);

        // The ask channel is a ladder: a SUPERVISOR AGENT first (it can unblock a stuck step or settle a
        // critic escalation on its own judgement), then the human tier behind it. The human tier is built
        // here, now the tracer exists, because the HTTP gate records its question/answer through it.
        $env->set(EnvKey::Ask, new EscalatingSpeaker(
            $this->supervisorSpeaker($env),
            $this->frontend->human($tracer),
        ));

        $taskBrief = "Title: {$issue->title}\n\nDescription: {$issue->description}";
        $registry->add(new RecallTool(new TraceReader($projectDb), $runId, $taskBrief));

        if ($resuming) {
            $this->frontend->report("Resuming run #{$runId} for issue #{$issue->id}…", false);
        }

        $ctx = new RunContext(
            $env,
            $tracer,
            $this->store,
            $workflowStore,
            $runId,
            $issue,
            $project,
            $solverName,
            $solverClass,
        );

        // Route on the ProjectManager's verdict. An untriaged issue keeps the old behaviour — generate
        // a solver — so a ticket opened before triage existed, or one whose analysis failed, still runs.
        return match ($strategy) {
            Strategy::Direct => $this->runDirect($ctx),
            Strategy::Decompose => $this->reportDecomposition($ctx),
            // A ready-made workflow needs no generation and no approval gate: it was written by a person
            // and reviewed once, which is the whole reason this strategy is cheaper than generating one.
            // It is not repairable either, for the same reason — see repairSolver().
            Strategy::Library => $this->runSolver($ctx, repairable: false),
            default => $this->runGenerated($ctx),
        };
    }

    /**
     * The written strategy an `approach` verdict named, or '' for any other verdict.
     *
     * A verdict naming a strategy that is no longer on the shelf yields '' rather than failing the run:
     * unlike a missing library workflow — which IS the run and leaves nothing to do — a missing recipe
     * only costs the generator its domain guidance, and it still has the ticket, the plan and the
     * general recipe. Stopping there would trade a worse solver for no solver.
     */
    private function chosenRecipe(Issue $issue): string
    {
        $verdict = $this->store->currentStrategy($issue->id);

        if (($verdict['strategy'] ?? null) !== Strategy::Approach) {
            return '';
        }

        $name = $verdict['workflow'];
        $project = $this->store->project();

        try {
            $offered = WorkflowStore::offered(
                WorkflowStore::libraries($this->config->library, $project->path, $project->id),
                $this->store->loadIssue($issue->id)->type ?? IssueType::Feature,
            );
        } catch (ClawException) {
            return '';
        }

        return (string) ($offered[$name]['recipe'] ?? '');
    }

    /**
     * Resolve the ready-made workflow a `library` verdict named, as [name, class].
     *
     * Returning null means the verdict cannot be carried out: the workflow that was on the shelf at
     * triage is not on it now — deleted, renamed, or the project was checked out somewhere without it.
     * That is not something a retry fixes and not something to silently generate around (which is
     * exactly what this strategy used to do), so the ticket goes to a person with the reason.
     *
     * @return ?array{0: string, 1: string}
     */
    private function libraryWorkflow(Issue $issue, string $name): ?array
    {
        $libraries = WorkflowStore::libraries(
            $this->config->library,
            $this->store->project()->path,
            $this->store->project()->id,
        );

        try {
            foreach ($libraries as $library) {
                foreach ($library->catalogue() as $entry) {
                    if ($entry['name'] === $name) {
                        return [$entry['name'], $entry['class']];
                    }
                }
            }

            $reason = $name === ''
                ? 'the verdict chose a ready-made workflow but recorded no name'
                : "the ready-made workflow '{$name}' is no longer in any library";
        } catch (ClawException $e) {
            $reason = 'the workflow library cannot be read: ' . $e->getMessage();
        }

        $this->frontend->report("Cannot run issue #{$issue->id}: {$reason}.", true);
        $this->store->setIssueStatus($issue->id, IssueStatus::WaitingHuman);

        return null;
    }

    /**
     * The `generate` path (and the fallback for anything not routed elsewhere): write a solver
     * workflow for the issue, have it approved, and run it.
     */
    private function runGenerated(RunContext $ctx): int
    {
        $early = $this->ensureSolver($ctx);

        if ($early !== null) {
            return $early;   // generation failed, or the solver was saved without running it
        }

        return $this->runSolver($ctx);
    }

    /**
     * The `direct` path: one agent, the run's tools, no generated class and no workflow machinery.
     *
     * This exists because generating a solver for a one-line change costs more than the change. The
     * ProjectManager picks it for localized, mechanical work, and here that means a single exchange
     * where the model reads, edits and verifies with the same tools a solver step would have used —
     * minus the class, the snapshot and the critic round it had no use for.
     */
    private function runDirect(RunContext $ctx): int
    {
        $this->frontend->report("Solving issue #{$ctx->issue->id} directly (no solver workflow needed).", false);
        $span = $ctx->tracer->enterWorkflow('direct');

        $loop = new DefaultTurnLoop(
            $this->agent,
            $ctx->env->executor(),
            $ctx->env->findAgentModel('worker-smart'),
            self::DIRECT_SYSTEM,
            $ctx->env->findRegistry(),
            $this->config->maxHistory,
            $ctx->tracer,
            $ctx->env->find(EnvKey::Ask) instanceof SpeakerInterface ? $ctx->env->find(EnvKey::Ask) : null,
        );

        try {
            $loop->run([Message::userText(
                "Title: {$ctx->issue->title}\n\nDescription: {$ctx->issue->description}",
            )]);
        } catch (\Cancellation $cancellation) {
            throw $cancellation;
        } catch (\Throwable $e) {
            $ctx->tracer->exit($span);
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
            $this->giveBackToProjectManager($ctx->issue, "the direct attempt failed: {$e->getMessage()}", $ctx->runId);
            $this->frontend->report("run #{$ctx->runId} failed: {$e->getMessage()}", true);

            return 1;
        }
        // The worker has stopped. Whether the ticket is SOLVED is not its call — it is the one thing in
        // the exchange with an interest in the answer. A separate pass settles it, against the project.
        $unsolved = $this->unsolvedReason($ctx);
        $ctx->tracer->exit($span);

        if ($unsolved !== null) {
            // Not a crash, so there is no error to report — the work simply is not done. Hand it back:
            // the ProjectManager escalates, or decides a person is needed, which is the right answer
            // when the ticket itself is the problem.
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
            $this->giveBackToProjectManager(
                $ctx->issue,
                "the direct attempt did not solve the ticket: {$unsolved}",
                $ctx->runId,
            );
            $this->frontend->report(
                "run #{$ctx->runId} ended without finishing issue #{$ctx->issue->id}; handed back for triage",
                true,
            );

            return 1;
        }

        $ctx->store->setRunStatus($ctx->runId, RunStatus::Done);
        $ctx->store->setIssueStatus($ctx->issue->id, IssueStatus::Done);
        $ctx->store->settleAncestors($ctx->issue->id);
        $this->frontend->report("Run #{$ctx->runId} finished for issue #{$ctx->issue->id}.", false);

        return 0;
    }

    /**
     * Is the ticket actually solved? Null when it is; otherwise what is missing, in a sentence.
     *
     * This is the `direct` path's gate, and it is a SEPARATE pass on purpose. The worker used to end
     * its own run by calling a `done` tool — a lever that let the one party with an interest in the
     * answer give it. Every false-Done this project has paid for came through it: a run that declared
     * victory on a clean `php -l`, one that closed a gibberish ticket on "can you clarify?", one that
     * closed a bug after merely reproducing it. Each fix wrapped another gate around the lever instead
     * of taking it away.
     *
     * The shape here is the one that already works elsewhere in this system — {@see Triage}: a plain
     * turn loop that decides ONE thing, outside the work, with tools to check for itself, and whose
     * answer the CODE branches on.
     *
     * Its palette is narrowed to reading — plus `bash`, because a judge that cannot RUN the tests
     * cannot judge anything, which is the whole point. That does mean the palette is not read-only
     * in the sense the word usually carries: `bash` writes as readily as it reads. Nothing mechanical
     * stops the judge editing the project, so {@see JUDGE_SYSTEM} forbids it in as many words. Said
     * plainly here because a comment claiming a restraint the code does not impose is exactly the
     * kind of paper gate this pass exists to remove.
     */
    private function unsolvedReason(RunContext $ctx): ?string
    {
        $registry = $ctx->env->findRegistry()->only(['read_file', 'list_files', 'bash']);
        $judge = new DefaultTurnLoop(
            $this->agent,
            $ctx->env->child()->set(EnvKey::Registry, $registry)->executor(),
            $ctx->env->findAgentModel('reviewer'),
            self::JUDGE_SYSTEM,
            $registry,
            $this->config->maxHistory,
            $ctx->tracer,
        );

        try {
            $answer = trim($judge->run([Message::userText(
                "The ticket:\nTitle: {$ctx->issue->title}\n\nDescription: {$ctx->issue->description}",
            )])->text ?? '');
        } catch (\Cancellation $cancellation) {
            throw $cancellation;
        } catch (\Throwable $e) {
            // A judge that could not run has not cleared anything. Unproven is unsolved: the whole point
            // is that nothing closes a ticket without a verdict, least of all the absence of one.
            return 'the completion check could not run: ' . $e->getMessage();
        }

        return self::readVerdict($answer);
    }

    /**
     * Read the judge's verdict off its FIRST LINE: null when it says solved, otherwise the reason.
     *
     * The whole line must be the word, because a prefix test is not a verdict — it used to be
     * `str_starts_with(strtoupper($answer), 'SOLVED')`, and "Solved for the happy path, but the null
     * case still fails" closed the ticket. Decoration a model reaches for anyway (backticks, bold, a
     * trailing full stop) is stripped before the comparison, because the same prompt used to print
     * the token in backticks and a judge that copied that formatting had its PASSING verdict handed
     * back to the ProjectManager as the reason the run failed.
     *
     * Anything else FAILS CLOSED. An unreadable verdict has cleared nothing, and the whole reply
     * becomes the reason so the person reading the ledger sees what was actually said.
     */
    private static function readVerdict(string $answer): ?string
    {
        if (trim($answer) === '') {
            return 'the completion check returned nothing';
        }

        $first = trim((string) strtok($answer, "\n"));
        $word = strtoupper(trim($first, " \t`*_#.:—–-"));

        if ($word === 'SOLVED') {
            return null;
        }

        // Keep the judge's own words: the detail lines are what the next attempt works from.
        return $answer;
    }

    /**
     * Hand a ticket that could not be solved back to the ProjectManager, and let it decide what
     * happens next. There are exactly two answers, and it has to pick one:
     *
     *  - a STRONGER strategy — the work still looks doable, so the ticket stays open and will be run
     *    again under a verdict that does more than the one that broke;
     *  - a PERSON — recorded with needs_human, which moves the ticket to WaitingHuman.
     *
     * No round counter is needed to stop this looping, because the store refuses any strategy that
     * does not escalate past a failed one. Once `decompose` — the top rank — has failed, there is
     * nothing left to escalate to, and the only answer the ProjectManager can still record is the
     * human one. The ladder ends itself.
     *
     * Best-effort: {@see Triage::analyse()} swallows its own failures, so a model that is down leaves
     * the ticket open and untriaged rather than taking the run report down with it.
     */
    private function giveBackToProjectManager(Issue $issue, string $reason, string $runId): void
    {
        // FIRST, stop the issue claiming to be in progress. The run set it to InProgress at the start
        // and it has just failed, so leaving it there strands the ticket: the board shows work that is
        // not happening, and nothing later moves it. This used to sit behind the early return below,
        // so a SECOND failure — where the strategy was already marked failed by the first — left the
        // ticket stuck at InProgress with no run and no way out.
        if ($this->store->loadIssue($issue->id)->status === IssueStatus::InProgress) {
            $this->store->setIssueStatus($issue->id, IssueStatus::Open);
        }

        if (!$this->store->failStrategy($issue->id, $reason)) {
            return;   // nothing was in force — the issue was never triaged, so there is nothing to escalate
        }

        new Triage($this->store, $this->config, $this->agent)->analyse($issue, $runId);
    }

    /**
     * The `decompose` path. A ticket judged too big is not run — it is split, and its parts are the
     * work. Splitting is the ProjectManager's job and has not happened here yet, so this stops and
     * says so rather than quietly falling through to generating a solver for a task the analysis
     * already judged too large for one.
     */
    private function reportDecomposition(RunContext $ctx): int
    {
        $span = $ctx->tracer->enterWorkflow('decompose');
        $children = $this->store->childIssues($ctx->issue->id);

        // Not split yet — do it now. Re-running a decomposed ticket must NOT split it again, hence
        // the check first: the sub-issues are the work, and this run only reports on them.
        $why = '';

        if ($children === []) {
            $this->frontend->report("Splitting issue #{$ctx->issue->id} into sub-issues…", false);

            try {
                $children = new Decompose($this->store, $this->config, $this->agent)->split($ctx->issue);
            } catch (\Cancellation $cancellation) {
                throw $cancellation;
            } catch (\Throwable $e) {
                // The ticket survives a failed split — but the reason travels with it. Reporting only
                // "could not be split" left the cause discoverable solely by re-running the exchange.
                $why = ': ' . $e->getMessage();
            }
        }
        $ctx->tracer->exit($span);

        if ($children === []) {
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
            $ctx->store->setIssueStatus($ctx->issue->id, IssueStatus::WaitingHuman);
            $this->frontend->report(
                "issue #{$ctx->issue->id} was judged too big to solve in one run, and could not be split{$why} — "
                . 'a person needs to say what the pieces are',
                true,
            );

            return 1;
        }

        // The parent's own run is finished — its work now lives in the children — but the TICKET is
        // not: it stays open until they all land, which settleAncestors() closes it on.
        $ctx->store->setRunStatus($ctx->runId, RunStatus::Done);
        $ctx->store->setIssueStatus($ctx->issue->id, IssueStatus::Open);
        $open = array_filter($children, static fn ($child): bool => $child->status !== IssueStatus::Done);
        $this->frontend->report(sprintf(
            'Issue #%s is split into %d sub-issues (%d still open). Run those; #%s closes when they all do.',
            $ctx->issue->id,
            \count($children),
            \count($open),
            $ctx->issue->id,
        ), false);

        return 0;
    }

    /**
     * The tokens this run may spend: the configured budget MINUS everything already spent anywhere in
     * this issue's tree.
     *
     * A decomposed ticket becomes N tickets, each of which is triaged, run, and may decompose again.
     * Giving each of them the full configured budget bounds nothing — it bounds each run while the
     * tree multiplies underneath. One pool for the whole tree is what makes the total finite: the
     * root's budget is the ceiling however the work is split.
     *
     * A root issue with no sub-issues is the common case, and there the pool is simply its own spend,
     * so this changes nothing for it beyond charging its earlier runs — which is right: a ticket
     * retried five times has spent five times.
     *
     * Returns 1 rather than 0 when the pool is exhausted, because 0 means UNLIMITED to {@see Budget}
     * and handing an over-budget run no limit at all is precisely backwards. One token is spent
     * immediately, so the run stops at its first check.
     */
    private function treeAllowance(Issue $issue): int
    {
        if ($this->config->budgetTokens <= 0) {
            return 0;   // no budget configured: unlimited, as before
        }

        $spent = $this->store->treeTokensSpent($this->store->rootIssue($issue->id));

        return max(1, $this->config->budgetTokens - $spent);
    }

    /**
     * A child scope for the AUTHORING workflows — generation and crash-repair — that mixes the
     * `define_workflow` tool into the run's registry. The solver itself runs on {@see RunContext::$env}
     * (no `define_workflow`): writing workflows is the generator's job, not the solver's, and leaving the
     * tool out of the solver's palette removes a whole class of confusion (a solver reaching for it and
     * looping on its validation). The child shadows only the registry; everything else is inherited.
     */
    private function authoringEnv(RunContext $ctx): Environment
    {
        return $ctx->env->child()->set(
            EnvKey::Registry,
            $ctx->env->findRegistry()->with(ToolFactory::defineWorkflow($ctx->workflowStore)),
        );
    }

    /**
     * Make sure a solver workflow exists for the run: reuse the one on disk, or generate one and have
     * the front-end decide whether to run it. Returns null to proceed to running, or an exit code to stop
     * here — a failed generation (1), or the solver saved without being run yet (0).
     */
    private function ensureSolver(RunContext $ctx): ?int
    {
        $solverPath = $ctx->workflowStore->path($ctx->solverName, true);

        if (is_file($solverPath)) {
            $this->frontend->report("Reusing solver {$ctx->solverClass}.", false);

            return null;
        }

        $this->frontend->report("Generating a solver workflow for issue #{$ctx->issue->id}…", false);

        $gen = $ctx->tracer->enterWorkflow('generate-issue-workflow');

        try {
            new GenerateIssueWorkflow($this->authoringEnv($ctx), $ctx->runId . '-gen', [
                'solverName' => $ctx->solverName,
                'solverNamespace' => $ctx->workflowStore->namespaceFor(true),
                'solverTools' => ['read_file', 'write_file', 'list_files', 'bash'],
                // Empty unless the verdict was `approach`, in which case this is the written strategy the
                // ProjectManager chose off the shelf — the domain half of what the generator is told,
                // sitting beside the general one it always carries.
                'recipe' => $this->chosenRecipe($ctx->issue),
            ], $ctx->issue, $ctx->project)->run();
        } catch (\Cancellation $cancellation) {
            throw $cancellation;   // a cancelled run must stop, not be reported as a generation failure
        } catch (\Throwable $e) {
            $ctx->tracer->exit($gen);
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
            $this->giveBackToProjectManager($ctx->issue, 'generation failed: ' . $e->getMessage(), $ctx->runId);
            $this->frontend->report('generation failed: ' . $e->getMessage(), true);

            return 1;
        }
        $ctx->tracer->exit($gen);

        if (!is_file($solverPath)) {
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
            $this->giveBackToProjectManager($ctx->issue, 'no solver workflow was produced', $ctx->runId);
            $this->frontend->report('no solver workflow was produced', true);

            return 1;
        }

        if (!$this->frontend->approveSolver($solverPath, (string) file_get_contents($solverPath))) {
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Generated);
            // Waiting on a person to review the generated solver — not in progress, and saying so is
            // what puts the ticket in the column where someone will actually look at it.
            $ctx->store->setIssueStatus($ctx->issue->id, IssueStatus::WaitingHuman);
            $this->frontend->report("Saved. Not run — review it, then run issue #{$ctx->issue->id} again.", false);

            return 0;
        }

        return null;
    }

    /**
     * Run the solver to completion; on a runtime crash, ask the supervisor to repair it (a new class
     * version) and resume the same runId — its snapshot skips the finished steps. Bounded by MAX_REPAIRS.
     *
     * $repairable is false for a workflow a PERSON wrote — see {@see repairSolver()} for why rewriting
     * one is never the answer.
     */
    private function runSolver(RunContext $ctx, bool $repairable = true): int
    {
        $solverSpan = $ctx->tracer->enterWorkflow($ctx->solverName);
        $currentClass = $ctx->solverClass;
        $attempt = 0;

        while (true) {
            try {
                $solver = new $currentClass($ctx->env, $ctx->runId, [], $ctx->issue, $ctx->project);

                if (!$solver instanceof WorkflowAbstract) {
                    throw new ClawException("{$currentClass} is not a workflow");
                }
                $solver->run();

                break;
            } catch (\Cancellation $cancellation) {
                throw $cancellation;   // a cancelled run must stop — never "repair" a cancellation
            } catch (\Throwable $e) {
                // Repair answers ONE question: is this workflow's code broken? Three kinds of failure
                // arrive here and only one of them is that.
                //
                // An AgentException is the model backend refusing or failing — a malformed request, a
                // rate limit, an outage, a spent quota. The code is fine; rewriting it spends the
                // supervisor's tokens inventing a replacement for a class that was never at fault, and
                // then runs that invention. Measured: a 400 from a malformed history had the supervisor
                // rewrite a workflow whose source it could not even read.
                //
                // A DELIBERATE stop is the run doing what it was told: the budget ran out, the supervisor
                // said `stop`, a step exhausted its review rounds with nobody to escalate to. The code is
                // not merely innocent here, it is being repaired AGAINST a decision that was taken on
                // purpose — the supervisor was sent to rewrite the class it had itself just stopped, and
                // the cheapest way to satisfy "fix the cause of: run stopped by the supervisor" is to
                // delete the critic that stopped it. A budget stop was worse still: the repair's own
                // model call hit the same spent budget, so the ticket came back reading "the solver
                // crashed and could not be repaired: run stopped: budget spent".
                //
                // Everything else — TypeError, ParseError, a step calling a method that is not there —
                // really can mean the generated code is broken, which is what repair is for.
                if (!$repairable || $e instanceof AgentException || ($e instanceof WorkflowException && $e->deliberate)) {
                    $ctx->tracer->exit($solverSpan);
                    $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
                    $this->giveBackToProjectManager($ctx->issue, "run #{$ctx->runId} failed: {$e->getMessage()}", $ctx->runId);
                    $this->frontend->report("Run #{$ctx->runId} failed: {$e->getMessage()}", true);

                    return 1;
                }

                // a generated solver throws Error (TypeError, ParseError, ...) as readily as Exception,
                // so catch the lot here and repair-and-resume — that is exactly this boundary's job
                if (++$attempt > self::MAX_REPAIRS) {
                    $ctx->tracer->exit($solverSpan);
                    $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
                    $message = "run #{$ctx->runId} failed after {$attempt} repair attempt(s): {$e->getMessage()}";
                    $this->giveBackToProjectManager($ctx->issue, $message, $ctx->runId);
                    $this->frontend->report($message, true);

                    return 1;
                }

                $this->frontend->report("Run hit an error; repairing (attempt {$attempt})…", false);
                $fixed = $this->repairSolver($ctx, $currentClass, $e->getMessage(), $attempt);

                if ($fixed === null) {
                    $ctx->tracer->exit($solverSpan);
                    $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
                    $this->giveBackToProjectManager($ctx->issue, "the solver crashed and could not be repaired: {$e->getMessage()}", $ctx->runId);
                    $this->frontend->report("the supervisor could not repair run #{$ctx->runId}", true);

                    return 1;
                }
                $currentClass = $fixed;   // resume with the fixed class on the next loop turn
            }
        }
        $ctx->tracer->exit($solverSpan);

        $ctx->store->setRunStatus($ctx->runId, RunStatus::Done);
        $ctx->store->setIssueStatus($ctx->issue->id, IssueStatus::Done);   // every step ran -> issue resolved
        $this->frontend->report("Run #{$ctx->runId} finished for issue #{$ctx->issue->id}.", false);

        return 0;
    }

    /**
     * The supervisor tier of the ask channel: an agent on the `supervisor` model that settles in-run
     * escalations (accept / stop / guidance) on its own judgement, so a stuck step does not wait on —
     * or churn against — the human. Replying `ESCALATE` returns null, so {@see EscalatingSpeaker}
     * passes the decision up to the human tier.
     *
     * It CAN LOOK. It used to be built with four arguments, which — back when the turn loop defaulted
     * its palette to none — left it judging "does the actual work look correct" with no way to open a
     * file or run a test, on nothing but the escalation text: the critic's complaint, and artifacts the
     * step under review wrote about itself. Its standing order biased it towards `accept`. That is the
     * `done` rubber stamp this project spent a year removing, rebuilt one tier up.
     *
     * Everything except writing. A supervisor reads and runs to check a claim; it never does the work
     * itself, and a tier that could edit the project would be settling escalations by fixing them. By
     * SUBTRACTION rather than an allow-list, so a capability added to runs later reaches it too — an
     * allow-list has to be revisited every time, and nothing says when it was not.
     */
    private function supervisorSpeaker(Environment $env): SpeakerInterface
    {
        $palette = $env->findRegistry()->except(['write_file']);
        $loop = new DefaultTurnLoop(
            $this->agent,
            $env->child()->set(EnvKey::Registry, $palette)->executor(),
            $env->findAgentModel('supervisor'),
            self::SUPERVISOR_SYSTEM,
            $palette,
        );
        $supervisor = new AgentSpeaker(SpeakerRole::Supervisor, $loop);

        return new class ($supervisor) implements SpeakerInterface {
            public function __construct(private readonly AgentSpeaker $supervisor)
            {
            }

            public function name(): SpeakerRole
            {
                return SpeakerRole::Supervisor;
            }

            public function reply(string $incoming): ?string
            {
                $answer = trim($this->supervisor->reply($incoming));

                // ESCALATE (or an empty answer) -> pass up to the next tier (the human). Matched as the
                // WHOLE reply, not a substring: `str_contains(…, 'ESCALATE')` sent the guidance "no need
                // to escalate, add the missing import" to a person instead of back to the step, which is
                // the reverse of what it says. Anything with more in it is an answer, and answers go back.
                $word = strtoupper(trim($answer, " \t`*_.!—–-"));

                return $answer === '' || $word === 'ESCALATE' ? null : $answer;
            }
        };
    }

    /**
     * Repair a crashed solver: read its source, hand it and the error to {@see SuperviseWorkflow}
     * (the supervisor role), which writes a corrected version under a new class name. Returns that
     * fully-qualified class name, or null if the repair produced nothing.
     *
     * Only ever for a GENERATED solver, and the source it reads says why: it comes from the project's
     * generated-workflow store. A workflow from the library is not there — it lives on a shelf, was
     * written and reviewed by a person, and is shared by every ticket that picks it. Rewriting one
     * because a single run crashed would leave the original in place for the next ticket while this
     * one quietly finished on a model's rewrite of it, and the defect nobody was told about would
     * still be on the shelf. A library workflow that crashes is a defect in a shared asset: it goes
     * back with the error, to a person.
     */
    private function repairSolver(RunContext $ctx, string $brokenClass, string $error, int $attempt): ?string
    {
        $fixedName = $ctx->solverName . 'R' . $attempt;
        $fixedClass = $ctx->workflowStore->classFor($fixedName, true);
        $fixedNamespace = $ctx->workflowStore->namespaceFor(true);

        $brokenShort = WorkflowStore::shortName($brokenClass);
        $brokenPath = $ctx->workflowStore->path($brokenShort, true);
        $brokenCode = is_file($brokenPath) ? (string) file_get_contents($brokenPath) : '';

        $span = $ctx->tracer->enterWorkflow('supervise-run');

        try {
            new SuperviseWorkflow($this->authoringEnv($ctx), $ctx->runId . '-fix' . $attempt, [
                'brokenName' => $brokenShort,
                'brokenCode' => $brokenCode,
                'error' => $error,
                'fixedName' => $fixedName,
                'fixedNamespace' => $fixedNamespace,
            ], $ctx->issue, $ctx->project)->run();
        } catch (\Cancellation $cancellation) {
            throw $cancellation;
        } catch (\Throwable $e) {
            $ctx->tracer->exit($span);
            $this->frontend->report('repair failed: ' . $e->getMessage(), true);

            return null;
        }
        $ctx->tracer->exit($span);

        return is_file($ctx->workflowStore->path($fixedName, true)) ? $fixedClass : null;
    }
}
