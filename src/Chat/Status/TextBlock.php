<?php

declare(strict_types=1);

namespace Claw\Chat\Status;

final class TextBlock implements StatusBlockInterface
{
    public function __construct(private readonly string $text) {}

    public function render(): string
    {
        return $this->text;
    }
}
