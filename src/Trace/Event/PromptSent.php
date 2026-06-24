<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\Level;
use Claw\Trace\TraceEventInterface;

/** The prompt an ai() call built, with the tool palette it was given. */
final readonly class PromptSent implements TraceEventInterface
{
    /** @param list<string> $tools */
    public function __construct(
        public string $text,
        public array $tools = [],
    ) {
    }

    public function type(): string
    {
        return 'prompt';
    }

    public function level(): Level
    {
        return Level::Debug;
    }

    public function toArray(): array
    {
        return ['text' => $this->text, 'tools' => $this->tools];
    }

    public function summary(): string
    {
        return Summarize::oneLine($this->text);
    }
}
