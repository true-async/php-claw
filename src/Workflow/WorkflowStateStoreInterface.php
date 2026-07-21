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
 *
 * The handoff — the selective context one step forms for the next — is persisted ALONGSIDE the
 * snapshot but on its own, because it is not part of the workflow's own state and it is written at
 * a different moment: a finished step saves the handoff it formed, and the next step (after a resume
 * in a fresh process, where the in-memory conversation that formed it is gone) loads it back before
 * it runs. {@see saveHandoff()}/{@see loadHandoff()} are that explicit door.
 */
interface WorkflowStateStoreInterface
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

    /**
     * Persist the handoff a finished step formed for the next step to read, keyed by the step that
     * formed it ($fromStep) — so a resume can tell whether the saved handoff is the current one or a
     * stale leftover from an earlier transition.
     */
    public function saveHandoff(string $runId, string $fromStep, string $handoff): void;

    /**
     * The saved handoff and the step that formed it, or {from: '', handoff: ''} when none was saved
     * (the first step, or a fresh run).
     *
     * @return array{from: string, handoff: string}
     */
    public function loadHandoff(string $runId): array;

    /** A fresh, monotonic id for a leaf call — the store owns identity, not the caller. */
    /**
     * Write down the conversation a step is in the MIDDLE of, so an interruption does not cost it.
     *
     * The step-edge snapshot ({@see save()}) records which steps FINISHED. A crash inside one therefore
     * replayed that whole step from a cold start: the model was asked again for work it had already
     * done, and the files it had already written were still written — the same instruction against a
     * world that had already moved. This is the other half of the snapshot, taken at every turn: the
     * resumed step continues its exchange from where it stopped, seeing its own earlier tool results,
     * and so does not repeat them.
     *
     * @param list<\Claw\Agent\Message> $history
     */
    public function saveExchange(string $runId, string $step, array $history): void;

    /**
     * The conversation a step was in the middle of, or an empty list when it was not in one.
     *
     * @return list<\Claw\Agent\Message>
     */
    public function loadExchange(string $runId, string $step): array;

    /** Forget a step's exchange — it finished, and what it did is in the snapshot and the journal now. */
    public function clearExchange(string $runId, string $step): void;

    public function nextId(): string;
}
