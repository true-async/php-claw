<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * Base for agent backend failures. Subclasses normalize the cause so callers
 * (retry policy, Session) can react by type. Used directly for unknown errors.
 */
class AgentException extends ClawException
{
}
