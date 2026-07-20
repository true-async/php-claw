<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Exceptions\ContextLengthException;
use Claw\Exceptions\WorkflowFinished;
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
     * How often (in model turns) the loop pauses to ask the ask channel whether to keep going — a
     * recurring checkpoint, NOT a hard cap. A step can legitimately take many turns (running a test
     * gate, retrying), so the loop runs on; but a runaway loop (the same failing command over and over)
     * should not churn forever. Every interval the loop checks in; with no one to ask, or any answer
     * other than "continue", it stops and returns what it has. The only hard bound is the Budget.
     */
    private const int TURN_CHECKPOINT_INTERVAL = 50;

    /**
     * No-progress circuit-breaker: if the SAME tool returns the SAME result this many times over the
     * exchange, the model is not making progress (it keeps re-sending a call that lands identically — a
     * repeated error, OR a useless success like "no such tool"/"nothing found"). Identical (tool, result)
     * is the signal: DIFFERENT results each time means the model is iterating and is left alone; the same
     * result repeating is a stuck loop. Small on purpose — three identical rounds is already "it isn't
     * learning". On trip the loop ESCALATES to the ask channel (supervisor/human) once, then stops.
     */
    private const int STUCK_TOOL_REPEAT = 3;

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
        private readonly ?TokenPricing $pricing = null,
    ) {
    }

    public function run(array $history): TurnResult
    {
        $totalInput  = 0;
        $totalOutput = 0;
        $totalCached = 0;
        $turnNo      = 0;
        $lastText    = null;
        $pricing     = $this->pricing ?? TokenPricing::shared();

        /** @var array<string, int> identical (tool, result) → how many times it has repeated this exchange */
        $toolRepeats = [];
        $stuckEscalated = false;   // escalate a wedged tool to the channel at most once, then stop

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

            // Every checkpoint interval, pause and ask whether to keep going — a runaway loop should not
            // churn forever. With no one to ask, stop here and return the last answer we have.
            if ($turnNo > 0 && $turnNo % self::TURN_CHECKPOINT_INTERVAL === 0 && !$this->keepGoing($turnNo)) {
                return new TurnResult($history, $lastText, new Usage($totalInput, $totalOutput, $totalCached));
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
            $totalCached += $response->usage->cachedTokens;
            $lastText = $response->text;

            $this->tracer?->reply(
                $response->text ?? '',
                array_map(static fn (ToolUseBlock $c): array => ['name' => $c->name, 'input' => $c->input], $response->toolCalls),
                $response->usage->inputTokens,
                $response->usage->outputTokens,
                $response->usage->cachedTokens,
                $pricing->normalized(
                    $response->usage->inputTokens,
                    $response->usage->cachedTokens,
                    $response->usage->outputTokens,
                    $this->model,
                ),
                $pricing->costMicros(
                    $response->usage->inputTokens,
                    $response->usage->cachedTokens,
                    $response->usage->outputTokens,
                    $this->model,
                ),
            );

            $history[] = new Message(Role::Assistant, $response->content);

            // Charge this round-trip to the turn budget; if it — or the run total it bubbles up to —
            // is spent, stop the exchange here and return what we have, not another round-trip.
            if ($this->turnBudget !== null) {
                $this->turnBudget->spend($response->usage->inputTokens + $response->usage->outputTokens);

                if ($this->turnBudget->isExhausted()) {
                    // Stopping here leaves the turn half-done: the model asked for tools that will now
                    // never run. This history is CONTINUED later — a critic re-run, a handoff — and a
                    // tool_use with no matching tool_result is rejected outright by both backends, so
                    // every requested call is answered before we go. The same closing the `done` path
                    // does below, for the same reason; only this exit was missing it, and a real run
                    // died on the 400 two steps later.
                    if ($response->toolCalls !== []) {
                        $unanswered = array_map(
                            static fn (ToolUseBlock $call): ToolResultBlock => new ToolResultBlock(
                                $call->id,
                                'not run: the turn budget was spent before this call',
                                true,
                            ),
                            $response->toolCalls,
                        );
                        $history[] = new Message(Role::User, $unanswered);
                    }

                    $this->tracer?->exit($turn);

                    return new TurnResult($history, $response->text, new Usage($totalInput, $totalOutput, $totalCached));
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

                return new TurnResult($history, $response->text, new Usage($totalInput, $totalOutput, $totalCached));
            }

            $results = [];
            $stuckTool = null;

            foreach ($response->toolCalls as $call) {
                $this->tracer?->toolCall($call->name, $call->input);

                try {
                    $result = $this->executor->call(new ToolCall($call->id, $call->name, $call->input));
                } catch (WorkflowFinished $signal) {
                    // `done` fired. The exchange stops here, but the conversation that led to it is the
                    // only record of what the worker actually did, and this loop is the only place that
                    // holds it — the step's critic reviews it. Attach it before the signal travels on.
                    //
                    // Every requested call still gets a result block first: this history is CONTINUED
                    // later (a critic re-run, the handoff), and a tool_use with no matching tool_result
                    // is rejected by both backends.
                    foreach (\array_slice($response->toolCalls, \count($results)) as $unanswered) {
                        $results[] = new ToolResultBlock($unanswered->id, 'the task was declared finished', false);
                    }
                    $history[] = new Message(Role::User, $results);
                    $this->tracer?->toolResult($call->name, 'the task was declared finished', false);
                    $this->tracer?->exit($turn);

                    throw new WorkflowFinished($signal->summary, $history);
                }

                $this->tracer?->toolResult($call->name, $result->content, $result->isError);
                $results[] = $result;

                // No-progress guard: the SAME tool returning the SAME result — an error OR a useless
                // success ("no such tool", "nothing found") — over and over is a wedged model, not work.
                // (DIFFERENT results each time = the model iterating, and is left alone.)
                $key = $call->name . "\0" . $result->content;
                $toolRepeats[$key] = ($toolRepeats[$key] ?? 0) + 1;

                if ($toolRepeats[$key] >= self::STUCK_TOOL_REPEAT) {
                    $stuckTool = $call->name;
                }
            }

            $history[] = new Message(Role::User, $results);
            $this->tracer?->exit($turn);

            // Wedged on a tool (same result STUCK_TOOL_REPEAT times). Do NOT just churn or silently stop:
            // ESCALATE to the channel (supervisor/human) once — guidance resumes the exchange with a fresh
            // slate; no one to ask, no answer, or a recurrence -> stop. The budget is still the hard cap.
            if ($stuckTool !== null) {
                if (!$stuckEscalated && $this->ask !== null) {
                    $stuckEscalated = true;
                    $steer = $this->ask->reply(
                        "This step keeps calling '{$stuckTool}' and getting the same result, making no "
                        . 'progress — it is stuck. Reply with guidance to try a different approach, or '
                        . 'anything else to stop here.',
                    );

                    if ($steer !== null) {
                        $history[] = Message::userText($steer);
                        $toolRepeats = [];   // a fresh slate after the steer

                        continue;
                    }
                }

                return new TurnResult($history, $lastText, new Usage($totalInput, $totalOutput, $totalCached));
            }
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
     * else null (a normal final answer). The marker is stripped; a bare marker with no question
     * falls back to a real prompt rather than echoing the literal "[question]" at the channel.
     */
    private function extractQuestion(string $text): ?string
    {
        if (!str_contains($text, self::QUESTION_MARKER)) {
            return null;
        }

        $question = trim(str_replace(self::QUESTION_MARKER, '', $text));

        return $question === '' ? 'The worker paused for input but gave no question.' : $question;
    }
}
