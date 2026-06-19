<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * The outcome of one ReAct turn: the full history after the turn (the assistant's
 * messages and any tool results appended), the final answer text, and the token
 * usage accumulated over the round-trips the turn took.
 */
final readonly class TurnResult
{
    /** @param list<Message> $history the conversation history after the turn */
    public function __construct(
        public array $history,
        public ?string $text,
        public Usage $usage,
    ) {
    }
}
