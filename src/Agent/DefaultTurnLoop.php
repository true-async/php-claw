<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Exceptions\ContextLengthException;
use Claw\Exec\ExecutorInterface;
use Claw\Tool\BashTool;
use Claw\Tool\Registry;
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
     * is the signal here; a call that fails DIFFERENTLY each time is left to {@see UNCHANGED_FAILURE_LIMIT}, which
     * compares failures by signature rather than by raw bytes. Small on purpose — three
     * identical rounds is already "it isn't learning". On trip the loop ESCALATES to the ask channel
     * (supervisor/human) once, then stops.
     */
    private const int STUCK_TOOL_REPEAT = 3;

    /**
     * How many times in a row the same call may come back with the SAME FAILURE before the exchange
     * stops and asks what is going on.
     *
     * The distinction from {@see STUCK_TOOL_REPEAT} is what "the same" means. That guard compares output
     * byte for byte, which sounds equivalent and is not: a test runner prints its own duration, so two
     * runs of an unchanged suite differ in the `Time:` line and compare unequal forever. That is why five
     * red runs of one command, in a step that fixed nothing, passed under it without a mark.
     *
     * Three, because with a signature rather than raw bytes this really does mean stuck: the third
     * identical failure of the same call is the second one that taught nothing. A DIFFERENT failure
     * resets the count — a step working its way through a parser fails all the way up the climb, and
     * each new error is ground gained, not a strike against it.
     */
    private const int UNCHANGED_FAILURE_LIMIT = 3;

    /**
     * A failure's identity for comparison: its text with the parts that move on their own taken out.
     *
     * Durations and memory figures change between two runs of the very same unchanged code, and that is
     * enough to make raw equality useless exactly where it is needed — over a test suite, which is the
     * commonest thing a stuck step re-runs. Counts and messages are left alone: "9 / 10" becoming
     * "10 / 10" IS the progress this must be able to see.
     */
    private static function signature(string $output): string
    {
        $clean = preg_replace('/\bTime:\s*[^\n,]+(,\s*Memory:\s*[^\n]+)?/i', '', $output) ?? $output;
        $clean = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:ms|s|sec|secs|seconds|MB|KB|GB|GiB|MiB)\b/i', '', $clean) ?? $clean;

        return trim((string) preg_replace('/\s+/', ' ', $clean));
    }

    /** Appended to the system prompt when an ask channel is present, teaching that marker. */
    private const string ASK_INSTRUCTION = "\n\nIf you need input or a decision from a person to "
        . 'proceed, do not guess: end your turn with no tool call and a line beginning "[question]" '
        . 'followed by your question. You will receive the answer and continue.';

    /**
     * The PALETTE IS REQUIRED, and that is the point. It used to be `array $specs = []`, so a caller
     * that simply forgot the argument got a model with no tools at all — and nothing said so. That is
     * what happened to the supervisor tier: built with four arguments, it was asked to judge whether
     * "the actual work looks correct" with no way to open a file or run a test, its only input the
     * report of the party under review.
     *
     * A default of "none" fails in the harmful direction and fails silently. There is no safe default
     * here, so there is no default: pass the registry and the model sees everything in it, narrow it
     * deliberately with {@see Registry::only()} or {@see Registry::except()}, and if a scope genuinely
     * must not act, pass an empty `new Registry()` — which says so in the code, where it can be read.
     *
     * This is also the contract {@see \Claw\Workflow\WorkflowAbstract::ai()} already used (null = every
     * tool, a list = only those, `[]` = none). Two components in one codebase disagreeing about what
     * "unspecified" means is how the disagreement stays invisible.
     *
     * @param int                $maxHistory soft cap on history length (0 = no cap); the
     *                                       hard bound is the model's own context window
     * @param ?SpeakerInterface  $ask        who the model reaches when it ends a turn with the
     *                                        [question] marker (a person or an agent); null = the
     *                                        loop stays headless and such a turn is just the answer
     * @param ?Budget            $turnBudget caps this one exchange in tokens/time; when it (or the
     *                                        run total it bubbles to) is spent, the loop stops and
     *                                        returns what it has. null = no cap.
     * @param ?\Closure(list<Message>): void $checkpoint called with the history after every turn, so a caller can
     *                                        write the exchange down as it happens. The loop keeps no
     *                                        opinion about where it goes; it only knows that a turn has
     *                                        landed and the history is consistent at that instant —
     *                                        every tool_use answered, nothing half-applied
     */
    public function __construct(
        private readonly AgentInterface $agent,
        private readonly ExecutorInterface $executor,
        private readonly string $model,
        private readonly string $system,
        private readonly Registry $tools,
        private readonly int $maxHistory = 0,
        private readonly ?Tracer $tracer = null,
        private readonly ?SpeakerInterface $ask = null,
        private readonly ?Budget $turnBudget = null,
        private readonly ?TokenPricing $pricing = null,
        private readonly ?\Closure $checkpoint = null,
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

        /** @var array<string, int> attempt → how many times IN A ROW it produced the SAME failure */
        $toolFailures = [];

        /** @var array<string, string> attempt → the signature of the failure it produced last */
        $lastFailure = [];
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
                tools: $this->tools->specs(),
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
                    // every requested call is answered before we go, or a real run dies on the 400 two
                    // steps later — which is exactly how this was found.
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
                    $question = self::extractQuestion($response->text ?? '');

                    if ($question !== null) {
                        // Persist the exchange BEFORE blocking on the answer: this turn — the [question] — is
                        // a no-tool turn the tool-turn checkpoint below never reaches, so without this a crash
                        // while parked leaves nothing recorded and a resume re-asks the model instead of
                        // continuing from the answer. {@see pendingQuestion()} reads this tail back on resume.
                        if ($this->checkpoint !== null) {
                            ($this->checkpoint)($history);
                        }

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
            $stuckWhy = '';

            foreach ($response->toolCalls as $call) {
                $this->tracer?->toolCall($call->name, $call->input);

                $result = $this->executor->call(new ToolCall($call->id, $call->name, $call->input));
                $this->tracer?->toolResult($call->name, $result->content, $result->isError);
                $results[] = $result;

                // No-progress guard: the SAME tool returning the SAME result — an error OR a useless
                // success ("no such tool", "nothing found") — over and over is a wedged model, not work.
                // A different result each time is the attempt guard's business, just below.
                $key = $call->name . "\0" . $result->content;
                $toolRepeats[$key] = ($toolRepeats[$key] ?? 0) + 1;

                if ($toolRepeats[$key] >= self::STUCK_TOOL_REPEAT) {
                    $stuckTool = $call->name;
                    $stuckWhy = "keeps calling '{$call->name}' and getting the same result back";
                }

                // Stuck guard: the same call coming back with the SAME FAILURE, over and over.
                //
                // What it must not punish is the loop that works. A step that edits code and re-runs the
                // tests runs the SAME command every time — that is how you find out whether it got better
                // — and it fails every time until it doesn't. Counting those as attempts stops honest
                // work: a live run wrote an expression parser in four passes, each one different, each
                // failing differently, and the first draft of this guard would have cut it off mid-climb.
                //
                // So the signal is not "it failed again", it is "nothing changed". A failure whose output
                // differs from the last one is progress being made or ground being explored, and resets
                // the count; a failure that says exactly what the last one said is a model not learning.
                $attempt = $call->name . "\0" . json_encode($call->input, JSON_UNESCAPED_SLASHES);

                if (self::failed($result)) {
                    $signature = self::signature($result->content);

                    if (($lastFailure[$attempt] ?? null) === $signature) {
                        $toolFailures[$attempt] = ($toolFailures[$attempt] ?? 1) + 1;
                    } else {
                        $lastFailure[$attempt] = $signature;
                        $toolFailures[$attempt] = 1;
                    }

                    if ($toolFailures[$attempt] >= self::UNCHANGED_FAILURE_LIMIT) {
                        $stuckTool = $call->name;
                        $stuckWhy = "has run the same '{$call->name}' call {$toolFailures[$attempt]} times "
                            . 'and got the same failure back every time';
                    }
                } else {
                    unset($toolFailures[$attempt], $lastFailure[$attempt]);   // it worked; the streak is over
                }
            }

            $history[] = new Message(Role::User, $results);
            $this->tracer?->exit($turn);

            // A turn has landed and the history is whole: every tool_use answered. This is the only
            // instant it is safe to write down, which is why the loop offers it rather than leaving the
            // caller to guess when to snapshot.
            if ($this->checkpoint !== null) {
                ($this->checkpoint)($history);
            }

            // Wedged on a tool (same result STUCK_TOOL_REPEAT times). Do NOT just churn or silently stop:
            // ESCALATE to the channel (supervisor/human) once — guidance resumes the exchange with a fresh
            // slate; no one to ask, no answer, or a recurrence -> stop. The budget is still the hard cap.
            if ($stuckTool !== null) {
                if (!$stuckEscalated && $this->ask !== null) {
                    $stuckEscalated = true;
                    $steer = $this->ask->reply(
                        "WHAT IS GOING ON HERE? This step {$stuckWhy}, and is not converging.\n\n"
                        . 'You have the project\'s tools: go and look before you answer — read what the '
                        . "step has been changing and run the thing it keeps failing, yourself.\n\n"
                        . 'Reply with concrete guidance for a different approach, or anything else to stop '
                        . 'the step here.',
                    );

                    if ($steer !== null) {
                        $history[] = Message::userText($steer);
                        $toolRepeats = [];     // a fresh slate after the steer
                        $toolFailures = [];
                        $lastFailure = [];

                        continue;
                    }
                }

                // Say so IN THE HISTORY before leaving. Whatever reads it next — the handoff the engine
                // forms by continuing this very conversation, then the critic, then a person reading the
                // trace — would otherwise see an ordinary tail of tool results and take the stop for a
                // choice. That is the same silence this guard exists to break, one layer further out.
                $history[] = Message::userText(
                    "STOPPED: this exchange {$stuckWhy}, and was ended without reaching a conclusion.",
                );

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
    /**
     * Did this tool call FAIL? Two ways, and the second is the one that matters.
     *
     * `isError` is set only when the tool itself threw — a bad path, a refused permission. A command
     * that ran fine and reported failure is, to the executor, a perfectly successful call: `bash` returns
     * the output prefixed `[exit 1]` and nothing anywhere marks that as a failure. Yet a red test run is
     * exactly the attempt worth counting; it is what "I tried and it did not work" looks like, and it is
     * the shape a step re-running a suite produces every time.
     *
     * The prefix is parsed by {@see BashTool::exitCode()} — the same class that writes it, so the two
     * cannot drift apart in silence.
     */
    private static function failed(ToolResultBlock $result): bool
    {
        if ($result->isError) {
            return true;
        }

        $exit = BashTool::exitCode($result->content);

        return $exit !== null && $exit !== 0;
    }

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
    private static function extractQuestion(string $text): ?string
    {
        if (!str_contains($text, self::QUESTION_MARKER)) {
            return null;
        }

        $question = trim(str_replace(self::QUESTION_MARKER, '', $text));

        return $question === '' ? 'The worker paused for input but gave no question.' : $question;
    }

    /**
     * The unanswered [question] a checkpointed exchange ended on, or null when the last turn is an
     * ordinary finished answer (an empty history, or a tail still asking for tools, is not a park).
     *
     * A resumed AI step reads this off its recorded exchange to tell a PARK — continue from the human's
     * answer — from a SETTLED exchange it should replay. It is the same marker the live loop routes on,
     * read from the durable tail rather than from a fresh response.
     *
     * @param list<Message> $history
     */
    public static function pendingQuestion(array $history): ?string
    {
        $last = $history === [] ? null : $history[array_key_last($history)];

        if (!$last instanceof Message || $last->role !== Role::Assistant) {
            return null;
        }

        $text = '';

        foreach ($last->content as $block) {
            if ($block instanceof ToolUseBlock) {
                return null;   // a turn still asking for tools was never a park
            }

            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return self::extractQuestion($text);
    }
}
