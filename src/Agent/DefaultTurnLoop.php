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
    /** The marker a worker ends a turn with to ask the ask channel instead of finishing. */
    private const string QUESTION_MARKER = '[question]';

    /**
     * Soft cap on model turns in ONE exchange. A step can legitimately take many turns (running a test
     * gate, retrying), so this is generous — but a runaway loop (the same failing command over and
     * over) should not churn forever. Every MAX_TURNS turns the loop pauses to ask the ask channel
     * whether to keep going; with no one to ask it stops and returns what it has, rather than run away.
     */
    private const int MAX_TURNS = 50;

    /** Appended to the system prompt when an ask channel is present, teaching that marker. */
    private const string ASK_INSTRUCTION = "\n\nIf you need input or a decision from a person to "
        . 'proceed, do not guess: end your turn with no tool call and a line beginning "[question]" '
        . 'followed by your question. You will receive the answer and continue.';

    /**
     * @param list<ToolSpec>     $specs      the tools advertised to the model each round-trip
     * @param int                $maxHistory soft cap on history length (0 = no cap); the
     *                                       hard bound is the model's own context window
     * @param ?SpeakerInterface  $ask        who the model reaches when it ends a turn with the
     *                                        [question] marker (a person or an agent); null = the
     *                                        loop stays headless and such a turn is just the answer
     * @param ?Budget            $turnBudget caps this one exchange in tokens/time; when it (or the
     *                                        run total it bubbles to) is spent, the loop stops and
     *                                        returns what it has. null = no cap.
     */
    public function __construct(
        private readonly AgentInterface $agent,
        private readonly ExecutorInterface $executor,
        private readonly string $model,
        private readonly string $system,
        private readonly array $specs = [],
        private readonly int $maxHistory = 0,
        private readonly ?Tracer $tracer = null,
        private readonly ?SpeakerInterface $ask = null,
        private readonly ?Budget $turnBudget = null,
    ) {
    }

    public function run(array $history): TurnResult
    {
        $totalInput  = 0;
        $totalOutput = 0;
        $turnNo      = 0;
        $lastText    = null;

        // With an ask channel present, teach the worker the [question] marker so it can pause for
        // input instead of finishing; without one the system prompt is untouched (headless).
        $system = $this->ask === null ? $this->system : $this->system . self::ASK_INSTRUCTION;

        // Loops until the model returns a final answer (no tool_use). The bound is
        // memory: the model's context window (the API rejects an oversized history ->
        // ContextLengthException), plus an optional soft cap.
        while (true) {
            if ($this->maxHistory > 0 && \count($history) >= $this->maxHistory) {
                throw new ContextLengthException("History reached the configured limit of {$this->maxHistory} messages");
            }

            // Every MAX_TURNS turns, pause and ask whether to keep going — a runaway loop should not
            // churn forever. With no one to ask, stop here and return the last answer we have.
            if ($turnNo > 0 && $turnNo % self::MAX_TURNS === 0 && !$this->keepGoing($turnNo)) {
                return new TurnResult($history, $lastText, new Usage($totalInput, $totalOutput));
            }

            // The model is stateless: every call carries the FULL history (system +
            // all messages + tool results). The repeated prefix is cheap via prompt
            // caching; trimming/summarization is a later layer.
            $turnNo++;   // NOT inside the tracer call below: a null tracer would skip the increment
            $turn = $this->tracer?->enterTurn($turnNo, $this->model);

            $response = $this->agent->send(new AgentRequest(
                model: $this->model,
                messages: $history,
                system: $system,
                tools: $this->specs,
            ));

            $totalInput  += $response->usage->inputTokens;
            $totalOutput += $response->usage->outputTokens;
            $lastText = $response->text;

            $this->tracer?->reply(
                $response->text ?? '',
                array_map(static fn (ToolUseBlock $c): array => ['name' => $c->name, 'input' => $c->input], $response->toolCalls),
                $response->usage->inputTokens,
                $response->usage->outputTokens,
            );

            $history[] = new Message(Role::Assistant, $response->content);

            // Charge this round-trip to the turn budget; if it — or the run total it bubbles up to —
            // is spent, stop the exchange here and return what we have, not another round-trip.
            if ($this->turnBudget !== null) {
                $this->turnBudget->spend($response->usage->inputTokens + $response->usage->outputTokens);
                if ($this->turnBudget->isExhausted()) {
                    $this->tracer?->exit($turn);

                    return new TurnResult($history, $response->text, new Usage($totalInput, $totalOutput));
                }
            }

            // Terminate on the dispatchable subset, not the advertised intent. A
            // response can carry a tool_use stop reason yet no parseable tool calls
            // (a truncated or malformed turn from either agent backend). Branching on
            // toolCalls — rather than wantsToolUse() — ends the turn with whatever
            // text is present instead of looping forever on an empty tool batch
            // (which would append empty user messages and burn round-trips).
            if ($response->toolCalls === []) {
                $this->tracer?->exit($turn);

                // No tool_use: the model is either done, or — with an ask channel — pausing to ask.
                // A turn carrying the [question] marker is the latter: route it to the channel, inject
                // the answer as the next user turn, and continue the same loop (context stays whole).
                if ($this->ask !== null) {
                    $question = $this->extractQuestion($response->text ?? '');
                    if ($question !== null) {
                        $answer = $this->ask->reply($question);
                        if ($answer !== null) {                       // null = the chain passed up, no one answered
                            $history[] = Message::userText($answer);

                            continue;
                        }
                    }
                }

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

    /**
     * The turn-cap checkpoint: ask the ask channel whether to keep going after a long run of turns.
     * True = continue. With no channel, or no answer, or anything other than "continue", stop — a
     * headless run must not loop forever. This is the soft backstop for a model stuck repeating a
     * failing command; the budget is the hard one.
     */
    private function keepGoing(int $turnNo): bool
    {
        if (!$this->ask instanceof SpeakerInterface) {
            return false;   // headless: stop at the cap rather than run away
        }

        $reply = $this->ask->reply(
            "This step has run {$turnNo} model turns without finishing (tests, retries, and the like). "
            . "Reply 'continue' to keep going, or anything else to stop here.",
        );

        return $reply !== null && str_starts_with(strtolower(trim($reply)), 'continue');
    }

    /**
     * The model's question for the ask channel when its turn carries the {@see QUESTION_MARKER},
     * else null (a normal final answer). The marker is stripped; an empty remainder falls back to
     * the whole text, so a bare marker still asks something.
     */
    private function extractQuestion(string $text): ?string
    {
        if (!str_contains($text, self::QUESTION_MARKER)) {
            return null;
        }

        $question = trim(str_replace(self::QUESTION_MARKER, '', $text));

        return $question === '' ? trim($text) : $question;
    }
}
