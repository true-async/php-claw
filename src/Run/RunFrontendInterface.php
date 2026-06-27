<?php

declare(strict_types=1);

namespace Claw\Run;

use Claw\Agent\SpeakerInterface;
use Claw\Trace\Tracer;
use Claw\Trace\TraceSinkInterface;

/**
 * The outside-facing side of a run: how {@see \Claw\Run\IssueRunner} reaches a human, gets a generated
 * solver approved, reports progress, and surfaces live trace — so the run pipeline itself holds no I/O
 * opinion. The two implementations are {@see ConsoleRunFrontend} (`claw run`) and {@see HttpRunFrontend}
 * (the dashboard server).
 */
interface RunFrontendInterface
{
    /** The ask channel's human tier, built once the run's tracer exists (the HTTP gate records through it). */
    public function human(Tracer $tracer): SpeakerInterface;

    /** Decide whether to run a freshly generated solver now. */
    public function approveSolver(string $solverPath, string $solverCode): bool;

    /** Emit one progress or error line. */
    public function report(string $message, bool $isError): void;

    /**
     * The run's trace sinks, over the project's db connection: always persists (the console front-end
     * adds the live stderr tree; the HTTP one persists-and-publishes through one {@see LiveTraceSink}).
     *
     * @return list<TraceSinkInterface>
     */
    public function traceSinks(\PDO $projectDb): array;
}
