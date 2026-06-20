<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\TraceEventInterface;

/** A span (workflow / step / ai / turn) closed. Carries no payload of its own. */
final readonly class SpanEnded implements TraceEventInterface
{
    public function type(): string
    {
        return 'end';
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
