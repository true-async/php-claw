<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * Plain text content.
 */
final class TextBlock implements ContentBlock
{
    public function __construct(public readonly string $text)
    {
    }
}
