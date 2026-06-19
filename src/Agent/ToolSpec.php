<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * A tool advertised to the model (no handler — execution is the Executor's job).
 * Built from a ToolInterface; `inputSchema` is JSON Schema.
 */
final class ToolSpec
{
    /**
     * @param array<string, mixed> $inputSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
    ) {
    }
}
