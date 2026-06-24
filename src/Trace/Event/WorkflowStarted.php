<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\Level;
use Claw\Trace\TraceEventInterface;

/** A workflow run began. */
final readonly class WorkflowStarted implements TraceEventInterface
{
    public function __construct(public string $name)
    {
    }

    public function type(): string
    {
        return 'workflow';
    }

    public function level(): Level
    {
        return Level::Notice;
    }

    public function toArray(): array
    {
        return ['name' => $this->name];
    }

    public function summary(): string
    {
        return $this->name;
    }
}
