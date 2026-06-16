<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * The durable snapshot of a run, persisted so a crash or a human wait can be
 * resumed. Because the plan can grow at runtime (mutation / dynamic steps), the
 * plan itself is part of the state, not just the position within it.
 *
 * The ownership chain is project -> issue -> workflow -> workflow -> ... Each run
 * carries its project and issue ids and its parent run id, so from a nested
 * workflow you can walk up to its parent run, and on to the issue and the project.
 */
final class WorkflowState
{
    /**
     * @param array<string, mixed> $params the workflow's parameters
     * @param list<StepSpec>       $plan    the (possibly grown) sequence of steps
     */
    public function __construct(
        public readonly string $id,          // this run's id
        public readonly string $workflow,    // workflow name
        public readonly string $project,     // owning project id
        public readonly string $issue,       // owning issue id
        public readonly ?string $parent = null,  // parent workflow run id; null at the issue's root run
        public array $params = [],
        public array $plan = [],
        public int $position = 1,            // current step number
        public int $iteration = 1,           // current loop iteration of that step
        public WorkflowStatus $status = WorkflowStatus::Running,
        public ?Budget $budget = null,
        public ?int $deadlineTs = null,      // waiting_human soft timeout, unix ts
    ) {
    }
}
