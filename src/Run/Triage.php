<?php

declare(strict_types=1);

namespace Claw\Run;

use Claw\Agent\AgentInterface;
use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\Message;
use Claw\Config;
use Claw\Exceptions\ClawException;
use Claw\Project\Issue;
use Claw\Project\ProjectStoreInterface;
use Claw\Project\Strategy;
use Claw\Tool\ProjectManagerTool;
use Claw\Tool\Registry;
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

        Look at the project if it helps (read_file, list_files) and judge the SIZE and SHAPE of the
        work, then choose exactly one strategy:

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

        Then RECORD the verdict with the `project_manager` tool:
        project_manager(action='set_strategy', issue='<id>', strategy='<one of the four>',
        reason='<why, in one or two sentences>', needs_human=<true|false>).

        Reply with nothing else. The decision does not exist until that call is made — everything
        downstream branches on the recorded strategy, and prose is not something code can act on.
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
    public function analyse(Issue $issue): ?Strategy
    {
        $registry = new Registry();
        $registry->add(new ProjectManagerTool($this->store));

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
            self::SYSTEM,
            $registry->specs(),
        );

        try {
            $loop->run([Message::userText($this->brief($issue))]);
        } catch (\Cancellation $cancellation) {
            throw $cancellation;
        } catch (\Throwable) {
            return null;   // see the docblock: a ticket outlives a failed analysis
        }

        return $this->store->currentStrategy($issue->id)['strategy'] ?? null;
    }

    /** The ticket as the ProjectManager sees it, with the id it must quote back to record a verdict. */
    private function brief(Issue $issue): string
    {
        $project = $this->store->project();
        $description = trim($issue->description) === ''
            ? '(no description was given — judge from the title alone)'
            : $issue->description;

        return "Project: {$project->name} ({$project->path})\n\n"
            . "Ticket #{$issue->id}\nTitle: {$issue->title}\n\nDescription:\n{$description}\n\n"
            . "Decide the strategy and record it with project_manager(action='set_strategy', issue='{$issue->id}', …).";
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
