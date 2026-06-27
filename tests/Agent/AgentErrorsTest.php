<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\AgentErrors;
use Claw\Exceptions\AgentException;
use Claw\Exceptions\AuthException;
use Claw\Exceptions\BadRequestException;
use Claw\Exceptions\ContextLengthException;
use Claw\Exceptions\OverloadedException;
use Claw\Exceptions\QuotaExceededException;
use Claw\Exceptions\RateLimitException;
use Claw\Exceptions\ServerErrorException;
use Testo\Assert;
use Testo\Test;

final class AgentErrorsTest
{
    #[Test]
    public function classifiesByProviderErrorType(): void
    {
        // error.type wins over the status code
        Assert::same(AgentErrors::classify(500, 'overloaded_error', 'x', 0)::class, OverloadedException::class);
        Assert::same(AgentErrors::classify(200, 'authentication_error', 'x', 0)::class, AuthException::class);
        Assert::same(AgentErrors::classify(200, 'invalid_request_error', 'x', 0)::class, BadRequestException::class);

        $rateLimited = AgentErrors::classify(429, 'rate_limit_error', 'x', 2000);
        Assert::same($rateLimited::class, RateLimitException::class);

        if ($rateLimited instanceof RateLimitException) {
            Assert::same($rateLimited->retryAfterMs, 2000);
        }
    }

    #[Test]
    public function classifiesByStatusWhenNoErrorType(): void
    {
        Assert::same(AgentErrors::classify(401, null, 'x', 0)::class, AuthException::class);
        Assert::same(AgentErrors::classify(429, null, 'x', 0)::class, RateLimitException::class);
        Assert::same(AgentErrors::classify(529, null, 'x', 0)::class, OverloadedException::class);
        Assert::same(AgentErrors::classify(503, null, 'x', 0)::class, ServerErrorException::class);
        Assert::same(AgentErrors::classify(400, null, 'x', 0)::class, BadRequestException::class);
        Assert::same(AgentErrors::classify(418, null, 'x', 0)::class, AgentException::class);   // unknown -> base
    }

    #[Test]
    public function distinguishesQuotaExhaustionFromRateLimit(): void
    {
        Assert::same(AgentErrors::classify(402, null, 'x', 0)::class, QuotaExceededException::class);
        Assert::same(AgentErrors::classify(429, 'insufficient_quota', 'x', 0)::class, QuotaExceededException::class);
        Assert::same(AgentErrors::classify(200, 'insufficient_balance', 'x', 0)::class, QuotaExceededException::class);
    }

    #[Test]
    public function detectsContextOverflowByMessage(): void
    {
        // arrives as a generic 400 — recognized by the message, not the status
        Assert::same(
            AgentErrors::classify(400, 'invalid_request_error', 'This model has a maximum context length of 128000 tokens', 0)::class,
            ContextLengthException::class,
        );
        Assert::same(AgentErrors::classify(400, null, 'prompt is too long', 0)::class, ContextLengthException::class);
    }
}
