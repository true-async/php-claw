<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * The one place a trace event becomes a one-line string — used by BOTH the live {@see ConsoleTraceSink}
 * and the history {@see TraceReader}, so the rendering can never drift between live and replayed (the
 * old design rendered it twice: each event's summary() and the reader's brief()). Keyed by event type
 * over the stored data bag, so it works the same on a fresh event or a row read back from the db.
 */
final class TraceFormat
{
    /** @param array<string, mixed> $data */
    public static function summary(string $type, array $data): string
    {
        return match ($type) {
            'workflow', 'step', 'tool' => self::str($data, 'name'),
            'tool-result' => self::str($data, 'name') . (self::bool($data, 'is_error') ? ' [error]' : '') . '  ' . self::oneLine(self::str($data, 'text'), 60),
            'ai' => self::str($data, 'role') . ' (' . self::str($data, 'model') . ')',
            'turn' => '#' . self::str($data, 'number'),
            'prompt' => self::oneLine(self::str($data, 'text')),
            'reply' => self::reply($data),
            'note' => trim(self::str($data, 'action') . ' ' . self::str($data, 'message')),
            'artifact' => self::str($data, 'label') . ' (' . self::str($data, 'kind') . ')  ' . self::oneLine(self::str($data, 'value'), 60),
            default => '',
        };
    }

    /**
     * Tokens, then any requested tool calls, then the clipped reply text — the rich live form.
     *
     * @param array<string, mixed> $data
     */
    private static function reply(array $data): string
    {
        $usage = $data['usage'] ?? null;
        $tokens = \is_array($usage) ? '[' . self::str($usage, 'in') . '/' . self::str($usage, 'out') . ' tok]' : '';

        $names = [];
        $calls = $data['tool_calls'] ?? [];
        if (\is_array($calls)) {
            foreach ($calls as $call) {
                if (\is_array($call) && isset($call['name']) && \is_scalar($call['name'])) {
                    $names[] = (string) $call['name'];
                }
            }
        }
        $tools = $names === [] ? '' : ' →(' . implode(', ', $names) . ')';

        return trim($tokens . $tools . ' ' . self::oneLine(self::str($data, 'text'), 60));
    }

    /** Collapse whitespace and clip to a width (was Summarize::oneLine + the reader's clip, unified). */
    private static function oneLine(string $text, int $width = 80): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > $width ? mb_substr($text, 0, $width) . '…' : $text;
    }

    /** @param array<array-key, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    /** @param array<array-key, mixed> $data */
    private static function bool(array $data, string $key): bool
    {
        return (bool) ($data[$key] ?? false);
    }
}
