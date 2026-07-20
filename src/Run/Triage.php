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
use Claw\Project\ProjectStoreInterface;
use Claw\Project\Strategy;
use Claw\Project\StrategyOutcome;
use Claw\Tool\ProjectManagerTool;
use Claw\Tool\RecallTool;
use Claw\Tool\Registry;
use Claw\Trace\TraceReader;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;

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

        If it IS workable, judge the SIZE and SHAPE of the work and choose exactly one strategy:

        - `direct`    — one agent with the project's tools can just do it. A localized, mechanical
                        change: a typo, a flag, a small method, a config value. This is the cheapest
                        path and should be your default for anything small.
        - `library`   — a ready-made, tested workflow fits this task as it stands.
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
        passing the issue id, the strategy you chose, your reason, and whether a person must approve.

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
        $registry = new Registry();
        $registry->add(new ProjectManagerTool($this->store));

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

        // The tools are named in the prompt as well as advertised through the API. A model handed
        // only a schema reaches for them inconsistently — this one judged a ticket correctly and then
        // PRINTED the recording call as a JSON block instead of making it, leaving the ticket
        // untouched. Naming them up front is what fixed the same problem for workflow steps.
        $loop = new DefaultTurnLoop(
            $this->agent,
            $env->executor(),
            $env->findAgentModel('project-manager'),
            self::SYSTEM . $registry->briefing('The tools you have — these are your only way to act'),
            $registry->specs(),
        );

        try {
            $result = $loop->run([Message::userText($this->brief($issue))]);

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
        }

        return $this->store->currentStrategy($issue->id)['strategy'] ?? null;
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

    /** The ticket as the ProjectManager sees it, with the id it must quote back to record a verdict. */
    private function brief(Issue $issue): string
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
            . $this->history($issue)
            . "Decide the strategy and record it with project_manager(action='set_strategy', issue='{$issue->id}', …).";
    }

    /**
     * What has already been tried on this ticket and how it ended — empty on a first triage.
     *
     * A retry chooses from the failures, so it has to see them: without this the ProjectManager would
     * pick the same strategy that just broke, and be refused by the store for not escalating, having
     * spent a model call to learn what it could have been told.
     */
    private function history(Issue $issue): string
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

        return "This ticket has been attempted before:\n" . implode("\n", $lines) . "\n\n"
            . 'Those reasons are one line each. If the choice turns on WHY it failed — and it usually does '
            . '— open the run itself with `recall`: `what=\'workflow\'` maps the steps, `what=\'step\'` with '
            . "a name returns that step's history, `what='artifacts'` its outputs, `what='tool'` a tool's "
            . 'calls. Do that before deciding when the one-line reason is not enough to tell a solvable '
            . "failure from a hopeless one.\n\n"
            . 'A strategy that already failed cannot be chosen again, and neither can a cheaper one — the '
            . "store will refuse it. Your next verdict must do MORE than what broke.\n\n"
            . 'If there is nothing left to escalate to, or the failures show this needs a person to '
            . 'decide something, set needs_human=true: that hands the ticket to a human, which is a real '
            . "answer and better than a verdict that will fail the same way.\n\n";
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
