<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * The light projection of a step used for navigating the context tree without
 * loading full turn histories. The full history stays on disk and is expanded
 * only on demand; this card is the "table of contents" entry.
 */
final readonly class StepCard
{
    public function __construct(
        public int $number,
        public string $description,
        public ?int $score = null,
        public ?StepOutcome $outcome = null,
    ) {
    }
}
