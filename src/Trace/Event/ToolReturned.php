<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\Level;
use Claw\Trace\TraceEventInterface;

/** A tool call returned its result (or an error). */
final readonly class ToolReturned implements TraceEventInterface
{
    public function __construct(
        public string $name,
        public string $text,
        public bool $isError,
    ) {
    }

    public function type(): string
    {
        return 'tool-result';
    }

    /** An error is a milestone worth seeing even when quiet; a normal result is routine progress. */
    public function level(): Level
    {
        return $this->isError ? Level::Notice : Level::Info;
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'text' => $this->text, 'is_error' => $this->isError];
    }

    public function summary(): string
    {
        return $this->name . ($this->isError ? ' [error]' : '') . '  ' . Summarize::oneLine($this->text, 60);
    }
}
