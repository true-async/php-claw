<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * The envelope every sink consumes: a typed {@see TraceEventInterface} placed in the run's tree.
 * An interface (not just the concrete {@see TraceRecord}) so other record shapes can appear later —
 * a replayed record read back from the store, a remote record — without touching the sinks.
 */
interface TraceRecordInterface
{
    /** The run this record belongs to (ties the whole trace together; the `run_id` column). */
    public function runId(): string;

    /** This record's monotonic id within the run — the span id for enter/exit, or the event id. */
    public function id(): int;

    /** The enclosing span's id, or null at the root. */
    public function parentId(): ?int;

    /** Nesting depth (0 at the root) — drives the indented live view. */
    public function depth(): int;

    /** 'enter' (span opened), 'exit' (span closed), or 'event' (a point under the current span). */
    public function phase(): string;

    /** The typed payload. */
    public function event(): TraceEventInterface;

    /** Unix timestamp (seconds) when the record was produced. */
    public function at(): int;
}
