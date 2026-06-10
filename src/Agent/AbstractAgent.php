<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Exceptions\AgentException;

/**
 * Base agent: one model round-trip is `attempt()` (provider-specific); `send()`
 * wraps it with cause-aware retry. Retry lives where the call happens — no
 * decorator. The policy decides by the typed exception the provider produced.
 */
abstract class AbstractAgent implements AgentInterface
{
    public function __construct(
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
}
