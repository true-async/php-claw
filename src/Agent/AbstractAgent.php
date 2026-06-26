<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Exceptions\AgentException;
use Claw\Exceptions\HttpException;
use Claw\Http\HttpClientInterface;

/**
 * Base agent: one model round-trip is `attempt()` (provider-specific); `send()`
 * wraps it with cause-aware retry. Retry lives where the call happens — no
 * decorator. The policy decides by the typed exception the provider produced.
 */
abstract class AbstractAgent implements AgentInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly AgentRetryPolicyInterface $retryPolicy = new BackoffAgentRetryPolicy(),
    ) {
    }

    final public function send(AgentRequest $request): AgentResponse
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attempt($request);
            } catch (AgentException $e) {
                $delay = $this->retryPolicy->delayBeforeRetry($e, $attempt);
                if ($delay === null) {
                    throw $e;
                }

                \Async\delay($delay);   // suspends this coroutine, blocks nothing
            }
        }
    }

    /**
     * One model round-trip. Throws a typed AgentException on failure.
     */
    abstract protected function attempt(AgentRequest $request): AgentResponse;

    /**
     * Shared transport for the concrete agents: POST the encoded body and return
     * the decoded JSON. Every failure exits as a typed AgentException — transport
     * faults and a malformed-but-2xx body (json() throws HttpException) both map
     * via AgentErrors, so nothing leaks past send()'s AgentException-only retry.
     *
     * @param array<int, string> $headers raw header lines ("Name: value")
     *
     * @return array<string, mixed>
     */
    protected function postJson(string $url, string $body, array $headers): array
    {
        try {
            $response = $this->http->post($url, $body, $headers);
            if (!$response->isOk()) {
                throw AgentErrors::fromResponse($response);
            }

            return $response->json();
        } catch (HttpException $e) {
            throw AgentErrors::fromTransport($e);
        }
    }
}
