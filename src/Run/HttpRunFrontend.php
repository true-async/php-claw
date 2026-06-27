<?php

declare(strict_types=1);

namespace Claw\Run;

use Async\Channel;
use Claw\Agent\SpeakerInterface;
use Claw\HttpGateSpeaker;
use Claw\Project\ProjectStore;
use Claw\Trace\LiveTraceSink;
use Claw\Trace\TraceBus;
use Claw\Trace\Tracer;
use Claw\Trace\TraceStore;

/**
 * The HTTP front-end of a run (the dashboard server): the human answers over POST .../answer through a
 * channel-backed gate, the generated solver is auto-approved (the approval gate is a later step),
 * progress goes only to the trace journal the dashboard reads, and the live sink pushes each record to
 * the SSE streams.
 */
final readonly class HttpRunFrontend implements RunFrontendInterface
{
    /** @param Channel<string> $answers the open gate's answer channel — POST .../answer sends the reply here */
    public function __construct(
        private ProjectStore $store,
        private string $issueId,
        private Channel $answers,
        private TraceBus $bus,
    ) {
    }

    public function human(Tracer $tracer): SpeakerInterface
    {
        return new HttpGateSpeaker($tracer, $this->store, $this->issueId, $this->answers);
    }

    public function approveSolver(string $solverPath, string $solverCode): bool
    {
        return true;
    }

    public function report(string $message, bool $isError): void
    {
        // No console: the dashboard reads progress from the trace journal.
    }

    public function traceSinks(\PDO $projectDb): array
    {
        // One sink that persists AND publishes: LiveTraceSink writes through the TraceStore, then pushes
        // the persisted record (with its seq) to the bus — no separate TraceStore to order against.
        return [new LiveTraceSink(new TraceStore($projectDb), $this->bus)];
    }
}
