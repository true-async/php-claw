<?php

declare(strict_types=1);

namespace Claw\Knowledge;

/**
 * A tag (aspect) on a knowledge-base article. Tags are how articles are organised
 * and linked. An agent may add tags freely. Weights let the agent rank relevance.
 */
final readonly class Tag
{
    public function __construct(
        public string $name,
        public float $weight = 1.0,
    ) {
    }
}
