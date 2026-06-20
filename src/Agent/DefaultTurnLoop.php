<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Exceptions\ContextLengthException;
use Claw\Exec\ExecutorInterface;
use Claw\Tool\ToolCall;
use Claw\Trace\Tracer;

/**
 * The default ReAct turn loop: call the model with the full history, run any
 * requested tools through the executor, append the results, and repeat until the
 * model returns a final answer (no more tool_use).
 *
 * This is a headless component — it owns no UI and no conversation. It takes a
 * history and gives back a {@see TurnResult} (final answer, the updated history,
 * accumulated token usage). Progress, cancellation, and human-facing messaging are
 * the caller's concern: a workflow step traces around it, and cancellation simply
 * propagates as an exception (TrueAsync structured concurrency unwinds the loop).
 *
 * Configuration (model, system prompt, tool specs, executor) is fixed for the loop's
 * lifetime; only the history flows through run(). This is the seam a workflow step
 * runs on: a step is one or more turns.
 */
final class DefaultTurnLoop implements TurnLoopInterface
{
    /**
     * @param list<ToolSpec> $specs the tools advertised to the model each round-trip
     * @param int            $maxHistory soft cap on history length (0 = no cap); the
     *                                   hard bound is the model's own context window
     */
    public function __construct(
        private readonly AgentInterface $agent,
        private readonly ExecutorInterface $executor,
        private readonly string $model,
        private readonly string $system,
        private readonly array $specs = [],
        private readonly int $maxHistory = 0,
        private readonly ?Tracer $tracer = null,
    ) {
    }

    public function run(array $history): TurnResult
    {
        $totalInput  = 0;
        $totalOutput = 0;
        $turnNo      = 0;

        // Loops until the model returns a final answer (no tool_use). The bound is
        // memory: the model's context window (the API rejects an oversized history ->
        // ContextLengthException), plus an optional soft cap.
        while (true) {
            if ($this->maxHistory > 0 && \count($history) >= $this->maxHistory) {
                throw new ContextLengthException("History reached the configured limit of {$this->maxHistory} messages");
            }

            // The model is stateless: every call carries the FULL history (system +
            // all messages + tool results). The repeated prefix is cheap via prompt
            // caching; trimming/summarization is a later layer.
            $turn = $this->tracer?->enterTurn(++$turnNo, $this->model);

            $response = $this->agent->send(new AgentRequest(
                model: $this->model,
                messages: $history,
                system: $this->system,
                tools: $this->specs,
            ));

            $totalInput  += $response->usage->inputTokens;
            $totalOutput += $response->usage->outputTokens;

            $this->tracer?->reply(
                $response->text ?? '',
                array_map(static fn (ToolUseBlock $c): array => ['name' => $c->name, 'input' => $c->input], $response->toolCalls),
                $response->usage->inputTokens,
                $response->usage->outputTokens,
            );

            $history[] = new Message(Role::Assistant, $response->content);

            // Terminate on the dispatchable subset, not the advertised intent. A
            // response can carry a tool_use stop reason yet no parseable tool calls
            // (a truncated or malformed turn from either agent backend). Branching on
            // toolCalls — rather than wantsToolUse() — ends the turn with whatever
            // text is present instead of looping forever on an empty tool batch
            // (which would append empty user messages and burn round-trips).
            if ($response->toolCalls === []) {
                $this->tracer?->exit($turn);

                return new TurnResult($history, $response->text, new Usage($totalInput, $totalOutput));
            }

            $results = [];
            foreach ($response->toolCalls as $call) {
                $this->tracer?->toolCall($call->name, $call->input);
                $result = $this->executor->call(new ToolCall($call->id, $call->name, $call->input));
                $this->tracer?->toolResult($call->name, $result->content, $result->isError);
                $results[] = $result;
            }

            $history[] = new Message(Role::User, $results);
            $this->tracer?->exit($turn);
        }
    }
}
