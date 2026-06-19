<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * Thrown when a tool fails to execute. The Executor turns this into an error
 * tool_result fed back to the model.
 */
final class ToolException extends ClawException
{
}
