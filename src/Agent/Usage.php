<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * Token usage for one model round-trip.
 */
final class Usage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
    }
}
