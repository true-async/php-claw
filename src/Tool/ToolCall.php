<?php

declare(strict_types=1);

namespace Claw\Tool;

/**
 * A request to run a tool, as received by the Executor. Built from the model's
 * tool_use by the turn loop; `chatId` lets later layers (approvals) route back.
 */
final class ToolCall
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
        public readonly int $chatId = 0,
    ) {
    }

    /** Human-readable one-liner for approval prompts and the audit log. */
    public function summary(): string
    {
        if ($this->input === []) {
            return $this->name;
        }

        $args = json_encode($this->input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $args === false ? $this->name : "{$this->name} {$args}";
    }
}
