<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * Thrown on an unrecoverable HTTP transport failure (network error after retries,
 * or an undecodable body). HTTP status codes are returned, not thrown.
 */
final class HttpException extends ClawException
{
}
