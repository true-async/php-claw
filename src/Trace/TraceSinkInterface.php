<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * Where trace records go — the one polymorphic seam of the trace component. Implementations persist
 * ({@see TraceStore}), render live ({@see ConsoleTraceSink}), or collect ({@see ArrayTraceSink}).
 * The {@see Tracer} fans each record out to a list of these; a sink must not throw the run down.
 */
interface TraceSinkInterface
{
    public function write(TraceRecordInterface $record): void;
}
