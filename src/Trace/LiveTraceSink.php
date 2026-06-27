<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * The live-delivery trace sink: after the durable {@see TraceStore} has persisted a record, this
 * publishes it to the {@see TraceBus} so subscribed SSE streams are pushed to with no polling.
 *
 * It must sit AFTER the TraceStore in the tracer's sink list and share the SAME pdo connection: the
 * record carries a span id, not the db's `seq` (the autoincrement primary key the dashboard resumes
 * on), so this reads `lastInsertId()` of the row the TraceStore just inserted on that connection. A
 * run's tracer fans out synchronously on its own connection, so that id is exactly this record's seq;
 * concurrent runs use separate connections, so they never cross.
 */
final readonly class LiveTraceSink implements TraceSinkInterface
{
    public function __construct(
        private TraceBus $bus,
        private \PDO $pdo,
    ) {
    }

    public function write(TraceRecordInterface $record): void
    {
        $seq = (int) $this->pdo->lastInsertId();   // the row TraceStore just inserted on this connection
        $this->bus->publish($record->runId(), self::row($record, $seq));
    }

    /**
     * Format a record the same way {@see \Claw\Server::trace()} serializes a db row, so a subscriber
     * cannot tell a pushed live row from a replayed one.
     *
     * @return array<string, mixed>
     */
    private static function row(TraceRecordInterface $record, int $seq): array
    {
        $event = $record->event();

        return [
            'seq' => $seq,
            'spanId' => $record->id(),
            'parentId' => $record->parentId(),
            'depth' => $record->depth(),
            'phase' => $record->phase(),
            'type' => $event->type,
            'level' => $event->level->value,
            'data' => $event->data,
        ];
    }
}
