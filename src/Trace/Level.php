<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * How important a trace event is — the one knob the console (and the history reader) filter on. A
 * sink is handed a threshold and prints only events at or above it, so "density" is a property of
 * each event, not a type-switch in the sink:
 *
 *   Debug  — the model's inner workings: the ai span, a turn, the prompt, the reply, full tool I/O.
 *   Info   — the run's progress: a step starting, a tool call and its ok result, a logged note.
 *   Notice — milestones and trouble: a workflow starting/finishing, a tool error, a human request.
 *
 * The backing ints are ordered (higher = more important), so the filter is a plain `>=`, and the
 * value is what {@see TraceStore} persists so the reader can filter the same way over the db.
 */
enum Level: int
{
    case Debug = 10;
    case Info = 20;
    case Notice = 30;

    /** True when this event is important enough for a sink set to $threshold — at least as high. */
    public function passes(self $threshold): bool
    {
        return $this->value >= $threshold->value;
    }
}
