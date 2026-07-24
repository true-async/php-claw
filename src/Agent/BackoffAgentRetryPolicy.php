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
        private readonly int $rateLimitMaxAttempts = 8,
    ) {
    }

    public function delayBeforeRetry(AgentException $error, int $attempt): ?int
    {
        if (!($error instanceof TransientErrorInterface)) {
            return null;   // permanent (auth, bad request, unknown)
        }

        // A rate limit gets a larger budget: each wait honors the server's own
        // delay, and under parallel-run contention the limit recurs a few times
        // before capacity frees up — maxAttempts-sized patience kills the run.
        $budget = $error instanceof RateLimitException ? $this->rateLimitMaxAttempts : $this->maxAttempts;

        if ($attempt >= $budget) {
            return null;   // exhausted
        }

        if ($error instanceof RateLimitException && $error->retryAfterMs > 0) {
            // Resume time too far away: do not block — let the caller report it.
            if ($error->retryAfterMs > $this->rateLimitWaitCeilingMs) {
                return null;
            }

            // Pad the server's estimate: it is approximate, and parallel runs
            // told the same resume time must not retry at the same instant.
            return $error->retryAfterMs + random_int(100, 500 + intdiv($error->retryAfterMs, 4));
        }

        $delay = min($this->baseDelayMs * (2 ** ($attempt - 1)), $this->maxDelayMs);

        return $delay + random_int(0, intdiv($delay, 4));
    }
}
