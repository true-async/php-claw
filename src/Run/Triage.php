<?php

declare(strict_types=1);

namespace Claw\Run;

use Claw\Agent\AgentInterface;
use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\Message;
use Claw\Config;
use Claw\Exceptions\ClawException;
use Claw\Project\Issue;
use Claw\Project\IssueStatus;
use Claw\Project\IssueType;
use Claw\Project\ProjectStoreInterface;
use Claw\Project\Strategy;
use Claw\Project\StrategyOutcome;
use Claw\Tool\ListWorkflowsTool;
use Claw\Tool\ProjectManagerTool;
use Claw\Tool\RecallTool;
use Claw\Tool\Registry;
use Claw\Trace\Tracer;
use Claw\Trace\TraceReader;
use Claw\Trace\TraceStore;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;
use Claw\Workflow\WorkflowStore;

/**
 * The ProjectManager's analysis pass: read a freshly opened ticket and decide HOW it should be
 * solved, before anything is run.
 *
 * This is the SECOND of two stages, and the split matters. Creating a ticket writes it to the
 * ledger and nothing else — it is instant, it cannot fail on a model call, and the ticket the
 * person typed exists whatever happens next. Only then does this run, and when it reaches a
 * verdict it CHANGES THE TICKET'S STATE, which the board is already streaming: the decision
 * arrives on screen as something the person watches happen, rather than a property that was
 * quietly there from the start.
 *
 * Deliberately not a workflow. It is one exchange with one tool available and one thing to
 * decide; a workflow would buy step snapshots, handoffs and a critic that this has no use for,
 * and would put a run row in the ledger for something that is not a run.
 */
final readonly class Triage
{
    /**
     * What the ProjectManager is and what it must produce. The rule it exists to enforce is the
     * last line: the verdict is only real once it is recorded through the tool, because prose is
     * not something the run pipeline can branch on. An earlier version of this system already
     * had a step that rated tasks in prose — the rating was ignored twice over.
     */
    private const string SYSTEM = <<<'PROMPT'
        You are the PROJECT MANAGER of an autonomous coding system. A ticket has just been opened.
        Your ONLY job is to decide HOW it should be solved — not to solve it, not to write code,
        not to plan the implementation.

        FIRST, decide whether the ticket is WORKABLE AT ALL, against this project. This is the more
        important half of your job, and the one that is easy to skip.

        A ticket is NOT workable when it does not say what to do: nonsense or random characters, a
        bare word with no request in it, a description that contradicts itself, or a request about
        something this project plainly does not contain. Read the project brief and look at the
        files before you conclude either way — a term that means nothing to you may be perfectly
        ordinary here.

        If it is not workable, do NOT pick a strategy in the hope that a worker will figure it out.
        Use the `project_manager` tool with the action `needs_human`, passing the issue id and a
        reason saying what is missing, and stop there. That is a real answer, not a failure — it is
        the only useful one for a ticket nobody can act on. An unworkable ticket handed to a worker
        burns money inventing a task: one reading just "ку" had a solver write tests for code nobody
        asked for and run them five times.

        If it IS workable, say first WHAT KIND of work it is — exactly one type:

        - `bug`      — something is broken and should not be. The work has a fixed shape: reproduce it,
                       pin it with a failing test, fix it, watch the test go green.
        - `feature`  — new behaviour someone asked for.
        - `refactor` — change the shape of the code without changing what it does. There are no
                       acceptance criteria; the existing tests passing IS the criterion.
        - `design`   — decide how something should be built. Produces a document and a decision, and
                       commits no code.
        - `research` — find something out and report it. Changes nothing.
        - `chore`    — mechanical upkeep with a rigid procedure: a dependency bump, a codemod, a rename
                       across the project, documentation, test coverage for code that already works.

        Judge the type from what the ticket ASKS FOR, not from how big it is — a bug is a bug whether
        it takes one line or ten files.

        THEN judge the SIZE and SHAPE of the work and choose exactly one strategy:

        - `direct`    — one agent with the project's tools can just do it. A localized, mechanical
                        change: a typo, a flag, a small method, a config value. This is the cheapest
                        path and should be your default for anything small.
        - `library`   — one of the ready-made, tested workflows listed in the ticket brief fits this
                        task as it stands. Prefer it over `generate` whenever one genuinely fits: it
                        was written and reviewed by a person, so it is both cheaper and more reliable
                        than writing a new solver. Read it in full with `list_workflows` first, and
                        name it when you record the verdict.
        - `generate`  — nothing off the shelf fits, so a solver workflow must be written for it.
                        Right for real implementation work with several concerns to carry.
        - `decompose` — genuinely too big for one run, and splitting it produces parts that can be
                        worked on separately. The most expensive verdict: it creates more tickets,
                        each of which is triaged in turn and spends its own budget. Choose it only
                        when the task truly cannot be done in one pass, never because splitting
                        feels tidy.

        Also decide whether a PERSON must approve before the work runs. Say yes when the decision is
        expensive or hard to undo — a decomposition almost always is — and no for small, obvious work.

        Then record the verdict by CALLING the `project_manager` tool with the action `set_strategy`,
        passing the issue id, the type and the strategy you chose, your reason, and whether a person
        must approve.

        HOW YOU WORK — the rules, plainly:

        1. You act ONLY by calling the tools listed below. Reading files, and recording your verdict,
           are both tool calls. You have no other way to affect anything.
        2. Your verdict is real only once the `project_manager` call RETURNS. Describing the call,
           printing it as JSON or in a code block, or explaining what you would call — none of that
           counts. It is text, and text changes nothing: the ticket stays as it was and the judging
           you just did is thrown away.
        3. Every run ends with exactly ONE `project_manager` call: `set_strategy` for a workable
           ticket, `needs_human` for one that is not. Never both, never neither.
        4. Look before you judge. `list_files` and `read_file` are there for that, and a ticket that
           reads as nonsense to you may be ordinary in this project.
        5. Say nothing else. No preamble, no summary after the call.
        PROMPT;

    public function __construct(
        private ProjectStoreInterface $store,
        private Config $config,
        private AgentInterface $agent,
    ) {
    }

    /**
     * Analyse an issue and record its strategy. Returns the strategy that ended up in force, or
     * null when none was recorded — the model declined to call the tool, or the call was refused.
     *
     * Failure is soft ON PURPOSE. Triage runs behind the ticket's own creation, so a model that is
     * down, out of quota or simply unhelpful must not take the ticket with it: an untriaged issue
     * is a usable issue, it just has no verdict yet and can be triaged again.
     */
    public function analyse(Issue $issue, ?string $failedRunId = null): ?Strategy
    {
        // Both tools are handed the SAME shelves: the one that shows what is available and the one that
        // records the choice. Any gap between those two lists is a verdict that lists fine and runs wrong.
        $project = $this->store->project();
        $libraries = WorkflowStore::libraries($this->config->library, $project->path, $project->id);

        $registry = new Registry();
        $registry->add(new ProjectManagerTool($this->store, $libraries));
        $registry->add(new ListWorkflowsTool($libraries));

        foreach ($this->readOnlyTools() as $tool) {
            $registry->add($tool);
        }

        // On a re-triage, let it open the run that failed. The ledger only carries a one-line reason —
        // usually an exception message — which is enough for "the solver crashed" and far too thin for
        // "the tests are red on the substance". The journal holds what actually happened, and this is
        // the door to it: read-only, scoped to that one run, so it can look without acting.
        if ($failedRunId !== null) {
            $registry->add(new RecallTool(
                new TraceReader($this->store->pdo()),
                $failedRunId,
                "Title: {$issue->title}\n\nDescription: {$issue->description}",
            ));
        }

        $env = new Environment()
            ->set(EnvKey::Worker, $this->agent)
            ->set(EnvKey::Registry, $registry)
            ->set(EnvKey::ModelId, $this->config->model)
            ->set(EnvKey::Agents, $this->config->agents);

        // Triage is recorded like anything else that spends money and decides something. It is not a
        // run, so it has no run row and no ledger id — it is keyed by the ticket instead, and read back
        // with `claw log triage-<issue>`. Without this the most consequential decision in the system was
        // the one thing nobody could look at: which tools the ProjectManager reached for, whether it
        // opened the workflow library at all, and what it read before choosing.
        $tracer = new Tracer(self::traceId($issue), new TraceStore($this->store->pdo()));
        $env->set(EnvKey::Tracer, $tracer);
        $span = $tracer->enterWorkflow('triage');

        // The tools are named in the prompt as well as advertised through the API. A model handed
        // only a schema reaches for them inconsistently — this one judged a ticket correctly and then
        // PRINTED the recording call as a JSON block instead of making it, leaving the ticket
        // untouched. Naming them up front is what fixed the same problem for workflow steps.
        $loop = new DefaultTurnLoop(
            $this->agent,
            $env->executor(),
            $env->findAgentModel('project-manager'),
            self::SYSTEM . $registry->briefing('The tools you have — these are your only way to act'),
            $registry,
            tracer: $tracer,
        );

        try {
            $result = $loop->run([Message::userText($this->brief($issue, $libraries, $failedRunId !== null))]);

            // A model that judged the ticket correctly but WROTE OUT the call instead of making it
            // leaves the ticket untouched and its reasoning wasted — seen in practice, printing the
            // call as a JSON block. Say so plainly once and let it correct itself; the analysis is
            // already in the history, so this costs a short exchange rather than a fresh one.
            if (!$this->settled($issue)) {
                $loop->run([...$result->history, Message::userText(
                    'You answered in text and did not call the tool, so nothing was recorded and the '
                    . 'ticket is unchanged. Make the actual `project_manager` tool call now — writing '
                    . 'it out as JSON or code does not count.',
                )]);
            }
        } catch (\Cancellation $cancellation) {
            throw $cancellation;
        } catch (\Throwable) {
            return null;   // see the docblock: a ticket outlives a failed analysis
        } finally {
            $tracer->exit($span);   // a failed analysis is worth reading too — often more so
        }

        return $this->store->currentStrategy($issue->id)['strategy'] ?? null;
    }

    /**
     * Where an issue's triage is recorded. Not a run id: triage deliberately puts no row in the runs
     * ledger, so its trace is keyed by the ticket it judged and read with `claw log triage-<issue>`.
     * A re-triage overwrites nothing — the records simply accumulate under the same key, oldest first,
     * which is what you want when asking why a ticket was routed differently the second time.
     */
    public static function traceId(Issue $issue): string
    {
        return 'triage-' . $issue->id;
    }

    /**
     * Did the analysis actually change anything? Either verdict counts: a recorded strategy, or the
     * ticket parked for a person. Anything else means the exchange ended with the ticket untouched.
     */
    private function settled(Issue $issue): bool
    {
        if ($this->store->currentStrategy($issue->id) !== null) {
            return true;
        }

        return $this->store->loadIssue($issue->id)->status === IssueStatus::WaitingHuman;
    }

    /**
     * The ticket as the ProjectManager sees it, with the id it must quote back to record a verdict.
     *
     * @param list<WorkflowStore> $libraries
     * @param bool                $canRecall whether `recall` is on the palette — see {@see history()}
     */
    private function brief(Issue $issue, array $libraries, bool $canRecall): string
    {
        $project = $this->store->project();
        $description = trim($issue->description) === ''
            ? '(no description was given — judge from the title alone)'
            : $issue->description;

        // The project's own brief. Without it "is this ticket workable" is judged against nothing,
        // and a term that is ordinary in this project reads as nonsense — or the reverse.
        $about = trim($project->description) === ''
            ? "(this project has no description — judge from its files)\n\n"
            : "What this project is:\n{$project->description}\n\n";

        return "Project: {$project->name} ({$project->path})\n\n"
            . $about
            . "Ticket #{$issue->id}\nTitle: {$issue->title}\n\nDescription:\n{$description}\n\n"
            . $this->shelf($libraries)
            . $this->history($issue, $canRecall)
            // The closing instruction used to read "Decide the strategy and record it", which presupposed
            // the answer to the FIRST question the system prompt asks — is this workable at all — in the
            // most recent position on the page, after the brief had pushed that prompt far behind. A
            // borderline ticket was being nudged towards a strategy by the last thing the model read.
            . 'Both verdicts are real answers. Decide first whether this ticket is workable, then record '
            . "exactly one, quoting issue id {$issue->id}: `set_strategy` with the type and strategy if "
            . 'it is, `needs_human` if it is not.';
    }

    /**
     * The ready-made workflows, SHOWN rather than offered behind a tool call.
     *
     * Measured, on a traced triage: `list_workflows` was never called once. The reason is circular —
     * the prompt says to call it before choosing `library`, but a model with no reason to think
     * anything is on the shelf never chooses `library`, so it never calls the tool, so it never learns
     * what is on the shelf. A capability reachable only by asking for it is a capability nobody asks
     * for. The shelf is small and this costs a few hundred tokens; `list_workflows` remains for the
     * full description of one that looks promising.
     *
     * @param list<WorkflowStore> $libraries
     */
    private function shelf(array $libraries): string
    {
        $lines = [];

        try {
            foreach ($libraries as $library) {
                foreach ($library->catalogue() as $entry) {
                    $serves = implode(', ', array_map(static fn (IssueType $t): string => $t->value, $entry['serves']));
                    $lines[] = "- {$entry['name']} (for: {$serves}) — " . self::firstLine($entry['description']);
                }
            }
        } catch (ClawException $e) {
            // A broken shelf must not take the triage with it: the ticket still needs a verdict, and
            // every other strategy is still available. Say what is wrong instead of showing nothing.
            return "Ready-made workflows: the library could not be read ({$e->getMessage()}), so the "
                . "`library` strategy is not available for this ticket.\n\n";
        }

        if ($lines === []) {
            return 'Ready-made workflows: none exist yet, so `library` is not available — this ticket '
                . "must be solved another way.\n\n";
        }

        return 'READY-MADE WORKFLOWS already written and tested, which `library` would run as they '
            . "stand.\nUse `list_workflows` to read one in full before choosing it:\n\n"
            . implode("\n", $lines) . "\n\n";
    }

    /** A catalogue entry's opening sentence — enough to judge whether it is worth reading in full. */
    private static function firstLine(string $description): string
    {
        $first = strtok(trim($description), "\n");

        return $first === false ? '' : trim($first);
    }

    /**
     * What has already been tried on this ticket and how it ended — empty on a first triage.
     *
     * A retry chooses from the failures, so it has to see them: without this the ProjectManager would
     * pick the same strategy that just broke, and be refused by the store for not escalating, having
     * spent a model call to learn what it could have been told.
     *
     * $canRecall is not decoration. {@see RecallTool} is registered only when {@see analyse()} was given
     * the failed run's id, and two of the three doors do not pass one — `Server.php` and the CLI both
     * call `analyse($issue)`. This paragraph fires on any failed attempt, so it used to instruct a
     * near-mandatory call ("and it usually does") to a tool that was not on the palette: at best a
     * wasted exchange, at worst a model reading the unknown-tool error as evidence the run is beyond
     * saving and parking a ticket on that.
     */
    private function history(Issue $issue, bool $canRecall): string
    {
        $failed = array_filter(
            $this->store->strategyAttempts($issue->id),
            static fn (array $attempt): bool => $attempt['outcome'] === StrategyOutcome::Failed,
        );

        if ($failed === []) {
            return '';
        }

        $lines = array_map(
            static fn (array $attempt): string => "- `{$attempt['strategy']->value}` was tried and failed: {$attempt['outcomeReason']}",
            $failed,
        );

        $recall = $canRecall
            ? 'Those reasons are one line each and rarely decide anything on their own. The failed run '
                . 'itself is open to you with `recall` — read it before choosing when the one line is not '
                . "enough to tell a solvable failure from a hopeless one.\n\n"
            : '';

        return "This ticket has been attempted before:\n" . implode("\n", $lines) . "\n\n"
            . $recall
            . 'A strategy that already failed cannot be chosen again, and neither can a cheaper one — the '
            . "store will refuse it. Your next verdict must do MORE than what broke.\n\n"
            // NOT "set needs_human=true". That is the flag on `set_strategy` meaning "a person approves
            // first", and on this path every `set_strategy` is refused for not escalating — so the one
            // instruction the model could follow literally was the one guaranteed to fail.
            . 'If there is nothing left to escalate to, or the failures show a person must decide '
            . 'something first, call `project_manager` with the ACTION `needs_human`. That hands the '
            . 'ticket to a human, which is a real answer and better than a verdict that will fail the '
            . "same way.\n\n";
    }

    /**
     * The palette for the analysis: reading only. The ProjectManager sizes up work, it does not do
     * it, and handing it `bash` or `write_file` invites a model to start solving the ticket it was
     * asked to classify — which is exactly how a read-only planning step in this system once
     * narrated an implementation it had never performed.
     *
     * @return list<\Claw\Tool\ToolInterface>
     */
    private function readOnlyTools(): array
    {
        try {
            $workspace = new \Claw\Tool\Workspace($this->store->project()->path);
        } catch (ClawException) {
            return [];   // an unreadable project folder is not a reason to skip the decision
        }

        return [
            new \Claw\Tool\ReadFileTool($workspace),
            new \Claw\Tool\ListFilesTool($workspace),
        ];
    }
}
