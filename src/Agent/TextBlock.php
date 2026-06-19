<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * Plain text content.
 */
final readonly class TextBlock implements ContentBlockInterface
{
    public function __construct(public string $text)
    {
    }
}
