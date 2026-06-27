<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Project\Project;
use Claw\Workflow\WorkflowStore;
use Claw\Workflow\WorkflowValidator;

/**
 * Builds the tool palette a solver run works with — the file/shell tools plus the workflow-authoring and
 * finish tools — against a project's real folder. Kept out of {@see \Claw\Cli\IssueRunner} so the run
 * pipeline does not own the tool wiring. The run's own RecallTool is added by the runner afterwards, once
 * the tracer it reads from exists.
 */
final class ToolFactory
{
    public static function forRun(Project $project, Workspace $workspace, WorkflowStore $workflowStore): Registry
    {
        $registry = new Registry();
        $registry->add(new BashTool($project->path));
        $registry->add(new ReadFileTool($workspace));
        $registry->add(new WriteFileTool($workspace));
        $registry->add(new ListFilesTool($workspace));
        $registry->add(new DefineWorkflowTool($workflowStore, new WorkflowValidator()));
        $registry->add(new FinishTool());   // the model can declare the task solved and end the run

        return $registry;
    }
}
