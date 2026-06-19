<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\AgentResponse;
use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\Message;
use Claw\Agent\Role;
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
