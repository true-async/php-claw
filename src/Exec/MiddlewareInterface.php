<?php

declare(strict_types=1);

namespace Claw\Exec;

use Claw\Agent\ToolResultBlock;
use Claw\Tool\ToolCall;

/**
 * One stage of the executor onion. Wrap $next: inspect, short-circuit (skip it),
 * modify, time, or log. The whole chain is await-able, so a stage may suspend
 * (e.g. Permission awaiting the user) without blocking the thread.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(ToolCall): ToolResultBlock $next
     */
    public function handle(ToolCall $call, callable $next): ToolResultBlock;
}
