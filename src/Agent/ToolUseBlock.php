<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * The model's request to call a tool.
 */
final readonly class ToolUseBlock implements ContentBlockInterface
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public string $id,
        public string $name,
        public array  $input,
    ) {
    }
}
