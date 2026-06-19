<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * Rate limit / token quota exhausted. Carries the time until it resumes
 * (`retryAfterMs`, 0 if unknown) so the retry policy can wait-or-give-up and the
 * bot can tell the user when to try again.
 */
final class RateLimitException extends AgentException implements TransientErrorInterface
{
    public function __construct(
        string $message,
        public readonly int $retryAfterMs = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
