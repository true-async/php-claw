<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * The model's next move. `content` is the full assistant turn (text + tool_use),
 * appended to history verbatim; `toolCalls` is the tool_use subset for dispatch.
 */
final class AgentResponse
{
    /**
     * @param list<ContentBlock> $content
     * @param list<ToolUseBlock> $toolCalls
     */
    public function __construct(
        public readonly array $content,
        public readonly array $toolCalls,
        public readonly StopReason $stopReason,
        public readonly Usage $usage,
        public readonly ?string $text = null,
    ) {
    }

    public function wantsToolUse(): bool
    {
        return $this->stopReason === StopReason::ToolUse || $this->toolCalls !== [];
    }
}
