<?php

declare(strict_types=1);

namespace Claw;

/**
 * Runs callbacks on an interval, driven by the TrueAsync reactor timer.
 *
 * `tick()` holds the scheduling decision (pure, testable); `run()` is the thin
 * reactor loop that advances a logical clock via `Async\delay()` and ticks.
 */
final class Scheduler
{
    /** @var list<array{everyMs: int, task: callable, dueAtMs: int}> */
    private array $jobs = [];

    /** Register a task to run every $everyMs. */
    public function every(int $everyMs, callable $task): void
    {
        if ($everyMs <= 0) {
            throw new \InvalidArgumentException('Scheduler: interval must be positive');
        }

        $this->jobs[] = ['everyMs' => $everyMs, 'task' => $task, 'dueAtMs' => $everyMs];
    }

    /**
     * Run every job due at $nowMs and reschedule it. Returns how many ran.
     * Missed ticks are skipped (next due is relative to now, no catch-up storm).
     */
    public function tick(int $nowMs): int
    {
        $ran = 0;
        foreach ($this->jobs as $i => $job) {
            if ($nowMs >= $job['dueAtMs']) {
                ($job['task'])();
                $this->jobs[$i]['dueAtMs'] = $nowMs + $job['everyMs'];
                $ran++;
            }
        }

        return $ran;
    }

    /** Reactor loop: sleep one step, tick, repeat. Non-blocking under TrueAsync. */
    public function run(int $resolutionMs = 1000): void
    {
        $elapsed = 0;
        for (;;) {
            \Async\delay($resolutionMs);
            $elapsed += $resolutionMs;
            $this->tick($elapsed);
        }
    }
}
