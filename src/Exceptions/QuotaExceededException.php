<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * Token quota / credit balance exhausted (HTTP 402, insufficient_quota /
 * insufficient_balance / billing_error). Unlike a rate limit this is NOT
 * transient — retrying soon will not help; it needs a top-up or a billing reset.
 * Carries the reset time (`retryAfterMs`, 0 if unknown) for the bot to report.
 */
final class QuotaExceededException extends AgentException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterMs = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
