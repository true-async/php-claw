<?php

declare(strict_types=1);

namespace Claw\Chat\Status;

final class ToolCallBlock implements StatusBlockInterface
{
    public function __construct(private readonly string $name) {}

    public function render(): string
    {
        return "⚙ {$this->name}";
    }
}
