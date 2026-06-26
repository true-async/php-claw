<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * The default {@see WorkflowStateStore}: keeps each run's latest snapshot in a map and hands out
 * sequential ids, all in process memory. Self-consistent within a process lifetime (ids unique,
 * progress restorable) with no external dependency; what it does NOT give is durability across
 * process boundaries — restart and the map is empty. The SQLite store (a later phase) is the
 * durable drop-in.
 */
final class InMemoryStateStore implements WorkflowStateStore
{
    /** @var array<string, array{state: array<string, mixed>, done: list<string>}> latest snapshot per run */
    private array $runs = [];

    /** @var array<string, array{from: string, handoff: string}> the handoff awaiting the next step, per run */
    private array $handoffs = [];

    /** Monotonic counter behind nextId() — the in-memory stand-in for a DB autoincrement. */
    private int $seq = 0;

    public function save(string $runId, array $state, array $done): void
    {
        $this->runs[$runId] = ['state' => $state, 'done' => $done];
    }

    /** @return array{state: array<string, mixed>, done: list<string>} */
    public function load(string $runId): array
    {
        return $this->runs[$runId] ?? ['state' => [], 'done' => []];
    }

    public function saveHandoff(string $runId, string $fromStep, string $handoff): void
    {
        $this->handoffs[$runId] = ['from' => $fromStep, 'handoff' => $handoff];
    }

    /** @return array{from: string, handoff: string} */
    public function loadHandoff(string $runId): array
    {
        return $this->handoffs[$runId] ?? ['from' => '', 'handoff' => ''];
    }

    public function nextId(): string
    {
        return (string) ++$this->seq;
    }
}
