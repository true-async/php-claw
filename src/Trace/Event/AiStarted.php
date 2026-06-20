<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\TraceEventInterface;

/** An ai() call began, against a named agent role and a concrete model. */
final readonly class AiStarted implements TraceEventInterface
{
    public function __construct(
        public string $role,
        public string $model,
    ) {
    }

    public function type(): string
    {
        return 'ai';
    }

    public function toArray(): array
    {
        return ['role' => $this->role, 'model' => $this->model];
    }

    public function summary(): string
    {
        return $this->role . ' (' . $this->model . ')';
    }
}
