<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\AgentRequest;
use Claw\Agent\Message;
use Claw\Agent\OpenAiCompatibleAgent;
use Claw\Agent\Role;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\ToolResultBlock;
use Claw\Agent\ToolSpec;
use Claw\Agent\ToolUseBlock;
use Claw\Http\HttpResponse;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeHttpClient;

final class OpenAiCompatibleAgentTest
{
    #[Test]
    public function encodesRequestToChatCompletionsShape(): void
    {
        $request = new AgentRequest(
            model: 'deepseek-chat',
            messages: [
                Message::userText('hi'),
                new Message(Role::Assistant, [
                    new TextBlock('checking'),
                    new ToolUseBlock('call_1', 'bash', ['cmd' => 'ls']),
                ]),
                new Message(Role::User, [
                    new ToolResultBlock('call_1', 'file.txt', false),
                ]),
            ],
            maxTokens: 256,
            system: 'You are Claw.',
            tools: [
                new ToolSpec('bash', 'Run a shell command', [
                    'type' => 'object',
                    'properties' => ['cmd' => ['type' => 'string']],
                    'required' => ['cmd'],
                ]),
            ],
            temperature: 0.2,
        );

        $wire = json_decode(json_encode(OpenAiCompatibleAgent::encodeRequest($request), JSON_THROW_ON_ERROR), true);

        Assert::same($wire['model'], 'deepseek-chat');
        Assert::same($wire['max_tokens'], 256);
        Assert::same($wire['temperature'], 0.2);

        // system becomes a leading message
        Assert::same($wire['messages'][0], ['role' => 'system', 'content' => 'You are Claw.']);
        Assert::same($wire['messages'][1], ['role' => 'user', 'content' => 'hi']);

        // assistant tool_use -> tool_calls with JSON-string arguments
        Assert::same($wire['messages'][2]['role'], 'assistant');
        Assert::same($wire['messages'][2]['content'], 'checking');
        Assert::same($wire['messages'][2]['tool_calls'][0]['id'], 'call_1');
        Assert::same($wire['messages'][2]['tool_calls'][0]['type'], 'function');
        Assert::same($wire['messages'][2]['tool_calls'][0]['function']['name'], 'bash');
        Assert::same($wire['messages'][2]['tool_calls'][0]['function']['arguments'], '{"cmd":"ls"}');

        // tool result -> its own `tool` message
        Assert::same($wire['messages'][3], [
            'role' => 'tool',
            'tool_call_id' => 'call_1',
            'content' => 'file.txt',
        ]);

        Assert::same($wire['tools'][0]['type'], 'function');
        Assert::same($wire['tools'][0]['function']['name'], 'bash');
        Assert::same($wire['tools'][0]['function']['parameters']['required'], ['cmd']);
    }

    #[Test]
    public function decodesToolCallResponse(): void
    {
        $response = OpenAiCompatibleAgent::decodeResponse([
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'one sec',
                    'tool_calls' => [[
                        'id' => 'call_9',
                        'type' => 'function',
                        'function' => ['name' => 'read_file', 'arguments' => '{"path":"/x"}'],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4],
        ]);

        Assert::same($response->stopReason, StopReason::ToolUse);
        Assert::same($response->text, 'one sec');
        Assert::same($response->usage->inputTokens, 10);
        Assert::same($response->usage->outputTokens, 4);
        Assert::same(count($response->toolCalls), 1);
        Assert::same($response->toolCalls[0]->id, 'call_9');
        Assert::same($response->toolCalls[0]->name, 'read_file');
        Assert::same($response->toolCalls[0]->input, ['path' => '/x']);
        Assert::true($response->wantsToolUse());
    }

    #[Test]
    public function decodesPlainTextResponse(): void
    {
        $response = OpenAiCompatibleAgent::decodeResponse([
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'done']]],
        ]);

        Assert::same($response->stopReason, StopReason::EndTurn);
        Assert::same($response->text, 'done');
        Assert::same($response->toolCalls, []);
        Assert::false($response->wantsToolUse());
    }

    #[Test]
    public function deepSeekFactorySendsToDeepSeekEndpoint(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, json_encode([
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'hello']]],
        ], JSON_THROW_ON_ERROR)));

        $agent = OpenAiCompatibleAgent::deepSeek($http, 'sk-ds');
        $response = $agent->send(new AgentRequest('deepseek-chat', [Message::userText('hi')]));

        Assert::same($response->text, 'hello');
        Assert::same($http->lastUrl, 'https://api.deepseek.com/chat/completions');
        Assert::true(in_array('authorization: Bearer sk-ds', $http->lastHeaders, true));
    }
}
