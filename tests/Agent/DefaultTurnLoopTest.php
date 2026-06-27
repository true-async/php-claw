<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\AgentInterface;
use Claw\Agent\AgentRequest;
use Claw\Agent\AgentResponse;
use Claw\Agent\Budget;
use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\Message;
use Claw\Agent\Role;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\ToolResultBlock;
use Claw\Agent\ToolSpec;
use Claw\Agent\ToolUseBlock;
use Claw\Agent\Usage;
use Claw\Exceptions\ContextLengthException;
use Claw\Tool\ToolCall;
use Testo\Assert;
use Testo\Test;
use Tests\Support\RecordingExecutor;
use Tests\Support\ScriptedAgent;

final class DefaultTurnLoopTest
{
    #[Test]
    public function returnsFinalAnswerAndAdvertisesToolsWhenNothingIsCalled(): void
    {
        $agent = new ScriptedAgent(
            new AgentResponse([new TextBlock('hello there')], [], StopReason::EndTurn, new Usage(3, 7), 'hello there'),
        );
        $spec = new ToolSpec('echo', 'echo it back', ['type' => 'object']);
        $loop = new DefaultTurnLoop($agent, new RecordingExecutor(), 'model-x', 'you are claw', [$spec]);

        $result = $loop->run([Message::userText('hi')]);

        Assert::same($result->text, 'hello there');

        // one round-trip: the starting user message plus the assistant answer
        Assert::count($result->history, 2);
        Assert::same($result->history[1]->role, Role::Assistant);

        Assert::same($result->usage->inputTokens, 3);
        Assert::same($result->usage->outputTokens, 7);

        // model, system prompt and tool specs are threaded into the request
        Assert::count($agent->requests, 1);
        Assert::same($agent->requests[0]->model, 'model-x');
        Assert::same($agent->requests[0]->system, 'you are claw');
        Assert::same($agent->requests[0]->tools[0]->name, 'echo');
    }

    #[Test]
    public function aRunawayLoopStopsAtTheTurnCapInsteadOfChurningForever(): void
    {
        // A model that never finishes — every turn is another tool call. With no ask channel the loop
        // must stop at the soft turn cap and return, not spin forever (this test would hang otherwise).
        $agent = new class () implements AgentInterface {
            public int $calls = 0;

            public function send(AgentRequest $request): AgentResponse
            {
                $this->calls++;
                $use = new ToolUseBlock('t' . $this->calls, 'echo', []);

                return new AgentResponse([$use], [$use], StopReason::ToolUse, new Usage(1, 1));
            }
        };
        $executor = new RecordingExecutor(
            static fn (ToolCall $call): ToolResultBlock => new ToolResultBlock($call->id, 'ok', false),
        );
        $loop = new DefaultTurnLoop($agent, $executor, 'm', 's');   // no ask channel -> stop at the cap

        $loop->run([Message::userText('go')]);

        Assert::same($agent->calls, 50);   // ran exactly MAX_TURNS turns, then stopped
    }

    #[Test]
    public function aWedgedToolStopsTheExchangeAfterRepeatedIdenticalErrors(): void
    {
        // The model keeps calling the same tool, which keeps failing with the SAME error. The circuit-
        // breaker stops the exchange after a few identical failures — not at the far 50-turn cap.
        $agent = new class () implements AgentInterface {
            public int $calls = 0;

            public function send(AgentRequest $request): AgentResponse
            {
                $this->calls++;
                $use = new ToolUseBlock('t' . $this->calls, 'wedged', []);

                return new AgentResponse([$use], [$use], StopReason::ToolUse, new Usage(1, 1));
            }
        };
        $executor = new RecordingExecutor(
            static fn (ToolCall $call): ToolResultBlock => new ToolResultBlock($call->id, 'same error every time', true),
        );
        $loop = new DefaultTurnLoop($agent, $executor, 'm', 's');   // no ask channel

        $loop->run([Message::userText('go')]);

        Assert::same($agent->calls, 3);   // stopped at STUCK_TOOL_REPEAT, not the 50-turn cap
    }

    #[Test]
    public function aToolFailingWithDifferentErrorsIsLeftToKeepIterating(): void
    {
        // A tool failing with a DIFFERENT error each time is the model iterating toward a fix — the
        // circuit-breaker must NOT trip on that; only the far turn cap stops it.
        $agent = new class () implements AgentInterface {
            public int $calls = 0;

            public function send(AgentRequest $request): AgentResponse
            {
                $this->calls++;
                $use = new ToolUseBlock('t' . $this->calls, 'probe', []);

                return new AgentResponse([$use], [$use], StopReason::ToolUse, new Usage(1, 1));
            }
        };
        $n = 0;
        $executor = new RecordingExecutor(
            static function (ToolCall $call) use (&$n): ToolResultBlock {
                return new ToolResultBlock($call->id, 'error #' . (++$n), true);   // distinct every time
            },
        );
        $loop = new DefaultTurnLoop($agent, $executor, 'm', 's');

        $loop->run([Message::userText('go')]);

        Assert::same($agent->calls, 50);   // distinct errors do not trip the breaker; only the turn cap stops it
    }

    #[Test]
    public function runsToolThreadsResultBackThenAnswers(): void
    {
        $toolUse = new ToolUseBlock('t1', 'echo', ['msg' => 'hi']);
        $agent = new ScriptedAgent(
            new AgentResponse([$toolUse], [$toolUse], StopReason::ToolUse, new Usage(10, 2)),
            new AgentResponse([new TextBlock('All done.')], [], StopReason::EndTurn, new Usage(5, 1), 'All done.'),
        );
        $executor = new RecordingExecutor(
            static fn (ToolCall $call): ToolResultBlock => new ToolResultBlock($call->id, 'echoed: ' . ($call->input['msg'] ?? ''), false),
        );
        $loop = new DefaultTurnLoop($agent, $executor, 'm', 's');

        $result = $loop->run([Message::userText('please echo hi')]);

        Assert::same($result->text, 'All done.');

        // usage accumulates across both round-trips
        Assert::same($result->usage->inputTokens, 15);
        Assert::same($result->usage->outputTokens, 3);

        // the executor saw exactly the model's tool_use
        Assert::count($executor->calls, 1);
        Assert::same($executor->calls[0]->id, 't1');
        Assert::same($executor->calls[0]->name, 'echo');
        Assert::same($executor->calls[0]->input, ['msg' => 'hi']);

        // returned history: user, assistant(tool_use), user(tool_result), assistant(text)
        Assert::count($result->history, 4);
        $toolResultMsg = $result->history[2];
        Assert::same($toolResultMsg->role, Role::User);
        $block = $toolResultMsg->content[0];
        Assert::true($block instanceof ToolResultBlock);   // narrows for the asserts below
        Assert::same($block->toolUseId, 't1');
        Assert::same($block->content, 'echoed: hi');
        Assert::false($block->isError);

        // the second model call carried the tool result in its history
        Assert::count($agent->requests, 2);
        Assert::count($agent->requests[1]->messages, 3);
    }

    #[Test]
    public function threadsToolErrorsBackWithoutStopping(): void
    {
        $toolUse = new ToolUseBlock('t1', 'boom', []);
        $agent = new ScriptedAgent(
            new AgentResponse([$toolUse], [$toolUse], StopReason::ToolUse, new Usage()),
            new AgentResponse([new TextBlock('recovered')], [], StopReason::EndTurn, new Usage(), 'recovered'),
        );
        $executor = new RecordingExecutor(
            static fn (ToolCall $call): ToolResultBlock => new ToolResultBlock($call->id, 'kaboom', true),
        );
        $loop = new DefaultTurnLoop($agent, $executor, 'm', 's');

        $result = $loop->run([Message::userText('go')]);

        Assert::same($result->text, 'recovered');

        $block = $result->history[2]->content[0];
        Assert::true($block instanceof ToolResultBlock);   // narrows for the asserts below
        Assert::true($block->isError);
        Assert::same($block->content, 'kaboom');
    }

    #[Test]
    public function gathersMultipleToolResultsIntoOneOrderedUserMessage(): void
    {
        $first  = new ToolUseBlock('t1', 'echo', ['msg' => 'one']);
        $second = new ToolUseBlock('t2', 'echo', ['msg' => 'two']);
        $agent = new ScriptedAgent(
            new AgentResponse([$first, $second], [$first, $second], StopReason::ToolUse, new Usage()),
            new AgentResponse([new TextBlock('done')], [], StopReason::EndTurn, new Usage(), 'done'),
        );
        $executor = new RecordingExecutor(
            static fn (ToolCall $call): ToolResultBlock => new ToolResultBlock($call->id, 'r:' . $call->id, false),
        );
        $spec = new ToolSpec('echo', 'echo it back', ['type' => 'object']);
        $loop = new DefaultTurnLoop($agent, $executor, 'model-x', 'you are claw', [$spec]);

        $result = $loop->run([Message::userText('go')]);

        // both calls dispatched, in tool_use order
        Assert::count($executor->calls, 2);
        Assert::same($executor->calls[0]->id, 't1');
        Assert::same($executor->calls[1]->id, 't2');

        // the two results are gathered into exactly ONE user message, in order:
        // history = user, assistant(2x tool_use), user(2x tool_result), assistant(text)
        Assert::count($result->history, 4);
        $resultsMsg = $result->history[2];
        Assert::same($resultsMsg->role, Role::User);
        Assert::count($resultsMsg->content, 2);
        $b0 = $resultsMsg->content[0];
        $b1 = $resultsMsg->content[1];
        Assert::true($b0 instanceof ToolResultBlock);   // narrows for the asserts below
        Assert::true($b1 instanceof ToolResultBlock);
        Assert::same($b0->toolUseId, 't1');
        Assert::same($b1->toolUseId, 't2');

        // model, system and specs are re-threaded on the follow-up round-trip too
        Assert::same($agent->requests[1]->model, 'model-x');
        Assert::same($agent->requests[1]->system, 'you are claw');
        Assert::same($agent->requests[1]->tools[0]->name, 'echo');
    }

    #[Test]
    public function terminatesWhenToolUseStopReasonCarriesNoToolCalls(): void
    {
        // A degenerate turn: the wire said "tool_use" but no parseable tool calls
        // arrived. The loop must end with the text, not spin on an empty batch.
        $agent = new ScriptedAgent(
            new AgentResponse([new TextBlock('partial')], [], StopReason::ToolUse, new Usage(2, 1), 'partial'),
        );
        $executor = new RecordingExecutor();
        $loop = new DefaultTurnLoop($agent, $executor, 'm', 's');

        $result = $loop->run([Message::userText('go')]);

        Assert::same($result->text, 'partial');
        Assert::count($agent->requests, 1);   // did not loop
        Assert::count($executor->calls, 0);   // nothing dispatched
        Assert::count($result->history, 2);   // user + the assistant turn only
    }

    #[Test]
    public function doesNotMutateTheCallersHistory(): void
    {
        $toolUse = new ToolUseBlock('t1', 'echo', []);
        $agent = new ScriptedAgent(
            new AgentResponse([$toolUse], [$toolUse], StopReason::ToolUse, new Usage()),
            new AgentResponse([new TextBlock('done')], [], StopReason::EndTurn, new Usage(), 'done'),
        );
        $loop = new DefaultTurnLoop($agent, new RecordingExecutor(), 'm', 's');

        $start = [Message::userText('go')];
        $result = $loop->run($start);

        // run() grows its own copy (copy-on-write); the caller's array is untouched —
        // a load-bearing contract for the phase-2 step executor that threads history.
        Assert::count($start, 1);
        Assert::count($result->history, 4);
    }

    #[Test]
    public function completesWhenHistoryStaysUnderTheCap(): void
    {
        $agent = new ScriptedAgent(
            new AgentResponse([new TextBlock('ok')], [], StopReason::EndTurn, new Usage(), 'ok'),
        );
        // history reaches length 2, strictly under the cap of 4 — a positive cap must
        // not abort an under-cap run (the guard is inclusive: count >= cap).
        $loop = new DefaultTurnLoop($agent, new RecordingExecutor(), 'm', 's', maxHistory: 4);

        $result = $loop->run([Message::userText('go')]);

        Assert::same($result->text, 'ok');
        Assert::count($result->history, 2);
    }

    #[Test]
    public function asksTheChannelOnAQuestionMarkerThenContinues(): void
    {
        $agent = new ScriptedAgent(
            new AgentResponse([new TextBlock('[question] which file?')], [], StopReason::EndTurn, new Usage(), '[question] which file?'),
            new AgentResponse([new TextBlock('done')], [], StopReason::EndTurn, new Usage(), 'done'),
        );
        $ask = new class () implements SpeakerInterface {
            public ?string $heard = null;

            public function name(): SpeakerRole
            {
                return SpeakerRole::Human;
            }

            public function reply(string $incoming): string
            {
                $this->heard = $incoming;

                return 'src/Foo.php';
            }
        };
        $loop = new DefaultTurnLoop($agent, new RecordingExecutor(), 'm', 's', ask: $ask);

        $result = $loop->run([Message::userText('go')]);

        Assert::same($result->text, 'done');                  // continued past the question to the final answer
        Assert::same($ask->heard, 'which file?');             // the marker is stripped before asking
        Assert::count($agent->requests, 2);                   // paused for input, then resumed
        Assert::true(str_contains($agent->requests[0]->system, '[question]'));   // the loop taught the marker

        // history: user(go), assistant([question]…), user(the answer), assistant(done)
        Assert::count($result->history, 4);
        Assert::same($result->history[2]->role, Role::User);
    }

    #[Test]
    public function aQuestionWhoseChannelPassesUpBecomesTheFinalAnswer(): void
    {
        $agent = new ScriptedAgent(
            new AgentResponse([new TextBlock('[question] anyone?')], [], StopReason::EndTurn, new Usage(), '[question] anyone?'),
        );
        $ask = new class () implements SpeakerInterface {
            public function name(): SpeakerRole
            {
                return SpeakerRole::Supervisor;
            }

            public function reply(string $incoming): ?string
            {
                return null;   // the whole chain passed up — no one answered
            }
        };
        $loop = new DefaultTurnLoop($agent, new RecordingExecutor(), 'm', 's', ask: $ask);

        $result = $loop->run([Message::userText('go')]);

        Assert::same($result->text, '[question] anyone?');   // unanswered question falls through to final
        Assert::count($agent->requests, 1);                  // did not loop
    }

    #[Test]
    public function withoutAnAskChannelAQuestionMarkerIsJustTheFinalAnswer(): void
    {
        $agent = new ScriptedAgent(
            new AgentResponse([new TextBlock('[question] hm?')], [], StopReason::EndTurn, new Usage(), '[question] hm?'),
        );
        $loop = new DefaultTurnLoop($agent, new RecordingExecutor(), 'm', 's');   // headless: no ask channel

        $result = $loop->run([Message::userText('go')]);

        Assert::same($result->text, '[question] hm?');        // a marker is inert without a channel
        Assert::count($agent->requests, 1);                   // did not loop
        Assert::same($agent->requests[0]->system, 's');       // system prompt left untouched
    }

    #[Test]
    public function stopsTheExchangeWhenTheTurnBudgetIsSpent(): void
    {
        // The model would keep calling a tool forever; the turn budget stops the exchange after the
        // first round-trip (10+5 tokens) hits the cap, before any further model call or dispatch.
        $looping = static fn (): AgentResponse => new AgentResponse(
            [new ToolUseBlock('t', 'echo', [])],
            [new ToolUseBlock('t', 'echo', [])],
            StopReason::ToolUse,
            new Usage(10, 5),
        );
        $agent = new ScriptedAgent($looping(), $looping(), $looping());
        $executor = new RecordingExecutor();
        $loop = new DefaultTurnLoop($agent, $executor, 'm', 's', turnBudget: new Budget(tokenLimit: 15));

        $loop->run([Message::userText('go')]);

        Assert::count($agent->requests, 1);   // stopped after one round-trip
        Assert::count($executor->calls, 0);   // did not even dispatch the tool
    }

    #[Test]
    public function throwsWhenHistoryReachesTheSoftCap(): void
    {
        $looping = static fn (): AgentResponse => new AgentResponse(
            [new ToolUseBlock('t', 'echo', [])],
            [new ToolUseBlock('t', 'echo', [])],
            StopReason::ToolUse,
            new Usage(),
        );
        $agent = new ScriptedAgent($looping(), $looping(), $looping(), $looping());
        $loop = new DefaultTurnLoop($agent, new RecordingExecutor(), 'm', 's', maxHistory: 4);

        $threw = false;

        try {
            $loop->run([Message::userText('go')]);
        } catch (ContextLengthException) {
            $threw = true;
        }

        Assert::true($threw);
    }
}
