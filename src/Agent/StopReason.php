<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * Why the model stopped. `Other` collapses provider values the agent loop does
 * not act on (stop_sequence, pause_turn, model_context_window_exceeded, ...).
 */
enum StopReason: string
{
    case EndTurn = 'end_turn';
    case ToolUse = 'tool_use';
    case MaxTokens = 'max_tokens';
    case Refusal = 'refusal';
    case Other = 'other';

    public static function fromWire(?string $value): self
    {
        return match ($value) {
            'end_turn' => self::EndTurn,
            'tool_use' => self::ToolUse,
            'max_tokens' => self::MaxTokens,
            'refusal' => self::Refusal,
            default => self::Other,
        };
    }
}
