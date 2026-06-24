<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * A spend limit for a run, measured in two resources: model TOKENS (charged explicitly as the work
 * happens) and elapsed TIME (measured from when the budget started). A budget can have a PARENT, so
 * scopes nest: a per-turn budget is a child of the whole-run budget — {@see spend()} charges this
 * budget and bubbles up to the parent, and {@see isExhausted()} is true when THIS budget's tokens or
 * time are spent OR any ancestor's are. A zero limit means "no limit", so an unset budget is inert.
 *
 * It lives in the Agent layer because {@see DefaultTurnLoop} (the turn) consults it; the workflow
 * layer above reuses the same type for the run total. The clock is injected so time is testable; in
 * production it is wall-clock {@see microtime()}. A resumed run starts a fresh budget (time is
 * per-process), which is the right anti-runaway bound.
 */
final class Budget
{
    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private readonly float $startedAt;

    private int $tokens = 0;

    /**
     * @param int           $tokenLimit   max tokens this scope may spend (0 = unlimited)
     * @param float         $secondsLimit max wall-clock seconds this scope may run (0 = unlimited)
     * @param ?Budget       $parent       the enclosing scope, also charged and checked (null = root)
     * @param ?\Closure(): float $clock   time source; defaults to microtime (injected in tests)
     */
    public function __construct(
        private readonly int $tokenLimit = 0,
        private readonly float $secondsLimit = 0.0,
        private readonly ?Budget $parent = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->startedAt = ($this->clock)();
    }

    /** A child scope (e.g. one turn) with its own limits, charged through to this budget as parent. */
    public function child(int $tokenLimit = 0, float $secondsLimit = 0.0): self
    {
        return new self($tokenLimit, $secondsLimit, $this, $this->clock);
    }

    /** Charge $tokens to this scope and every ancestor. */
    public function spend(int $tokens): void
    {
        $this->tokens += $tokens;
        $this->parent?->spend($tokens);
    }

    /** True once this scope's tokens or time are used up, or any ancestor's are. */
    public function isExhausted(): bool
    {
        if ($this->tokenLimit > 0 && $this->tokens >= $this->tokenLimit) {
            return true;
        }
        if ($this->secondsLimit > 0.0 && $this->elapsed() >= $this->secondsLimit) {
            return true;
        }

        return $this->parent !== null && $this->parent->isExhausted();
    }

    /** A short, human-readable reason this (or an ancestor) is exhausted — for the stop message. */
    public function reason(): string
    {
        if ($this->tokenLimit > 0 && $this->tokens >= $this->tokenLimit) {
            return "token budget exhausted ({$this->tokens}/{$this->tokenLimit})";
        }
        if ($this->secondsLimit > 0.0 && $this->elapsed() >= $this->secondsLimit) {
            return 'time budget exhausted (' . round($this->elapsed(), 1) . "s/{$this->secondsLimit}s)";
        }

        return $this->parent !== null ? $this->parent->reason() : 'budget exhausted';
    }

    /** Seconds since this budget started. */
    public function elapsed(): float
    {
        return ($this->clock)() - $this->startedAt;
    }

    /** Tokens charged to this scope so far. */
    public function tokens(): int
    {
        return $this->tokens;
    }
}
