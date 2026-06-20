<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * The durable sink: persists every record into a `trace` table (in the project db), so a run's full
 * hierarchy survives and can be read back later. Named like {@see \Claw\Store\SessionStore} /
 * {@see \Claw\Workflow\WorkflowStore} — a store, not "the SQLite sink". It owns its table; reading a
 * run's history back is a later, additive step on the same rows.
 */
final class TraceStore implements TraceSinkInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::ensureTable($pdo);
    }

    /** Create the trace table if missing — the single source of its schema (shared with the reader). */
    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS trace (
                seq        INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id     TEXT NOT NULL,
                span_id    INTEGER NOT NULL,
                parent_id  INTEGER,
                depth      INTEGER NOT NULL,
                phase      TEXT NOT NULL,
                type       TEXT NOT NULL,
                data       TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )',
        );
    }

    public function write(TraceRecordInterface $record): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO trace (run_id, span_id, parent_id, depth, phase, type, data, created_at)
             VALUES (:run, :span, :parent, :depth, :phase, :type, :data, :at)',
        );
        $stmt->execute([
            'run' => $record->runId(),
            'span' => $record->id(),
            'parent' => $record->parentId(),
            'depth' => $record->depth(),
            'phase' => $record->phase(),
            'type' => $record->event()->type(),
            'data' => json_encode($record->event()->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'at' => $record->at(),
        ]);
    }
}
