<?php

declare(strict_types=1);

namespace Claw\Project;

/**
 * A project's state store: its issues, the runs spawned for them, and the connection that backs the
 * trace and run-state stores. {@see ProjectStore} is the SQLite implementation; this seam is what the
 * dashboard and the runner depend on, so a different backend (Postgres/MySQL) can drop in without them
 * knowing which db they hit.
 *
 * Caveat: {@see pdo()} still hands out a raw \PDO — the trace and workflow-state stores read SQL straight
 * off it — so a real backend swap also means abstracting those. This interface covers the issue/run
 * surface; it does not by itself make the whole app database-agnostic.
 */
interface ProjectStoreInterface
{
    /** The opened project's metadata. */
    public function project(): Project;

    /**
     * Every issue in the project, oldest first (soft-deleted ones hidden).
     *
     * @return list<Issue>
     */
    public function allIssues(): array;

    /**
     * The runs spawned for an issue, oldest first, with their status.
     *
     * @return list<array{id: string, workflow: string, status: string}>
     */
    public function runsFor(string $issueId): array;

    /** The shared connection backing the run-state store and the tracer. */
    public function pdo(): \PDO;

    /**
     * Open a new issue and return it — the store assigns its id. Pass $parent to open it as a
     * sub-issue of an existing one; its depth is derived from the parent, never supplied.
     *
     * @throws \Claw\Exceptions\ClawException
     */
    public function addIssue(string $title, string $description = '', ?string $parent = null): Issue;

    /**
     * The issues decomposed directly out of this one, oldest first (soft-deleted ones hidden).
     *
     * @return list<Issue>
     */
    public function childIssues(string $issueId): array;

    /**
     * Settle the ancestors of an issue that just landed: a parent finishes only when every child has.
     * Walks up, stopping at the first ancestor that still has open work.
     */
    public function settleAncestors(string $issueId): void;

    /** Reopen the ancestors of an issue that came back to life — the counterpart to settleAncestors(). */
    public function reopenAncestors(string $issueId): void;

    /**
     * Record the ProjectManager's verdict for an issue — how it is to be solved, why, and whether a
     * person must sign off. Appended, never overwritten.
     *
     * @throws \Claw\Exceptions\ClawException when the strategy does not escalate past one that failed here
     */
    public function setStrategy(string $issueId, Strategy $strategy, string $reason, bool $needsHuman): void;

    /**
     * The verdict currently in force for an issue, or null if it was never triaged.
     *
     * @return ?array{strategy: Strategy, reason: string, needsHuman: bool, outcome: StrategyOutcome, outcomeReason: string}
     */
    public function currentStrategy(string $issueId): ?array;

    /**
     * Every verdict passed on an issue, oldest first — what was tried and how it ended.
     *
     * @return list<array{strategy: Strategy, reason: string, needsHuman: bool, outcome: StrategyOutcome, outcomeReason: string}>
     */
    public function strategyAttempts(string $issueId): array;

    /**
     * Mark the verdict in force as failed and reopen the issue so it is triaged again. False when there
     * was no verdict in force to fail.
     */
    public function failStrategy(string $issueId, string $reason): bool;

    /**
     * Load one issue with the ids of the runs spawned for it.
     *
     * @throws \Claw\Exceptions\ClawException
     */
    public function loadIssue(string $issueId): Issue;

    /** Record a run spawned for an issue and return its store-assigned id. */
    public function recordRun(string $issueId, string $workflow, RunStatus $status = RunStatus::Running): string;

    public function setRunStatus(string $runId, RunStatus $status): void;

    /**
     * Recent runs from the ledger, newest first.
     *
     * @return list<array{id: string, issue: string, workflow: string, status: string}>
     */
    public function recentRuns(int $limit = 20): array;

    /** The id of an interrupted (still-Running) run for this issue's workflow, newest first, or null. */
    public function resumableRun(string $issueId, string $workflow): ?string;

    public function setIssueStatus(string $issueId, IssueStatus $status): void;
}
