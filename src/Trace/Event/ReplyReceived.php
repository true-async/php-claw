<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\Level;
use Claw\Trace\TraceEventInterface;

/** A model reply: its text, the tool calls it requested, and the tokens it cost. */
final readonly class ReplyReceived implements TraceEventInterface
{
    /** @param list<array{name: string, input: array<string, mixed>}> $toolCalls */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public int $inTokens,
        public int $outTokens,
    ) {
    }

    public function type(): string
    {
        return 'reply';
    }

    public function level(): Level
    {
        return Level::Debug;
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'tool_calls' => $this->toolCalls,
            'usage' => ['in' => $this->inTokens, 'out' => $this->outTokens],
        ];
    }

    public function summary(): string
    {
        $tokens = '[' . $this->inTokens . '/' . $this->outTokens . ' tok]';
        $calls = $this->toolCalls === []
            ? ''
            : ' →(' . implode(', ', array_map(static fn (array $c): string => (string) $c['name'], $this->toolCalls)) . ')';

        return $tokens . $calls . ' ' . Summarize::oneLine($this->text, 60);
    }
}
