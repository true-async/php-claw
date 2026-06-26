<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Agent\AbstractAgent;
use Claw\Agent\AgentRequest;
use Claw\Agent\AgentResponse;
use Claw\Agent\AgentRetryPolicyInterface;
use Claw\Http\HttpResponse;

/**
 * A concrete AbstractAgent whose single attempt() returns preset outcomes
 * (responses or thrown exceptions) in order, counting attempts — lets the
 * base retry loop be tested without a real model.
 */
final class ScriptedRetryAgent extends AbstractAgent
{
    public int $attempts = 0;

    /** @var array<array-key, AgentResponse|\Throwable> */
    private array $outcomes;

    /**
     * @param array<array-key, AgentResponse|\Throwable> $outcomes
     */
    public function __construct(array $outcomes, AgentRetryPolicyInterface $policy)
    {
        $this->outcomes = $outcomes;

        // attempt() is overridden and never touches transport, so a throwaway
        // client satisfies the base contract without a network.
        parent::__construct(new FakeHttpClient(new HttpResponse(200, '{}')), $policy);
    }

    protected function attempt(AgentRequest $request): AgentResponse
    {
        $this->attempts++;

        $next = array_shift($this->outcomes);
        if ($next === null) {
            throw new \RuntimeException('ScriptedRetryAgent: no more outcomes');
        }

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
