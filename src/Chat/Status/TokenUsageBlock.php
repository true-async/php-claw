<?php

declare(strict_types=1);

namespace Claw\Chat\Status;

use Claw\Agent\Usage;

final class TokenUsageBlock implements StatusBlockInterface
{
    public function __construct(private readonly Usage $usage) {}

    public function render(): string
    {
        return "↑{$this->usage->inputTokens} ↓{$this->usage->outputTokens} tokens";
    }
}
