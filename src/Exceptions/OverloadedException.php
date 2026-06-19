<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * The provider is temporarily overloaded (e.g. 529 / overloaded_error). Transient.
 */
final class OverloadedException extends AgentException implements TransientErrorInterface
{
}
