<?php

declare(strict_types=1);

namespace Claw\Exec;

use Claw\Agent\ToolResultBlock;
use Claw\Tool\ToolCall;

/**
 * One transparent entry point for running a tool. The turn loop never touches a
 * tool directly — everything (security, audit, …) is a middleware behind this.
 */
interface ExecutorInterface
{
    public function call(ToolCall $call): ToolResultBlock;
}
