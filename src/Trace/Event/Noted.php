<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\TraceEventInterface;

/** A free-form note a workflow logged about what it was doing (the old `log()`). */
final readonly class Noted implements TraceEventInterface
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $action,
        public string $message = '',
        public array $context = [],
    ) {
    }

    public function type(): string
    {
        return 'note';
    }

    public function toArray(): array
    {
        return ['action' => $this->action, 'message' => $this->message, 'context' => $this->context];
    }

    public function summary(): string
    {
        return trim($this->action . ' ' . $this->message);
    }
}
