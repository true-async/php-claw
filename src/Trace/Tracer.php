<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * The one recorder for a run — logger and tracer in one. Hierarchical: an enterX method opens a
 * span and makes it the current parent, {@see exit()} closes it, and the event methods attach a
 * point under the current span — so the whole tree (workflow → step → ai → turn → tool) is captured
 * with parent and depth. These typed methods are the only surface (callers never build a record);
 * each packs its arguments into a uniform {@see TraceEvent} — type, {@see Level}, and a data bag —
 * and fans a {@see TraceRecord} out to every sink. Synchronous and single-stack (parallel
 * sub-workflows would need a per-coroutine stack); a failing sink never breaks the run.
 */
final class Tracer
{
    private int $seq = 0;

    /** @var list<int> ids of the currently-open spans; the last is the current parent. */
    private array $stack = [];

    /** @var array<int, Level> each open span's level, so its close ('end') inherits it. */
    private array $spanLevel = [];

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
        return $this->open(new TraceEvent('workflow', Level::Notice, ['name' => $name]));
    }

    public function enterStep(string $name): int
    {
        return $this->open(new TraceEvent('step', Level::Info, ['name' => $name]));
    }

    public function enterAi(string $role, string $model): int
    {
        return $this->open(new TraceEvent('ai', Level::Debug, ['role' => $role, 'model' => $model]));
    }

    public function enterTurn(int $number, string $model): int
    {
        return $this->open(new TraceEvent('turn', Level::Debug, ['number' => $number, 'model' => $model]));
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

        $level = $this->spanLevel[$id] ?? Level::Info;   // a close is shown exactly when its open was
        unset($this->spanLevel[$id]);
        $this->emit('exit', $id, new TraceEvent('end', $level));
    }

    /** @param list<string> $tools */
    public function prompt(string $text, array $tools = []): void
    {
        $this->event(new TraceEvent('prompt', Level::Debug, ['text' => $text, 'tools' => $tools]));
    }

    /** @param list<array{name: string, input: array<string, mixed>}> $toolCalls */
    public function reply(string $text, array $toolCalls, int $inTokens, int $outTokens): void
    {
        $this->event(new TraceEvent('reply', Level::Debug, [
            'text' => $text,
            'tool_calls' => $toolCalls,
            'usage' => ['in' => $inTokens, 'out' => $outTokens],
        ]));
    }

    /** @param array<string, mixed> $input */
    public function toolCall(string $name, array $input): void
    {
        $this->event(new TraceEvent('tool', Level::Info, ['name' => $name, 'input' => $input]));
    }

    public function toolResult(string $name, string $text, bool $isError): void
    {
        // An error is a milestone worth seeing even when quiet; a normal result is routine progress.
        $level = $isError ? Level::Notice : Level::Info;
        $this->event(new TraceEvent('tool-result', $level, ['name' => $name, 'text' => $text, 'is_error' => $isError]));
    }

    /** @param array<string, mixed> $context */
    public function log(string $action, string $message = '', array $context = [], Level $level = Level::Info): void
    {
        $this->event(new TraceEvent('note', $level, ['action' => $action, 'message' => $message, 'context' => $context]));
    }

    private function open(TraceEvent $event): int
    {
        $id = ++$this->seq;
        $this->spanLevel[$id] = $event->level;   // remembered so the matching exit inherits it
        $this->emit('enter', $id, $event);       // parent/depth from the current stack, before the push
        $this->stack[] = $id;

        return $id;
    }

    private function event(TraceEvent $event): void
    {
        $this->emit('event', ++$this->seq, $event);
    }

    private function emit(string $phase, int $id, TraceEvent $event): void
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
