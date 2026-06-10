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
use Claw\Exceptions\AgentException;
use Claw\Exceptions\AuthException;
use Claw\Exceptions\ContextLengthException;
use Claw\Exceptions\QuotaExceededException;
use Claw\Exceptions\RateLimitException;
use Claw\Session;
use Claw\Tool\Registry;
use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeConversation;
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
        $conversation = new FakeConversation('first', 'second');

        $session = new Session($conversation, $agent, new Registry(), 's', 'm');
        $session->run();

        // each turn failed gracefully; the loop survived both messages
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
