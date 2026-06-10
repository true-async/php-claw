<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * The result of executing a tool, sent back to the model.
 */
final class ToolResultBlock implements ContentBlock
{
    public function __construct(
        public readonly string $toolUseId,
        public readonly string $content,
        public readonly bool $isError = false,
    ) {
    }
}
