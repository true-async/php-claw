<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\Level;
use Claw\Trace\TraceEventInterface;

/**
 * A span (workflow / step / ai / turn) closed. Carries no payload of its own, but inherits the
 * level of the span it closes, so a close is shown exactly when its open was (the {@see Tracer}
 * passes the opening event's level here).
 */
final readonly class SpanEnded implements TraceEventInterface
{
    public function __construct(public Level $level = Level::Info)
    {
    }

    public function type(): string
    {
        return 'end';
    }

    public function level(): Level
    {
        return $this->level;
    }

    public function toArray(): array
    {
        return [];
    }

    public function summary(): string
    {
        return '';
    }
}
