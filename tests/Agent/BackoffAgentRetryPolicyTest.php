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

        Assert::same($policy->delayBeforeRetry(new RateLimitException('x', 5_000), 1), 5_000);
        Assert::null($policy->delayBeforeRetry(new RateLimitException('x', 120_000), 1));   // too far -> give up
    }

    #[Test]
    public function stopsAfterMaxAttempts(): void
    {
        $policy = new BackoffAgentRetryPolicy(maxAttempts: 2);

        Assert::true($policy->delayBeforeRetry(new ServerErrorException('x'), 1) > 0);
        Assert::null($policy->delayBeforeRetry(new ServerErrorException('x'), 2));
    }
}
