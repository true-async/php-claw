<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Turns a critic's soft Score into a hard StepOutcome. This is the deterministic
 * policy that lives in workflow code: the critic only judges, the policy decides.
 *
 * Defaults: pass at score >= 3; otherwise retry, and after maxAttempts escalate to
 * a human. An explicit "advise human" from the critic short-circuits to AwaitHuman.
 */
final readonly class RetryPolicy
{
    public function __construct(
        public int $passThreshold = 3,
        public int $maxAttempts = 3,
    ) {
    }

    public function decide(Score $score, int $attempt): StepOutcome
    {
        if ($score->adviseHuman) {
            return StepOutcome::AwaitHuman;
        }

        if ($score->value >= $this->passThreshold) {
            return StepOutcome::Proceed;
        }

        if ($attempt >= $this->maxAttempts) {
            return StepOutcome::AwaitHuman;   // tried enough; hand it to a human
        }

        return StepOutcome::Retry;
    }
}
