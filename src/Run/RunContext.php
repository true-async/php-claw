<?php

declare(strict_types=1);

namespace Claw\Run;

use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Project\ProjectStore;
use Claw\Trace\Tracer;
use Claw\Workflow\Environment;
use Claw\Workflow\WorkflowStore;

/**
 * The stable context of one `claw run` — everything the run lifecycle (generate, run, repair) threads
 * around but never changes. Bundling it keeps the run/repair methods from taking the same eight-plus
 * arguments each, and ties the ledger row, the trace, the durable state and the issue/project together
 * as one value.
 */
final readonly class RunContext
{
    public function __construct(
        public Environment $env,
        public Tracer $tracer,
        public ProjectStore $store,
        public WorkflowStore $workflowStore,
        public string $runId,
        public Issue $issue,
        public Project $project,
        public string $solverName,
        public string $solverClass,
    ) {
    }
}
