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
use Claw\Exceptions\WorkflowFinished;
use Claw\Project\Issue;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStoreInterface;
use Claw\Project\RunStatus;
use Claw\Project\Strategy;
use Claw\Tool\RecallTool;
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

        When the change exists and you have SEEN it work, call `done` with a short summary of what you
        changed and what you ran to verify it.

        If you CANNOT finish — the ticket is unclear, it asks for something that is not there, or it
        turns out to be far bigger than it looked — do NOT just answer in prose. Use the `[question]`
        protocol described above: it reaches a person, and your reply comes back so you can carry on.
        Prose with no tool call and no marker ends the attempt with nothing done, and the ticket goes
        back for re-triage.
        PROMPT;

    /** The supervisor agent's standing role — it settles in-run escalations or defers to the human. */
    private const string SUPERVISOR_SYSTEM = <<<'PROMPT'
        You are the SUPERVISOR of an autonomous coding workflow. You are consulted when a step is stuck:
        a worker pauses with a question, or a step's work failed review and the run asks whether to keep
        going. Your job is to UNBLOCK with the smallest sound decision, so the run does not churn.

        How to answer (reply with ONLY the decision, no preamble):
        - To resolve a "did not pass review / is this OK?" escalation, reply with exactly one of:
          `accept` — the work is good enough as is, stop reworking;
          `stop`   — the goal cannot be reached here (e.g. a required tool is missing, the gate is
                     unsatisfiable in this environment) or it is looping with no progress — abort the step;
          or a short, concrete GUIDANCE for ONE more attempt (only if a specific fix is likely to work).
        - To answer a worker's question, give the briefest concrete answer that lets it proceed.

        Bias to ending churn: if a step has failed several times for the same reason, or the blocker is
        environmental (a missing test runner, an absent dependency) and cannot change, choose `accept`
        (if the actual work looks correct) or `stop` — do NOT keep saying "try again".

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
        $registry = ToolFactory::forRun($project, $workspace);

        // The store is durable (a killed run resumes from its snapshot); budgets cap the run total and
        // each exchange (0 = unlimited); named agent roles share the access and override only the model.
        $env = new Environment()
            ->set(EnvKey::Worker, $this->agent)
            ->set(EnvKey::Registry, $registry)
            ->set(EnvKey::ModelId, $this->config->model)
            ->set(EnvKey::SystemPrompt, Config::DEFAULT_SYSTEM)
            ->set(EnvKey::MaxHistory, $this->config->maxHistory)
            ->set(EnvKey::Store, new SqliteStateStore($projectDb))
            ->set(EnvKey::Agents, $this->config->agents)
            ->set(EnvKey::Budget, new Budget($this->treeAllowance($issue), (float) $this->config->budgetSeconds))
            ->set(EnvKey::TurnTokenLimit, $this->config->turnTokens)
            ->set(EnvKey::TurnTimeLimit, (float) $this->config->turnSeconds)
            ->set(EnvKey::BudgetPolicy, BudgetPolicy::from($this->config->budgetPolicy));

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
            $ctx->env->findRegistry()->specs(),
            $this->config->maxHistory,
            $ctx->tracer,
            $ctx->env->find(EnvKey::Ask) instanceof SpeakerInterface ? $ctx->env->find(EnvKey::Ask) : null,
        );

        // `done` is the ONLY thing that finishes this path. The turn loop returns normally whenever
        // the model stops without calling a tool — which is what it does when it has a question, or
        // gives up, or simply wanders off — and treating that as success closed a ticket whose
        // description was gibberish, on the strength of the model replying "can you clarify?".
        $declaredDone = false;

        try {
            $loop->run([Message::userText(
                "Title: {$ctx->issue->title}\n\nDescription: {$ctx->issue->description}",
            )]);
        } catch (WorkflowFinished) {
            $declaredDone = true;
        } catch (\Cancellation $cancellation) {
            throw $cancellation;
        } catch (\Throwable $e) {
            $ctx->tracer->exit($span);
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
            $this->giveBackToProjectManager($ctx->issue, "the direct attempt failed: {$e->getMessage()}", $ctx->runId);
            $this->frontend->report("run #{$ctx->runId} failed: {$e->getMessage()}", true);

            return 1;
        }
        $ctx->tracer->exit($span);

        if (!$declaredDone) {
            // Not a crash, so there is no error to report — the model simply never claimed the work
            // was finished. Hand it back: the ProjectManager escalates, or decides a person is needed,
            // which is the right answer when the ticket itself is the problem.
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
            $this->giveBackToProjectManager(
                $ctx->issue,
                'the direct attempt ended without declaring the task done — the worker stopped without '
                . 'calling `done`, usually because it needed something the ticket does not say',
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
            } catch (WorkflowFinished) {
                break;   // the solver called `done`: a clean finish, not a crash to repair
            } catch (\Cancellation $cancellation) {
                throw $cancellation;   // a cancelled run must stop — never "repair" a cancellation
            } catch (\Throwable $e) {
                // Repair answers ONE question: is this workflow's code broken? Two kinds of failure
                // arrive here and only one of them is that.
                //
                // An AgentException is the model backend refusing or failing — a malformed request, a
                // rate limit, an outage, a spent quota. The code is fine; rewriting it spends the
                // supervisor's tokens inventing a replacement for a class that was never at fault, and
                // then runs that invention. Measured: a 400 from a malformed history had the supervisor
                // rewrite a workflow whose source it could not even read.
                //
                // Everything else — TypeError, ParseError, a step calling a method that is not there —
                // really can mean the generated code is broken, which is what repair is for.
                if (!$repairable || $e instanceof AgentException) {
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
     * or churn against — the human. It runs tool-less (it judges from the escalation text). Replying
     * `ESCALATE` returns null, so {@see EscalatingSpeaker} passes the decision up to the human tier.
     */
    private function supervisorSpeaker(Environment $env): SpeakerInterface
    {
        $loop = new DefaultTurnLoop($this->agent, $env->executor(), $env->findAgentModel('supervisor'), self::SUPERVISOR_SYSTEM);
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

                // ESCALATE (or an empty answer) -> pass up to the next tier (the human).
                return $answer === '' || str_contains(strtoupper($answer), 'ESCALATE') ? null : $answer;
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
