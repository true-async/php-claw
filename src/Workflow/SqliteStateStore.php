<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * The durable {@see WorkflowStateStore}: snapshots a run's state into the project db, so a run that
 * was killed mid-flight resumes after a restart — the next instance for the same run id loads the
 * saved fields + completed steps and re-runs only the unfinished tail. The in-memory store is the
 * drop-in for tests / single-process runs; this is the production one.
 *
 * One row per run keyed by run id (state + completed steps as JSON). Leaf-call ids come from a
 * monotonic table so they stay unique across restarts, not just within a process.
 */
final class SqliteStateStore implements WorkflowStateStore
{
    public function __construct(private readonly \PDO $pdo)
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

    public function nextId(): string
    {
        $this->pdo->exec('INSERT INTO state_seq DEFAULT VALUES');

        return (string) $this->pdo->lastInsertId();
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
