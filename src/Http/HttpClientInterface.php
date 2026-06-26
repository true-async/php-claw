<?php

declare(strict_types=1);

namespace Claw\Http;

/**
 * Thin, single-shot HTTP transport: one request, one response, no retries.
 * Cause-aware retry lives in the agent layer (AbstractAgent::send via
 * AgentRetryPolicyInterface), so an implementation must not retry on its own.
 * Application-level errors (4xx/5xx) are data — returned via the status, not
 * thrown; only a transport failure throws.
 */
interface HttpClientInterface
{
    /**
     * @param array<int, string> $headers raw header lines ("Name: value")
     *
     * @throws \Claw\Exceptions\HttpException on transport failure
     */
    public function post(string $url, string $body, array $headers = []): HttpResponse;

    /**
     * @param array<int, string> $headers raw header lines ("Name: value")
     *
     * @throws \Claw\Exceptions\HttpException on transport failure
     */
    public function get(string $url, array $headers = []): HttpResponse;
}
