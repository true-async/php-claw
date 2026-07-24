<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Tool\ToolResultMeta;

/**
 * The result of executing a tool, sent back to the model. `meta` is the tool's own structured
 * report about the execution (exit status, recognized program, its verdict line) — invisible to
 * the model, read by code that must not re-parse the text (the artifact recorder, the dashboard).
 */
final readonly class ToolResultBlock implements ContentBlockInterface
{
    public function __construct(
        public string $toolUseId,
        public string $content,
        public bool   $isError = false,
        public ?ToolResultMeta $meta = null,
    ) {
    }
}
