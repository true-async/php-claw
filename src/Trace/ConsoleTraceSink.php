<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * The live view: each record as one line, indented by depth, so the hierarchy is visible as the run
 * happens — ▶ opens a span, ◀ closes it, · is a point event, followed by the event's own summary.
 */
final class ConsoleTraceSink implements TraceSinkInterface
{
    /** @param resource $stream */
    public function __construct(private $stream)
    {
    }

    public function write(TraceRecordInterface $record): void
    {
        if (!\is_resource($this->stream)) {
            return;
        }

        $glyph = match ($record->phase()) {
            'enter' => '▶',
            'exit' => '◀',
            default => '·',
        };

        $event = $record->event();
        $head = trim($event->type() . ' ' . $event->summary());

        fwrite($this->stream, str_repeat('  ', $record->depth()) . $glyph . ' ' . $head . "\n");
    }
}
