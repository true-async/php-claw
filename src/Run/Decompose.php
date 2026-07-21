<?php

declare(strict_types=1);

namespace Claw\Run;

use Claw\Agent\AgentInterface;
use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\Message;
use Claw\Config;
use Claw\Exceptions\ClawException;
use Claw\Project\Issue;
use Claw\Project\ProjectStore;
use Claw\Project\ProjectStoreInterface;
use Claw\Tool\ListFilesTool;
use Claw\Tool\ProjectManagerTool;
use Claw\Tool\ReadFileTool;
use Claw\Tool\Registry;
use Claw\Tool\Workspace;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;

/**
 * Carry out a `decompose` verdict: split a ticket judged too big into sub-issues.
 *
 * The counterpart to {@see Triage}, and deliberately its own pass rather than part of it. Triage
 * answers ONE question — how should this be solved — and answers it for every ticket; splitting is
 * work that only a `decompose` verdict calls for, needs a different tool (`create_issue`), and can
 * be re-run on its own if it produced nothing. Folding it in would make the common case pay for the
 * rare one and give a single exchange two jobs.
 *
 * Each sub-issue it opens is a ticket like any other, so it is triaged in turn. That recursion is
 * bounded where it belongs — in {@see \Claw\Project\ProjectStore::addIssue()}, which refuses a child
 * past the depth or breadth cap — and the whole tree draws on one token pool, so a split cannot
 * multiply spend.
 */
final readonly class Decompose
{
    /**
     * What a good split is, and what the caps mean. The temptation this guards against is
     * PHASE-SPLITTING — "design", "implement", "test" as separate tickets — which reads like a plan
     * but produces parts nobody can finish alone: the second cannot start until the first lands, and
     * none of them is a change anyone can verify. The parts have to be independently solvable, or
     * the split has bought nothing but ceremony.
     */
    private const string SYSTEM = <<<'PROMPT'
        You are the PROJECT MANAGER. A ticket has been judged too big to solve in one run, and your
        job now is to SPLIT it into sub-issues. You are not solving it and not planning how to solve
        it — you are deciding what the separate pieces of work are.

        What makes a good piece:
        - It can be finished ON ITS OWN, without waiting for a sibling. If piece B cannot start until
          piece A lands, they are one piece, not two.
        - It is worth a ticket: real work someone could pick up and verify. If it is a five-minute
          change, fold it into a neighbouring piece.
        - It is SMALL ENOUGH TO BE SOLVED IN ONE RUN. This is the bound people forget, and it is the
          reason you are here: the parent was too big for one run, so a split into two enormous halves
          has bought nothing — each half is judged too big in its turn and the whole thing stalls.
        - Its title says what to do, and its description carries enough context to be worked on by
          someone who has not read the parent — the sub-issue is triaged and solved on its own.

        Do NOT split by phase. "Design it", "implement it", "write the tests", "document it" are
        stages of one piece of work, not separate pieces: each blocks the next, and none of them is
        a change that can be verified alone. Split by what the work TOUCHES — a component, a
        surface, an independent capability.

        Look at the project first (read_file, list_files) so the pieces match what is actually there.

        Then open each one by CALLING the `project_manager` tool with the action `create_issue`,
        passing the parent id, a title, and a description. One call per piece. Prefer FEWER, larger
        pieces: every sub-issue is triaged and run on its own, and costs accordingly.

        YOU MAY OPEN AT MOST %d SUB-ISSUES, and the tree may be at most %d deep. Those are hard limits
        enforced by the store, not advice. Decide the WHOLE split before your first call, so the last
        call you make covers the last of the work — you cannot go back and widen a piece afterwards,
        because nothing edits a sub-issue once it exists.

        If a call is refused because a cap was reached, stop — do not restate it or try again with
        different wording. But understand what that means: some of the parent's work is now covered by
        nothing, because the split you chose did not fit. That is the mistake to avoid by counting
        first, and it is worth saying so in the last piece's description rather than leaving it silent.

        Act only through the tools; describing a call, or printing it as JSON or code, creates
        nothing. Reply with nothing else.
        PROMPT;

    /**
     * {@see SYSTEM} with the real caps filled in. They are INTERPOLATED, never typed as literals: they
     * belong to {@see ProjectStore}, which is what actually refuses the call, and a number copied into a
     * prompt drifts the day the constant moves — one more rule that reads right and enforces nothing.
     */
    private static function system(): string
    {
        return sprintf(self::SYSTEM, ProjectStore::MAX_CHILDREN, ProjectStore::MAX_DEPTH);
    }

    public function __construct(
        private ProjectStoreInterface $store,
        private Config $config,
        private AgentInterface $agent,
    ) {
    }

    /**
     * Split the issue and return the sub-issues that now exist under it. An empty list means the
     * model produced none — it declined, or every call was refused — which the caller treats as a
     * dead end rather than a silent success.
     *
     * Failures are NOT swallowed here. An earlier version caught everything so a model being down
     * could not cost the parent ticket, and that made a real failure indistinguishable from a model
     * that simply said nothing: the run reported "could not be split" with no reason, and the cause
     * could only be found by re-running the same exchange by hand. The caller parks the ticket for a
     * person either way — so it may as well tell the person what happened.
     *
     * @return list<Issue>
     *
     * @throws \Throwable whatever the exchange failed with
     */
    public function split(Issue $issue): array
    {
        $registry = new Registry();
        // ONE action, and not because the prompt asks nicely. This pass opens sub-issues; each of them
        // is triaged afterwards, by a pass that has the shelf and the escalation ledger this one does
        // not. Handed the whole surface, a model that has just opened eight tickets is one helpful
        // impulse from setting strategies on them, or closing the parent, or parking it when the split
        // feels hard — none of which it knows enough to decide here.
        $registry->add(new ProjectManagerTool($this->store, only: ['create_issue']));

        foreach ($this->readOnlyTools() as $tool) {
            $registry->add($tool);
        }

        $env = new Environment()
            ->set(EnvKey::Worker, $this->agent)
            ->set(EnvKey::Registry, $registry)
            ->set(EnvKey::ModelId, $this->config->model)
            ->set(EnvKey::Agents, $this->config->agents);

        $loop = new DefaultTurnLoop(
            $this->agent,
            $env->executor(),
            $env->findAgentModel('project-manager'),
            self::system() . $registry->briefing('The tools you have — these are your only way to act'),
            $registry,
        );

        $loop->run([Message::userText($this->brief($issue))]);

        return $this->store->childIssues($issue->id);
    }

    /** The ticket to split, with the project it belongs to and the id every sub-issue hangs off. */
    private function brief(Issue $issue): string
    {
        $project = $this->store->project();
        $about = trim($project->description) === ''
            ? ''
            : "What this project is:\n{$project->description}\n\n";

        $description = trim($issue->description) === ''
            ? '(no description was given — split from the title and what the project contains)'
            : $issue->description;

        return "Project: {$project->name} ({$project->path})\n\n"
            . $about
            . "Ticket #{$issue->id} — TOO BIG for one run, split it.\n"
            . "Title: {$issue->title}\n\nDescription:\n{$description}\n\n"
            . "Open each piece with create_issue, passing parent='{$issue->id}'.";
    }

    /**
     * Reading only. Splitting is a judgement about what the work is, not the work itself; a model
     * given `bash` or `write_file` here would start doing the task it was asked to divide.
     *
     * @return list<\Claw\Tool\ToolInterface>
     */
    private function readOnlyTools(): array
    {
        try {
            $workspace = new Workspace($this->store->project()->path);
        } catch (ClawException) {
            return [];
        }

        return [new ReadFileTool($workspace), new ListFilesTool($workspace)];
    }
}
