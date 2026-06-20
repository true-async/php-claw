<?php

declare(strict_types=1);

namespace Claw\Trace;

use Claw\Trace\Event\AiStarted;
use Claw\Trace\Event\Noted;
use Claw\Trace\Event\PromptSent;
use Claw\Trace\Event\ReplyReceived;
use Claw\Trace\Event\SpanEnded;
use Claw\Trace\Event\StepStarted;
use Claw\Trace\Event\ToolInvoked;
use Claw\Trace\Event\ToolReturned;
use Claw\Trace\Event\TurnStarted;
use Claw\Trace\Event\WorkflowStarted;

/**
 * The one recorder for a run — logger and tracer in one. Hierarchical: an enterX method opens a
 * span and makes it the current parent, {@see exit()} closes it, and the event methods attach a
 * point under the current span — so the whole tree (workflow → step → ai → turn → tool) is captured
 * with parent and depth. These typed methods are the only surface (callers never build a record or
 * a payload bag); each one wraps a typed {@see TraceEventInterface} and fans a {@see TraceRecord}
 * out to every sink. Synchronous and single-stack (parallel sub-workflows would need a per-coroutine
 * stack); a failing sink never breaks the run.
 */
final class Tracer
{
    private int $seq = 0;

    /** @var list<int> ids of the currently-open spans; the last is the current parent. */
    private array $stack = [];

    /** @var list<TraceSinkInterface> */
    private readonly array $sinks;

    public function __construct(
        private readonly string $runId,
        TraceSinkInterface ...$sinks,
    ) {
        $this->sinks = array_values($sinks);
    }

    public function enterWorkflow(string $name): int
    {
        return $this->open(new WorkflowStarted($name));
    }

    public function enterStep(string $name): int
    {
        return $this->open(new StepStarted($name));
    }

    public function enterAi(string $role, string $model): int
    {
        return $this->open(new AiStarted($role, $model));
    }

    public function enterTurn(int $number, string $model): int
    {
        return $this->open(new TurnStarted($number, $model));
    }

    /** Close a span (and any still-open children, defensively). A null id is a no-op. */
    public function exit(?int $id): void
    {
        if ($id === null) {
            return;
        }

        while ($this->stack !== [] && $this->top() !== $id) {
            array_pop($this->stack);
        }
        if ($this->stack !== []) {
            array_pop($this->stack);
        }

        $this->emit('exit', $id, new SpanEnded());
    }

    /** @param list<string> $tools */
    public function prompt(string $text, array $tools = []): void
    {
        $this->event(new PromptSent($text, $tools));
    }

    /** @param list<array{name: string, input: array<string, mixed>}> $toolCalls */
    public function reply(string $text, array $toolCalls, int $inTokens, int $outTokens): void
    {
        $this->event(new ReplyReceived($text, $toolCalls, $inTokens, $outTokens));
    }

    /** @param array<string, mixed> $input */
    public function toolCall(string $name, array $input): void
    {
        $this->event(new ToolInvoked($name, $input));
    }

    public function toolResult(string $name, string $text, bool $isError): void
    {
        $this->event(new ToolReturned($name, $text, $isError));
    }

    /** @param array<string, mixed> $context */
    public function log(string $action, string $message = '', array $context = []): void
    {
        $this->event(new Noted($action, $message, $context));
    }

    private function open(TraceEventInterface $event): int
    {
        $id = ++$this->seq;
        $this->emit('enter', $id, $event);   // parent/depth from the current stack, before the push
        $this->stack[] = $id;

        return $id;
    }

    private function event(TraceEventInterface $event): void
    {
        $this->emit('event', ++$this->seq, $event);
    }

    private function emit(string $phase, int $id, TraceEventInterface $event): void
    {
        $record = new TraceRecord($this->runId, $id, $this->top(), \count($this->stack), $phase, $event, time());

        foreach ($this->sinks as $sink) {
            try {
                $sink->write($record);
            } catch (\Throwable) {
                // Tracing must never bring the run down.
            }
        }
    }

    private function top(): ?int
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }
}
