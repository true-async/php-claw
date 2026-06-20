<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\TraceEventInterface;

/** A workflow step method began. */
final readonly class StepStarted implements TraceEventInterface
{
    public function __construct(public string $name)
    {
    }

    public function type(): string
    {
        return 'step';
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
