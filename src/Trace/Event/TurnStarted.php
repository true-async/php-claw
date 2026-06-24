<?php

declare(strict_types=1);

namespace Claw\Trace\Event;

use Claw\Trace\Level;
use Claw\Trace\TraceEventInterface;

/** One iteration of the ReAct turn loop began. */
final readonly class TurnStarted implements TraceEventInterface
{
    public function __construct(
        public int $number,
        public string $model,
    ) {
    }

    public function type(): string
    {
        return 'turn';
    }

    public function level(): Level
    {
        return Level::Debug;
    }

    public function toArray(): array
    {
        return ['number' => $this->number, 'model' => $this->model];
    }

    public function summary(): string
    {
        return '#' . $this->number;
    }
}
