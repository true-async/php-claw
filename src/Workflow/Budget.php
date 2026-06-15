<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * A run's hard ceiling: tokens, wall-clock, money. Lives in the context and is
 * introduced from the start. Sub-workflows share the parent pool by default; the
 * workflow may carve out sub-limits. Exhaustion stops the run.
 *
 * Skeleton: only token spend is tracked here; time/cost are placeholders.
 */
final class Budget
{
    private int $spentTokens = 0;

    public function __construct(
        public readonly ?int $maxTokens = null,
        public readonly ?int $maxSeconds = null,
        public readonly ?int $maxCostCents = null,
    ) {
    }

    public function charge(int $tokens): void
    {
        $this->spentTokens += $tokens;
    }

    public function spentTokens(): int
    {
        return $this->spentTokens;
    }

    public function isExhausted(): bool
    {
        return $this->maxTokens !== null && $this->spentTokens >= $this->maxTokens;
    }
}
