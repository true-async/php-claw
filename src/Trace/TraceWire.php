<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * The trace wire shape, shared by every live edge. The SSE stream and the
 * WebSocket room publish both format a (record, seq) into this exact array, so
 * a pushed event is identical whichever transport carries it — and identical to
 * the replay shape {@see TraceReader::tail()} reads back from a stored row.
 */
final class TraceWire
{
    /** @return array<string, mixed> */
    public static function row(TraceRecordInterface $record, int $seq): array
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
            'tsMs' => $record->at(),
            'data' => $event->data,
        ];
    }
}
