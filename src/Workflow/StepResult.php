<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * What a finished step hands back: its artifacts, the critic's score, and the
 * outcome the policy derived. The workflow code reads this to decide flow.
 */
final readonly class StepResult
{
    /** @param array<string, mixed> $artifacts */
    public function __construct(
        public array $artifacts,
        public Score $score,
        public StepOutcome $outcome,
    ) {
    }
}
