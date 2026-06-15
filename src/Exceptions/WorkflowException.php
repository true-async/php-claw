<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * Thrown when a workflow fails to load, validate, or execute. The runner turns
 * this into an error result, the same way a ToolException is handled.
 */
final class WorkflowException extends ClawException
{
}
