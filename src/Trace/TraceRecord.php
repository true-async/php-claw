<?php

declare(strict_types=1);

namespace Claw\Trace;

/** The default {@see TraceRecordInterface}: an immutable envelope around a typed event. */
final readonly class TraceRecord implements TraceRecordInterface
{
    public function __construct(
        private string $runId,
        private int $id,
        private ?int $parentId,
        private int $depth,
        private string $phase,
        private TraceEvent $event,
        private int $at,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function parentId(): ?int
    {
        return $this->parentId;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function event(): TraceEvent
    {
        return $this->event;
    }

    public function at(): int
    {
        return $this->at;
    }
}
