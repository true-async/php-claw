<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Claw\Run\RunFrontendInterface;
use Claw\Trace\Tracer;
use Claw\Trace\TraceSinkInterface;
use Claw\Trace\TraceStore;

/**
 * A headless {@see RunFrontendInterface} for tests: it records every progress line, answers the
 * solver-approval decision with a preset flag, and persists trace to the project db (so a test can
 * read artifacts back). Its human tier is always "no one there" (EOF), so an escalation that reaches
 * it passes up to nothing — a test exercises the run pipeline, not a person.
 */
final class RecordingRunFrontend implements RunFrontendInterface
{
    /** @var list<array{message: string, error: bool}> every report(), in order. */
    public array $reports = [];

    public function __construct(public bool $approve = true)
    {
    }

    public function human(Tracer $tracer): SpeakerInterface
    {
        return new class implements SpeakerInterface {
            public function name(): SpeakerRole
            {
                return SpeakerRole::Human;
            }

            public function reply(string $incoming): ?string
            {
                return null;   // no human is attached in a headless test
            }
        };
    }

    public function approveSolver(string $solverPath, string $solverCode): bool
    {
        return $this->approve;
    }

    public function report(string $message, bool $isError): void
    {
        $this->reports[] = ['message' => $message, 'error' => $isError];
    }

    /** @return list<TraceSinkInterface> */
    public function traceSinks(\PDO $projectDb): array
    {
        return [new TraceStore($projectDb)];
    }

    /** Whether any reported line contains $needle — for asserting a progress message was emitted. */
    public function reported(string $needle): bool
    {
        foreach ($this->reports as $report) {
            if (str_contains($report['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
