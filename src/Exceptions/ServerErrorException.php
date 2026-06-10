<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * A 5xx server-side error from the provider. Transient.
 */
final class ServerErrorException extends AgentException implements TransientErrorInterface
{
}
