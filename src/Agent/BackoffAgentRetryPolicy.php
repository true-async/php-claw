<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Exceptions\AgentException;
use Claw\Exceptions\RateLimitException;
use Claw\Exceptions\TransientErrorInterface;

/**
 * Retry transient errors (transport / overloaded / server / rate limit); never
 * permanent ones (auth, bad request). For a rate limit, honor the resume time
 * if it is near, otherwise give up so the bot can tell the user when to retry.
 */
final class BackoffAgentRetryPolicy implements AgentRetryPolicyInterface
{
    public function __construct(
        private readonly int $maxAttempts = 4,
        private readonly int $baseDelayMs = 500,
        private readonly int $maxDelayMs = 30_000,
        private readonly int $rateLimitWaitCeilingMs = 60_000,
    ) {
    }

    public function delayBeforeRetry(AgentException $error, int $attempt): ?int
    {
        if ($attempt >= $this->maxAttempts) {
            return null;   // exhausted
        }

        if (!($error instanceof TransientErrorInterface)) {
            return null;   // permanent (auth, bad request, unknown)
        }

        if ($error instanceof RateLimitException && $error->retryAfterMs > 0) {
            // Resume time too far away: do not block — let the caller report it.
            return $error->retryAfterMs > $this->rateLimitWaitCeilingMs ? null : $error->retryAfterMs;
        }

        $delay = min($this->baseDelayMs * (2 ** ($attempt - 1)), $this->maxDelayMs);

        return $delay + random_int(0, intdiv($delay, 4));
    }
}
