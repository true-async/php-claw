<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * The result of executing a tool, sent back to the model.
 */
final readonly class ToolResultBlock implements ContentBlockInterface
{
    public function __construct(
        public string $toolUseId,
        public string $content,
        public bool   $isError = false,
    ) {
    }
}
