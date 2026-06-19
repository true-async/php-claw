<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * Authentication or permission failure (401 / 403): wrong key or no access.
 * Permanent — retrying will not help.
 */
final class AuthException extends AgentException
{
}
