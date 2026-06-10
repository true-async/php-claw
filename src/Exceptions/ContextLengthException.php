<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * The conversation no longer fits the model's context window (or a configured
 * history limit). Permanent for this request — retrying the same oversized
 * payload will not help; the history must be trimmed or the conversation reset.
 */
final class ContextLengthException extends AgentException
{
}
