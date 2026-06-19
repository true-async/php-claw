<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * An LLM backend. The agent only decides the next action — it never executes
 * tools (that is the Executor's job). One call = one model round-trip; the turn
 * loop owns the loop.
 */
interface AgentInterface
{
    /**
     * One model round-trip: send the request, get the model's next move
     * (text or tool_use). May await (async HTTP).
     *
     * @throws \Claw\Exceptions\AgentException
     */
    public function send(AgentRequest $request): AgentResponse;
}
