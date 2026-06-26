<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * The live view: each record as one line, indented by depth, so the hierarchy is visible as the run
 * happens — ▶ opens a span, ◀ closes it, · is a point event, followed by the event's own summary.
 *
 * Density is a threshold, not a type-switch: the sink prints an event only when its {@see Level} is
 * at least $threshold (default {@see Level::Info}). The importance lives on each event, so adding a
 * kind never touches this filter.
 */
final class ConsoleTraceSink implements TraceSinkInterface
{
    /** Whether to tint each line by event type — on for a real terminal, off for a pipe/file/NO_COLOR. */
    private readonly bool $color;

    /**
     * @param resource  $stream
     * @param ?bool     $color force colour on/off; null = auto-detect from the stream (a TTY, NO_COLOR unset)
     */
    public function __construct(
        private $stream,
        private readonly Level $threshold = Level::Info,
        ?bool $color = null,
    ) {
        $this->color = $color ?? (\is_resource($stream) && stream_isatty($stream) && getenv('NO_COLOR') === false);
    }

    public function write(TraceRecordInterface $record): void
    {
        if (!\is_resource($this->stream)) {
            return;
        }

        $event = $record->event();
        if (!$event->level->passes($this->threshold)) {
            return;
        }

        $glyph = match ($record->phase()) {
            'enter' => '▶',
            'exit' => '◀',
            default => '·',
        };

        $head = $glyph . ' ' . trim($event->type . ' ' . TraceFormat::summary($event->type, $event->data));

        fwrite($this->stream, str_repeat('  ', $record->depth()) . TraceFormat::paint($event->type, $head, $this->color) . "\n");
    }
}
