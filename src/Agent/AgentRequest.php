<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * One model round-trip request: history + available tools + model settings.
 */
final class AgentRequest
{
    /**
     * @param list<Message>  $messages
     * @param list<ToolSpec> $tools
     */
    public function __construct(
        public readonly string $model,
        public readonly array $messages,
        public readonly int $maxTokens = 4096,
        public readonly string $system = '',
        public readonly array $tools = [],
        public readonly ?float $temperature = null,
    ) {
    }
}
