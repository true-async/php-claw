<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Turns a critic's soft Score into a hard StepOutcome. The critic only judges; the
 * policy decides. Different workflows can plug in different policies.
 */
interface RetryPolicyInterface
{
    public function decide(Score $score, int $attempt): StepOutcome;
}
