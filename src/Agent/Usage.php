<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * Token usage for one model round-trip. `cachedTokens` is the subset of `inputTokens` the provider
 * served from its prompt cache (billed cheaper) — 0 when the provider reports none or does not cache.
 */
final class Usage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cachedTokens = 0,
    ) {
    }
}
