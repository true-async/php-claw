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

    /** Close a span (and any still-open children, defensively). A null/unknown id is a no-op. */
    public function exit(?int $id): void
    {
        if ($id === null) {
            return;
        }

        // An id we don't hold open (double exit, foreign id) must NOT unwind the
        // whole stack — just drop any stray level and bail.
        if (!\in_array($id, $this->stack, true)) {
            unset($this->spanLevel[$id]);

            return;
        }

        // Close still-open children first, then the target — every opened span gets
        // a matching exit, so the persisted trace stays balanced (an unbalanced enter
        // makes the reader's stepBounds() run away) and spanLevel can't leak.
        while ($this->top() !== $id) {
            $this->closeTop();
        }
        $this->closeTop();
    }

    /** Pop the current span and emit its matching 'exit', inheriting the level its 'enter' had. */
    private function closeTop(): void
    {
        $id = (int) array_pop($this->stack);
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

    /** A named output a step produced — recorded under the current step so it shows in the journal. */
    public function artifact(string $label, string $kind, string $value): void
    {
        $this->event(new TraceEvent('artifact', Level::Info, ['label' => $label, 'kind' => $kind, 'value' => $value]));
    }

    /** The baton a step hands to the next: its returned summary of what it did / what to watch for. */
    public function handoff(string $text): void
    {
        $this->event(new TraceEvent('handoff', Level::Notice, ['text' => $text]));
    }

    /**
     * A run paused for a human: record the escalation/question and return its span id. The dashboard
     * surfaces the latest `question` with no matching {@see answer()} as the issue's open gate, and the
     * id lets the answer point back at exactly this question (one run can gate more than once).
     */
    public function question(string $prompt): int
    {
        $id = ++$this->seq;
        $this->emit('event', $id, new TraceEvent('question', Level::Notice, ['prompt' => $prompt]));

        return $id;
    }

    /** The human's reply to a {@see question()}, recorded against its id — closes that gate in the journal. */
    public function answer(int $questionId, string $text): void
    {
        $this->event(new TraceEvent('answer', Level::Notice, ['ref' => $questionId, 'text' => $text]));
    }

    /** The run jumped BACK to an earlier step (e.g. a review sending the work back) — recorded with the reason. */
    public function back(string $from, string $to, string $reason): void
    {
        $this->event(new TraceEvent('back', Level::Notice, ['from' => $from, 'to' => $to, 'reason' => $reason]));
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
            } catch (\Exception) {
                // Tracing must never bring the run down.
            }
        }
    }

    private function top(): ?int
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }
}
