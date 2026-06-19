<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Agent\ToolResultBlock;
use Claw\Exec\ExecutorInterface;
use Claw\Tool\ToolCall;

/**
 * A fake Executor that records every ToolCall and returns a result from an optional
 * handler — lets a turn loop be tested without the real middleware chain. With no
 * handler it returns a trivial success for each call.
 */
final class RecordingExecutor implements ExecutorInterface
{
    /** @var list<ToolCall> Every call seen, in order. */
    public array $calls = [];

    /** @var \Closure(ToolCall): ToolResultBlock */
    private readonly \Closure $handler;

    /** @param (callable(ToolCall): ToolResultBlock)|null $handler */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler !== null
            ? \Closure::fromCallable($handler)
            : static fn (ToolCall $call): ToolResultBlock => new ToolResultBlock($call->id, 'ok', false);
    }

    public function call(ToolCall $call): ToolResultBlock
    {
        $this->calls[] = $call;

        return ($this->handler)($call);
    }
}
