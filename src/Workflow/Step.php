<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Agent\Message;

/**
 * One step of a workflow as an entity. Steps run strictly in order. The pre-context
 * is the fixed input the step starts with; the working context is the turn history
 * it grows and which is persisted continuously, so a step can resume mid-flight.
 *
 * Mutable on purpose: status, score and outcome change as the step runs.
 */
final class Step
{
    /**
     * @param list<Message>        $preContext     fixed input (instruction + upstream artifacts)
     * @param list<Message>        $workingContext the turn history this step accumulates
     * @param array<string, mixed> $artifacts      the step's results
     */
    public function __construct(
        public readonly int $number,
        public readonly int $iteration,
        public readonly string $description,
        public array $preContext = [],
        public array $workingContext = [],
        public array $artifacts = [],
        public ?Score $score = null,
        public ?StepOutcome $outcome = null,
        public StepStatus $status = StepStatus::Pending,
    ) {
    }

    /** The light projection of this step for tree navigation. */
    public function card(): StepCard
    {
        return new StepCard($this->number, $this->description, $this->score?->value, $this->outcome);
    }
}
