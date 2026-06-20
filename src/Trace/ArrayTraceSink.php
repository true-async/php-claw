<?php

declare(strict_types=1);

namespace Claw\Trace;

/** Collects records in memory — for programmatic inspection and tests (replaces RecordingJournal). */
final class ArrayTraceSink implements TraceSinkInterface
{
    /** @var list<TraceRecordInterface> */
    public array $records = [];

    public function write(TraceRecordInterface $record): void
    {
        $this->records[] = $record;
    }
}
