<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * The request did not complete (network error, reset, timeout). Wraps the
 * underlying HttpException. Transient.
 */
final class TransportException extends AgentException implements TransientErrorInterface
{
}
