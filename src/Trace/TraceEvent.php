<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * One thing that happened in a run — a value object, not a class per kind. The {@see Tracer}'s typed
 * methods (`enterAi`, `toolCall`, `reply`, …) are the producer surface that knows each kind's fields
 * and importance; they pack that into this one uniform envelope:
 *
 *  - $type   — a stable discriminator (also the `type` column): 'workflow', 'step', 'tool', 'reply'…
 *  - $level  — the {@see Level} the sinks filter on to set output density.
 *  - $data   — the kind's typed fields as a bag, persisted as JSON.
 *
 * Turning an event into a one-line string lives in {@see TraceFormat}, shared by the live console and
 * the history reader — so there is exactly one renderer, and a new kind is one `Tracer` method, not a
 * new class plus a matching case in two formatters.
 */
final readonly class TraceEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $type,
        public Level $level,
        public array $data = [],
    ) {
    }
}
