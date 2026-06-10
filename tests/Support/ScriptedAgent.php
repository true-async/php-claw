<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Agent\AgentInterface;
use Claw\Agent\AgentRequest;
use Claw\Agent\AgentResponse;

/**
 * Returns preset outcomes (responses or thrown exceptions) in order and records
 * every request — lets the Session loop be tested without a real model.
 */
final class ScriptedAgent implements AgentInterface
{
    /** @var array<array-key, AgentResponse|\Throwable> */
    private array $outcomes;

    /** @var list<AgentRequest> */
    public array $requests = [];

    public function __construct(AgentResponse|\Throwable ...$outcomes)
    {
        $this->outcomes = $outcomes;
    }

    public function send(AgentRequest $request): AgentResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->outcomes);
        if ($next === null) {
            throw new \RuntimeException('ScriptedAgent: no more outcomes');
        }

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
