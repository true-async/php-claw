<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * A higher-level way to interact with an AI agent: run a full ReAct turn rather
 * than a single round-trip. Where AgentInterface::send() is one model call, this
 * owns the loop — call the model with the full history, run any requested tools
 * through the executor, append the results, and repeat until the model returns a
 * final answer (no more tool_use).
 *
 * Configuration (model, system prompt, tool specs, executor) is held by the
 * implementation; run() takes only the mutable conversation. This is the seam a
 * workflow step runs on: a step is one or more turns.
 */
interface TurnLoopInterface
{
    /**
     * @param list<Message> $history the conversation so far (carried in full each call)
     *
     * @return TurnResult final answer, full updated history, and token usage
     *
     * @throws \Claw\Exceptions\AgentException
     */
    public function run(array $history): TurnResult;
}
