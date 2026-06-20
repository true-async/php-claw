<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * One typed thing that happened in a run — a step starting, a prompt sent, a model reply, a tool
 * call. The hierarchy (parent/depth/phase) is the {@see Tracer}'s job; an event is the *payload*.
 *
 * Adding a new kind of event is one new class implementing this interface — sinks, the store, and
 * the reader consume events only through it, so they never change. That is the whole point of the
 * split: the producer surface is typed, the transport stays uniform.
 */
interface TraceEventInterface
{
    /** Stable kind discriminator, also the `type` column (e.g. 'step', 'ai', 'prompt', 'reply'). */
    public function type(): string;

    /**
     * The event's typed fields flattened for persistence (stored as JSON).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /** A one-line summary for the live console view. */
    public function summary(): string;
}
