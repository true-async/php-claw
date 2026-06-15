<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * The heart of step execution: run a step to a verdict. Execute the work, let the
 * critic score it, let the policy decide, and retry while the policy says Retry and
 * attempts remain. This is where "the outcome is the steering wheel" lives.
 *
 * Sequencing of steps (order, loops, branching) stays in the workflow's own run()
 * code; this engine drives a single step to its outcome.
 */
final class WorkflowEngine
{
    public function __construct(
        private readonly StepExecutor $executor,
        private readonly Critic $critic,
        private readonly RetryPolicy $policy = new RetryPolicy(),
    ) {
    }

    public function runStep(StepSpec $spec, int $number, ?Budget $budget = null): StepResult
    {
        $attempt = 0;

        for (;;) {
            ++$attempt;

            if ($budget !== null && $budget->isExhausted()) {
                return new StepResult([], new Score(0, true, 'budget exhausted'), StepOutcome::Abort);
            }

            $step = new Step($number, $attempt, $spec->description, rubric: $spec->rubric);
            $step->status = StepStatus::Running;

            $this->executor->execute($step);

            $score = $this->critic->evaluate($step);
            $step->score = $score;

            $outcome = $this->policy->decide($score, $attempt);
            $step->outcome = $outcome;

            if ($outcome === StepOutcome::Retry) {
                continue;
            }

            $step->status = match ($outcome) {
                StepOutcome::Proceed => StepStatus::Done,
                StepOutcome::AwaitHuman => StepStatus::WaitingHuman,
                StepOutcome::Abort => StepStatus::Aborted,
            };

            return new StepResult($step->artifacts, $score, $outcome);
        }
    }
}
