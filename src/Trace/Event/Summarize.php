<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

/** Shared one-line summariser for text-bearing events (collapse whitespace, clip to a width). */
final class Summarize
{
    public static function oneLine(string $text, int $width = 80): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > $width ? mb_substr($text, 0, $width) . '…' : $text;
    }
}
