<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * The durable {@see WorkflowStateStoreInterface}: snapshots a run's state into the project db, so a run that
 * was killed mid-flight resumes after a restart — the next instance for the same run id loads the
 * saved fields + completed steps and re-runs only the unfinished tail. The in-memory store is the
 * drop-in for tests / single-process runs; this is the production one.
 *
 * One row per run keyed by run id (state + completed steps as JSON). The handoff awaiting the next
 * step lives in its own one-row-per-run table, written by a finished step and read back by the next
 * one — kept apart from the state snapshot because the two are saved at different moments. Leaf-call
 * ids come from a monotonic table so they stay unique across restarts, not just within a process.
 */
final readonly class SqliteStateStore implements WorkflowStateStoreInterface
{
    public function __construct(private \PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS workflow_state (
                run_id     TEXT PRIMARY KEY,
                state      TEXT NOT NULL,
                done       TEXT NOT NULL,
                updated_at INTEGER NOT NULL
            )',
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS workflow_handoff (
                run_id     TEXT PRIMARY KEY,
                from_step  TEXT NOT NULL,
                handoff    TEXT NOT NULL,
                updated_at INTEGER NOT NULL
            )',
        );

        $pdo->exec('CREATE TABLE IF NOT EXISTS state_seq (id INTEGER PRIMARY KEY AUTOINCREMENT)');
    }

    public function save(string $runId, array $state, array $done): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO workflow_state (run_id, state, done, updated_at)
             VALUES (:run, :state, :done, :at)',
        );

        $stmt->execute([
            'run' => $runId,
            'state' => json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'done' => json_encode($done, JSON_THROW_ON_ERROR),
            'at' => time(),
        ]);
    }

    public function load(string $runId): array
    {
        $stmt = $this->pdo->prepare('SELECT state, done FROM workflow_state WHERE run_id = :run');
        $stmt->execute(['run' => $runId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return ['state' => [], 'done' => []];
        }

        return [
            'state' => $this->decodeState($row['state'] ?? ''),
            'done' => $this->decodeDone($row['done'] ?? ''),
        ];
    }

    public function saveHandoff(string $runId, string $fromStep, string $handoff): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO workflow_handoff (run_id, from_step, handoff, updated_at)
             VALUES (:run, :from, :handoff, :at)',
        );

        $stmt->execute(['run' => $runId, 'from' => $fromStep, 'handoff' => $handoff, 'at' => time()]);
    }

    /** @return array{from: string, handoff: string} */
    public function loadHandoff(string $runId): array
    {
        $stmt = $this->pdo->prepare('SELECT from_step, handoff FROM workflow_handoff WHERE run_id = :run');
        $stmt->execute(['run' => $runId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return ['from' => '', 'handoff' => ''];
        }

        return [
            'from' => \is_string($row['from_step'] ?? null) ? $row['from_step'] : '',
            'handoff' => \is_string($row['handoff'] ?? null) ? $row['handoff'] : '',
        ];
    }

    public function nextId(): string
    {
        // AUTOINCREMENT (via sqlite_sequence) hands out a fresh, never-reused id even across restarts.
        // The inserted rows have no other purpose, so reclaim the spent ones — the counter table can
        // never grow unboundedly, it holds just the latest row.
        $this->pdo->exec('INSERT INTO state_seq DEFAULT VALUES');
        $id = (string) $this->pdo->lastInsertId();
        $this->pdo->exec('DELETE FROM state_seq WHERE id < ' . (int) $id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function decodeState(mixed $json): array
    {
        $decoded = json_decode(\is_string($json) ? $json : '', true);
        $state = [];
        if (\is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $state[(string) $key] = $value;
            }
        }

        return $state;
    }

    /** @return list<string> */
    private function decodeDone(mixed $json): array
    {
        $decoded = json_decode(\is_string($json) ? $json : '', true);
        $done = [];
        if (\is_array($decoded)) {
            foreach ($decoded as $value) {
                if (\is_string($value)) {
                    $done[] = $value;
                }
            }
        }

        return $done;
    }
}
