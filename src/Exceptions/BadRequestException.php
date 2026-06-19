<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * The request was rejected as invalid (400 / 404 / 413 / 422): a bug on our side
 * or a bad model id. Permanent — retrying will not help.
 */
final class BadRequestException extends AgentException
{
}
