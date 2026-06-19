<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Where a run's progress is persisted so it can resume. A run is durable by SNAPSHOT: after each
 * step the workflow's own state (its fields) and the names of the steps completed so far are
 * saved against the run id. On restart the state is restored onto the instance and run() skips
 * the steps already done, re-executing only the unfinished tail — so a skipped step loses
 * nothing, its effect is already in the restored state.
 *
 * Concrete stores ({@see InMemoryStateStore} as the default, SQLite for production — a later
 * phase) decide how to persist. Every run has a store, so ids and durability are always
 * available; the in-memory default just makes resume a no-op across process boundaries.
 *
 * The store is also the source of leaf-call ids: nextId() hands out a monotonic identifier so
 * the workflow never fabricates ids by string-mangling the run id.
 */
interface WorkflowStateStore
{
    /**
     * Persist a run's state snapshot and the names of the steps completed so far.
     *
     * @param array<string, mixed> $state the workflow's own fields
     * @param list<string>         $done  completed step names
     */
    public function save(string $runId, array $state, array $done): void;

    /**
     * The persisted snapshot for a run — its state and completed step names — or empty defaults
     * when the run has no saved progress.
     *
     * @return array{state: array<string, mixed>, done: list<string>}
     */
    public function load(string $runId): array;

    /** A fresh, monotonic id for a leaf call — the store owns identity, not the caller. */
    public function nextId(): string;
}
