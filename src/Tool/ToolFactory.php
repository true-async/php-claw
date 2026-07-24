<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Knowledge\KnowledgeBaseInterface;
use Claw\Knowledge\KnowledgeWriterInterface;
use Claw\Project\Project;
use Claw\Workflow\WorkflowStore;
use Claw\Workflow\WorkflowValidator;

/**
 * Builds the tool palette a solver run works with — the file/shell tools plus the finish tool — against
 * a project's real folder. Kept out of {@see \Claw\Run\IssueRunner} so the run pipeline does not own the
 * tool wiring. The run's own RecallTool is added by the runner afterwards, once the tracer it reads from
 * exists.
 *
 * Note what is NOT here: `define_workflow`. Authoring a workflow is the GENERATOR's job, not the solver's
 * — a solver solves the task. So that tool is mixed into the generation/repair scope only ({@see
 * defineWorkflow()}, wired in {@see \Claw\Run\IssueRunner}), never the solver's palette, where it only
 * invites a confused model to reach for the wrong tool.
 */
final class ToolFactory
{
    public static function forRun(
        Project $project,
        Workspace $workspace,
        ?Secrets $secrets = null,
        ?KnowledgeBaseInterface $knowledge = null,
        ?KnowledgeWriterInterface $knowledgeWriter = null,
        int $timeoutMs = 0,
    ): Registry {
        $registry = new Registry();
        $registry->add(new BashTool($project->path, $secrets, $timeoutMs));
        // Reading is guarded too: a secret leaves through a FILE the run wrote (a token in `.git/config`,
        // a credential cache under the project-rooted $HOME) far more easily than through a command's
        // output. See ReadFileTool::handle().
        $registry->add(new ReadFileTool($workspace, secrets: $secrets));
        $registry->add(new WriteFileTool($workspace));
        $registry->add(new ListFilesTool($workspace));

        // The knowledge base, when the project has a usable one. Offered rather than required: a base
        // that cannot answer would be a tool a model spends turns interrogating before believing it.
        // Whether one is usable is the base's own judgement — see KnowledgeBase::forProject(). The
        // writer arrives separately (its own interface), so a palette can be read-only by omission.
        if ($knowledge !== null) {
            $registry->add(new KnowledgeTool($knowledge, $knowledgeWriter));
        }

        return $registry;
    }

    /** The workflow-authoring tool, mixed into the registry only while GENERATING or REPAIRING a solver. */
    public static function defineWorkflow(WorkflowStore $workflowStore): DefineWorkflowTool
    {
        return new DefineWorkflowTool($workflowStore, new WorkflowValidator());
    }
}
