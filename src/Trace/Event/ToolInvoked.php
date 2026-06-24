<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\Level;
use Claw\Trace\TraceEventInterface;

/** A tool was called, with its input. */
final readonly class ToolInvoked implements TraceEventInterface
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $name,
        public array $input,
    ) {
    }

    public function type(): string
    {
        return 'tool';
    }

    public function level(): Level
    {
        return Level::Info;
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'input' => $this->input];
    }

    public function summary(): string
    {
        return $this->name;
    }
}
