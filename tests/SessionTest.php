<?php

declare(strict_types=1);

namespace Tests;

use Claw\Agent\AgentInterface;
use Claw\Agent\AgentRequest;
use Claw\Agent\AgentResponse;
use Claw\Agent\Role;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\ToolResultBlock;
use Claw\Agent\ToolUseBlock;
use Claw\Agent\Usage;
use Claw\Chat\Approval;
use Claw\Exceptions\AgentException;
use Claw\Exceptions\AuthException;
use Claw\Exceptions\ContextLengthException;
use Claw\Exceptions\QuotaExceededException;
use Claw\Exceptions\RateLimitException;
use Claw\Session;
use Claw\Store\SessionStore;
use Claw\Tool\Registry;
use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeConversation;
use Tests\Support\QueuedConversation;
use Tests\Support\ScriptedAgent;

final class SessionTest
{
    #[Test]
    public function runsReActLoopToolThenAnswer(): void
    {
        $toolUse = new ToolUseBlock('t1', 'echo', ['msg' => 'hi']);
        $agent = new ScriptedAgent(
            new AgentResponse([$toolUse], [$toolUse], StopReason::ToolUse, new Usage()),
            new AgentResponse([new TextBlock('All done.')], [], StopReason::EndTurn, new Usage(), 'All done.'),
        );
        $conversation = new FakeConversation('please echo hi');
        $registry = new Registry();
        $registry->add($this->echoTool());

        $session = new Session($conversation, $agent, $registry, 'you are claw', 'model-x');
        $session->run();

        // final text delivered to the conversation
        Assert::same($conversation->sent, ['All done.']);

        // two model round-trips
        Assert::same(count($agent->requests), 2);

        // the tool was advertised to the model
        Assert::same($agent->requests[0]->tools[0]->name, 'echo');

        // second request history: user, assistant(tool_use), user(tool_result)
        $second = $agent->requests[1];
        Assert::same(count($second->messages), 3);
        Assert::same($second->messages[2]->role, Role::User);
        $toolResult = $second->messages[2]->content[0];
        Assert::true($toolResult instanceof ToolResultBlock);   // narrows for the asserts below
        Assert::same($toolResult->toolUseId, 't1');
        Assert::same($toolResult->content, 'echoed: hi');
        Assert::false($toolResult->isError);
    }

    #[Test]
    public function skipsMutatingToolWhenUserDenies(): void
    {
        $toolUse = new ToolUseBlock('t1', 'touch', ['command' => 'echo hi']);
        $agent = new ScriptedAgent(
            new AgentResponse([$toolUse], [$toolUse], StopReason::ToolUse, new Usage()),
            new AgentResponse([new TextBlock('ok')], [], StopReason::EndTurn, new Usage(), 'ok'),
        );
        $conversation = new FakeConversation('go');
        $conversation->confirmReplies = [Approval::No];   // the user refuses
        $registry = new Registry();
        $registry->add($this->mutatingTool());

        (new Session($conversation, $agent, $registry, 's', 'm'))->run();

        // the user was asked, the tool did NOT run, and the model heard the refusal
        Assert::same(count($conversation->confirmed), 1);
        $toolResult = $agent->requests[1]->messages[2]->content[0];
        Assert::true($toolResult instanceof ToolResultBlock);
        Assert::true($toolResult->isError);
        Assert::true(str_contains($toolResult->content, 'denied'));
    }

    #[Test]
    public function runsMutatingToolWhenUserApproves(): void
    {
        $toolUse = new ToolUseBlock('t1', 'touch', ['command' => 'echo hi']);
        $agent = new ScriptedAgent(
            new AgentResponse([$toolUse], [$toolUse], StopReason::ToolUse, new Usage()),
            new AgentResponse([new TextBlock('ok')], [], StopReason::EndTurn, new Usage(), 'ok'),
        );
        $conversation = new FakeConversation('go');
        $conversation->confirmReplies = [Approval::Once];   // the user approves once
        $registry = new Registry();
        $registry->add($this->mutatingTool());

        (new Session($conversation, $agent, $registry, 's', 'm'))->run();

        $toolResult = $agent->requests[1]->messages[2]->content[0];
        Assert::true($toolResult instanceof ToolResultBlock);
        Assert::false($toolResult->isError);
        Assert::true(str_contains($toolResult->content, 'ran: echo hi'));
    }

    #[Test]
    public function feedsToolErrorBackToModel(): void
    {
        $toolUse = new ToolUseBlock('t1', 'boom', []);
        $agent = new ScriptedAgent(
            new AgentResponse([$toolUse], [$toolUse], StopReason::ToolUse, new Usage()),
            new AgentResponse([new TextBlock('ok')], [], StopReason::EndTurn, new Usage(), 'ok'),
        );
        $conversation = new FakeConversation('go');
        $registry = new Registry();
        $registry->add($this->failingTool());

        $session = new Session($conversation, $agent, $registry, 's', 'm');
        $session->run();

        $toolResult = $agent->requests[1]->messages[2]->content[0];
        Assert::true($toolResult instanceof ToolResultBlock);   // narrows for the asserts below
        Assert::true($toolResult->isError);
        Assert::true(str_contains($toolResult->content, 'kaboom'));
    }

    #[Test]
    public function stopsWhenHistoryHitsConfiguredLimit(): void
    {
        $loop = static fn (): AgentResponse => new AgentResponse(
            [new ToolUseBlock('t', 'echo', [])],
            [new ToolUseBlock('t', 'echo', [])],
            StopReason::ToolUse,
            new Usage(),
        );
        $agent = new ScriptedAgent($loop(), $loop(), $loop(), $loop());
        $conversation = new FakeConversation('go');
        $registry = new Registry();
        $registry->add($this->echoTool());

        // The soft memory cap stops the runaway tool loop.
        (new Session($conversation, $agent, $registry, 's', 'm', maxHistory: 4))->run();

        Assert::true(str_contains($conversation->sent[0], 'too long'));
    }

    #[Test]
    public function reportsContextOverflowFromModel(): void
    {
        $agent = new ScriptedAgent(new ContextLengthException('maximum context length exceeded'));
        $conversation = new FakeConversation('go');

        (new Session($conversation, $agent, new Registry(), 's', 'm'))->run();

        Assert::true(str_contains($conversation->sent[0], 'too long'));
    }

    #[Test]
    public function reportsAgentErrorAndKeepsTheConversationAlive(): void
    {
        $agent = new class () implements AgentInterface {
            public int $calls = 0;

            public function send(AgentRequest $request): AgentResponse
            {
                $this->calls++;

                throw new AgentException('boom api');
            }
        };
        $conversation = new QueuedConversation();
        $session = new Session($conversation, $agent, new Registry(), 's', 'm');

        // Deliver one message per turn, letting each turn finish before the next —
        // so the error in one doesn't take down the loop.
        $run = \Async\spawn(static fn () => $session->run());
        $conversation->deliver('first');
        \Async\delay(60);
        $conversation->deliver('second');
        \Async\delay(60);
        $conversation->close();
        \Async\await($run);

        Assert::same($conversation->sent, ['Error: boom api', 'Error: boom api']);
        Assert::same($agent->calls, 2);
    }

    #[Test]
    public function reportsRateLimitWithResumeTime(): void
    {
        $agent = new ScriptedAgent(new RateLimitException('rl', 5_000));
        $conversation = new FakeConversation('go');

        (new Session($conversation, $agent, new Registry(), 's', 'm'))->run();

        Assert::true(str_contains($conversation->sent[0], 'Rate limit'));
        Assert::true(str_contains($conversation->sent[0], '5s'));
    }

    #[Test]
    public function reportsAuthErrorAsConfiguration(): void
    {
        $agent = new ScriptedAgent(new AuthException('invalid key'));
        $conversation = new FakeConversation('go');

        (new Session($conversation, $agent, new Registry(), 's', 'm'))->run();

        Assert::true(str_contains($conversation->sent[0], 'Configuration error'));
    }

    #[Test]
    public function reportsQuotaExhaustion(): void
    {
        $agent = new ScriptedAgent(new QuotaExceededException('no credits'));
        $conversation = new FakeConversation('go');

        (new Session($conversation, $agent, new Registry(), 's', 'm'))->run();

        Assert::true(str_contains($conversation->sent[0], 'Quota exhausted'));
    }

    #[Test]
    public function alwaysApprovalIsRememberedAndSkipsTheSecondPrompt(): void
    {
        $path = sys_get_temp_dir() . '/claw-rules-' . uniqid('', true) . '.db';

        try {
            $call = static fn (string $id): ToolUseBlock => new ToolUseBlock($id, 'touch', ['command' => 'echo hi']);
            $agent = new ScriptedAgent(
                new AgentResponse([$call('t1')], [$call('t1')], StopReason::ToolUse, new Usage()),
                new AgentResponse([$call('t2')], [$call('t2')], StopReason::ToolUse, new Usage()),
                new AgentResponse([new TextBlock('ok')], [], StopReason::EndTurn, new Usage(), 'ok'),
            );
            $conversation = new FakeConversation('go');
            $conversation->confirmReplies = [Approval::Always];   // approve, and remember it
            $registry = new Registry();
            $registry->add($this->mutatingTool());

            (new Session($conversation, $agent, $registry, 's', 'm', store: new SessionStore($path)))->run();

            // asked exactly once; the second call ran straight through the saved rule
            Assert::same(count($conversation->confirmed), 1);

            $firstResult = $agent->requests[1]->messages[2]->content[0];
            Assert::true($firstResult instanceof ToolResultBlock);
            Assert::false($firstResult->isError);

            $secondResult = $agent->requests[2]->messages[4]->content[0];
            Assert::true($secondResult instanceof ToolResultBlock);
            Assert::false($secondResult->isError);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function remembersHistoryAcrossSessionsViaStore(): void
    {
        $path = sys_get_temp_dir() . '/claw-session-' . uniqid('', true) . '.db';

        try {
            // First run: one message, answered with plain text.
            $agent1 = new ScriptedAgent(
                new AgentResponse([new TextBlock('hi there')], [], StopReason::EndTurn, new Usage(), 'hi there'),
            );
            (new Session(new FakeConversation('hello'), $agent1, new Registry(), 's', 'm', store: new SessionStore($path)))->run();

            // Second run: a fresh Session reopening the same file. The prior turn
            // is loaded and replayed to the model as context.
            $agent2 = new ScriptedAgent(
                new AgentResponse([new TextBlock('again')], [], StopReason::EndTurn, new Usage(), 'again'),
            );
            (new Session(new FakeConversation('and now?'), $agent2, new Registry(), 's', 'm', store: new SessionStore($path)))->run();

            // The second model call saw: user 'hello', assistant 'hi there', user 'and now?'.
            $messages = $agent2->requests[0]->messages;
            Assert::same(count($messages), 3);
            Assert::same($messages[0]->role, Role::User);
            Assert::same($messages[1]->role, Role::Assistant);
            Assert::same($messages[2]->role, Role::User);

            $first = $messages[0]->content[0];
            Assert::true($first instanceof TextBlock);
            Assert::same($first->text, 'hello');
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function stopCancelsTheRunningTurn(): void
    {
        // A slow round-trip, so the "/stop" lands while the turn is mid-flight.
        $agent = new class () implements AgentInterface {
            public function send(AgentRequest $request): AgentResponse
            {
                \Async\delay(1000);

                return new AgentResponse([new TextBlock('done')], [], StopReason::EndTurn, new Usage(), 'done');
            }
        };
        $conversation = new QueuedConversation();
        $session = new Session($conversation, $agent, new Registry(), 's', 'm');

        // "hello" starts a turn that parks on the slow send; once it's running,
        // "/stop" arrives and the loop cancels it.
        $run = \Async\spawn(static fn () => $session->run());
        $conversation->deliver('hello');
        \Async\delay(80);
        $conversation->deliver('/stop');
        \Async\delay(80);
        $conversation->close();
        \Async\await($run);

        Assert::true(\in_array('Stopped.', $conversation->sent, true));
        Assert::false(\in_array('done', $conversation->sent, true));   // the answer never arrived
    }

    private function echoTool(): ToolInterface
    {
        return new class () implements ToolInterface {
            public function name(): string
            {
                return 'echo';
            }

            public function description(): string
            {
                return 'echo the message back';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']]];
            }

            public function risk(): Risk
            {
                return Risk::Safe;
            }

            public function handle(array $input): string
            {
                return 'echoed: ' . ($input['msg'] ?? '');
            }
        };
    }

    private function mutatingTool(): ToolInterface
    {
        return new class () implements ToolInterface {
            public function name(): string
            {
                return 'touch';
            }

            public function description(): string
            {
                return 'a mutating action that needs approval';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['command' => ['type' => 'string']]];
            }

            public function risk(): Risk
            {
                return Risk::Mutating;
            }

            public function handle(array $input): string
            {
                return 'ran: ' . ($input['command'] ?? '');
            }
        };
    }

    private function failingTool(): ToolInterface
    {
        return new class () implements ToolInterface {
            public function name(): string
            {
                return 'boom';
            }

            public function description(): string
            {
                return 'always fails';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function risk(): Risk
            {
                return Risk::Safe;
            }

            public function handle(array $input): string
            {
                throw new \Claw\Exceptions\ToolException('kaboom');
            }
        };
    }
}
