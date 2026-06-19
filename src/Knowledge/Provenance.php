<?php

declare(strict_types=1);

namespace Claw\Knowledge;

/**
 * Why a knowledge-base entry exists: which workflow created it and at which moment
 * in history, plus a short reason. Lets the base be traced back to its origin.
 */
final readonly class Provenance
{
    public function __construct(
        public ?string $workflow = null,    // workflow that created the entry
        public ?string $historyRef = null,  // moment in history (step / message id)
        public string $reason = '',         // why it was written
    ) {
    }
}
