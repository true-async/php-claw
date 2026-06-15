<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * A critic's judgement of a step: a soft signal, not a command. The workflow's
 * policy reads the value (and the advice) and decides the StepOutcome.
 */
final readonly class Score
{
    public function __construct(
        public int $value,                 // e.g. 0..5
        public bool $adviseHuman = false,  // the critic suggests escalating to a human
        public string $note = '',          // free-text rationale
    ) {
    }
}
