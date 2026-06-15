<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * The durable snapshot of a run, persisted so a crash or a human wait can be
 * resumed. Because the plan can grow at runtime (mutation / dynamic steps), the
 * plan itself is part of the state, not just the position within it.
 */
final class WorkflowState
{
    /**
     * @param array<string, mixed> $params the workflow's parameters
     * @param list<StepSpec>       $plan    the (possibly grown) sequence of steps
     */
    public function __construct(
        public readonly string $workflow,
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
