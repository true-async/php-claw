<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\BackoffAgentRetryPolicy;
use Claw\Exceptions\AgentException;
use Claw\Exceptions\AuthException;
use Claw\Exceptions\OverloadedException;
use Claw\Exceptions\QuotaExceededException;
use Claw\Exceptions\RateLimitException;
use Claw\Exceptions\ServerErrorException;
use Claw\Exceptions\TransportException;
use Testo\Assert;
use Testo\Test;

final class BackoffAgentRetryPolicyTest
{
    #[Test]
    public function retriesTransientErrors(): void
    {
        $policy = new BackoffAgentRetryPolicy();

        Assert::true($policy->delayBeforeRetry(new TransportException('x'), 1) > 0);
        Assert::true($policy->delayBeforeRetry(new ServerErrorException('x'), 1) > 0);
        Assert::true($policy->delayBeforeRetry(new OverloadedException('x'), 1) > 0);
    }

    #[Test]
    public function doesNotRetryPermanentErrors(): void
    {
        $policy = new BackoffAgentRetryPolicy();

        Assert::null($policy->delayBeforeRetry(new AuthException('x'), 1));
        Assert::null($policy->delayBeforeRetry(new QuotaExceededException('x'), 1));   // out of credits, not transient
        Assert::null($policy->delayBeforeRetry(new AgentException('x'), 1));   // unknown is not transient
    }

    #[Test]
    public function honorsRateLimitResumeTimeWithinCeiling(): void
    {
        $policy = new BackoffAgentRetryPolicy(rateLimitWaitCeilingMs: 60_000);

        // At least the server's resume time, plus a bounded pad so parallel
        // runs do not retry at the same instant.
        $delay = $policy->delayBeforeRetry(new RateLimitException('x', 5_000), 1);
        Assert::true($delay >= 5_000);
        Assert::true($delay <= 5_000 + 500 + 1_250);

        Assert::null($policy->delayBeforeRetry(new RateLimitException('x', 120_000), 1));   // too far -> give up
    }

    #[Test]
    public function stopsAfterMaxAttempts(): void
    {
        $policy = new BackoffAgentRetryPolicy(maxAttempts: 2);

        Assert::true($policy->delayBeforeRetry(new ServerErrorException('x'), 1) > 0);
        Assert::null($policy->delayBeforeRetry(new ServerErrorException('x'), 2));
    }

    #[Test]
    public function rateLimitGetsItsOwnLargerAttemptBudget(): void
    {
        $policy = new BackoffAgentRetryPolicy(maxAttempts: 4, rateLimitMaxAttempts: 8);

        // A TPM 429 under parallel-run contention recurs a few times even when
        // each wait honors the server's delay — it must outlive maxAttempts.
        Assert::true($policy->delayBeforeRetry(new RateLimitException('x', 1_434), 4) > 0);
        Assert::true($policy->delayBeforeRetry(new RateLimitException('x', 1_434), 7) > 0);
        Assert::null($policy->delayBeforeRetry(new RateLimitException('x', 1_434), 8));

        // Other transient errors keep the small budget.
        Assert::null($policy->delayBeforeRetry(new ServerErrorException('x'), 4));
    }

    #[Test]
    public function rateLimitWithoutResumeTimeStillBacksOff(): void
    {
        $policy = new BackoffAgentRetryPolicy();

        Assert::true($policy->delayBeforeRetry(new RateLimitException('x'), 1) > 0);
    }
}
