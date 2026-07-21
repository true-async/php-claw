<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Knowledge\EmbedderInterface;
use Claw\Knowledge\KnowledgeIndex;
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
        ?KnowledgeIndex $index = null,
        ?EmbedderInterface $embedder = null,
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

        // The knowledge base, when the project has one and there is something to embed with. Offered
        // rather than required: a project with no kb/ folder should not be handed a tool that can only
        // tell it so, and one with no embedder configured would get a tool that fails on every call.
        $knowledge = self::knowledge($project, $index, $embedder);

        if ($knowledge !== null) {
            $registry->add($knowledge);
        }

        return $registry;
    }

    /**
     * The knowledge tool for a project, or null when it would be useless.
     *
     * Two conditions, and both are about not offering a capability that cannot work. A project with no
     * `kb/` folder has nothing to search — the tool would exist to answer "nothing is written down",
     * which a model would try several times before believing. And with no embedder there is nothing to
     * turn a question into a vector, so every call would fail identically.
     */
    private static function knowledge(Project $project, ?KnowledgeIndex $index, ?EmbedderInterface $embedder): ?KnowledgeTool
    {
        $folder = $project->path . '/kb';

        if ($index === null || $embedder === null || !is_dir($folder)) {
            return null;
        }

        return new KnowledgeTool($index, $embedder, $folder);
    }

    /** The workflow-authoring tool, mixed into the registry only while GENERATING or REPAIRING a solver. */
    public static function defineWorkflow(WorkflowStore $workflowStore): DefineWorkflowTool
    {
        return new DefineWorkflowTool($workflowStore, new WorkflowValidator());
    }
}
