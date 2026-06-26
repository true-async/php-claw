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
    /**
     * The ANSI SGR code each event type is painted with, so the tree reads at a glance — structure
     * (workflow/step) stands out, the model's own words (prompt/reply) are tinted apart from the
     * machinery (tool/turn), and the handoff — the context carried between steps — is highlighted.
     * A type absent here is left uncoloured.
     */
    private const array COLOR = [
        'workflow'    => '1;35',   // bold magenta — the run
        'step'        => '1;36',   // bold cyan — a phase
        'ai'          => '1;34',   // bold blue — a model exchange
        'prompt'      => '33',     // yellow — what we asked
        'reply'       => '32',     // green — what the model said
        'tool'        => '35',     // magenta — a tool call
        'tool-result' => '90',     // grey — its result
        'turn'        => '90',     // grey — turn marker
        'note'        => '90',     // grey — a logged note
        'artifact'    => '36',     // cyan — a produced output
        'handoff'     => '1;33',   // bold yellow — context carried to the next step
    ];

    /**
     * The glyph + type + summary head for one record — ▶ opens a span, ◀ closes it, · is a point
     * event. The shared one-line body the live {@see ConsoleTraceSink} and the history
     * {@see TraceReader} both indent and {@see paint()}, so the two can never drift.
     *
     * @param array<string, mixed> $data
     */
    public static function line(string $phase, string $type, array $data): string
    {
        $glyph = match ($phase) {
            'enter' => '▶',
            'exit' => '◀',
            default => '·',
        };

        return $glyph . ' ' . trim($type . ' ' . self::summary($type, $data));
    }

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
            'handoff' => self::oneLine(self::str($data, 'text'), 70),
            default => '',
        };
    }

    /**
     * Wrap a rendered line in the ANSI colour chosen for its event type, or return it untouched when
     * $color is off (a pipe, a file, NO_COLOR) or the type has no colour. The one place colour is
     * applied, so live and replayed trees tint identically.
     */
    public static function paint(string $type, string $text, bool $color): string
    {
        if (!$color) {
            return $text;
        }

        $code = self::COLOR[$type] ?? '';

        return $code === '' ? $text : "\e[{$code}m{$text}\e[0m";
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

    /**
     * Read a field from the data bag as a string, coercing scalars and mapping anything else to ''.
     * Shared with {@see TraceReader} so the fresh-event and row-read-back paths coerce identically.
     *
     * @param array<array-key, mixed> $data
     */
    public static function str(array $data, string $key): string
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
