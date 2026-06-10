<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * The model's request to call a tool.
 */
final class ToolUseBlock implements ContentBlock
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
    ) {
    }
}
