<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\AgentRequest;
use Claw\Agent\BackoffAgentRetryPolicy;
use Claw\Agent\ClaudeAgent;
use Claw\Agent\Message;
use Claw\Agent\Role;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\ToolResultBlock;
use Claw\Agent\ToolSpec;
use Claw\Agent\ToolUseBlock;
use Claw\Exceptions\AgentException;
use Claw\Exceptions\HttpException;
use Claw\Http\HttpResponse;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeHttpClient;
use Tests\Support\ScriptedHttpClient;

final class ClaudeAgentTest
{
    #[Test]
    public function encodesRequestToMessagesApiShape(): void
    {
        $request = new AgentRequest(
            model: 'claude-test',
            messages: [
                Message::userText('hi'),
                new Message(Role::Assistant, [
                    new TextBlock('let me check'),
                    new ToolUseBlock('toolu_1', 'bash', ['cmd' => 'ls']),
                ]),
                new Message(Role::User, [
                    new ToolResultBlock('toolu_1', 'file.txt', false),
                ]),
            ],
            maxTokens: 1024,
            system: 'You are Claw.',
            tools: [
                new ToolSpec('bash', 'Run a shell command', [
                    'type' => 'object',
                    'properties' => ['cmd' => ['type' => 'string']],
                    'required' => ['cmd'],
                ]),
            ],
        );

        // Normalize objects -> arrays through JSON so assertions are simple.
        $wire = json_decode(json_encode(ClaudeAgent::encodeRequest($request), JSON_THROW_ON_ERROR), true);

        Assert::same($wire['model'], 'claude-test');
        Assert::same($wire['max_tokens'], 1024);
        Assert::same($wire['system'], 'You are Claw.');
        Assert::same($wire['tools'][0]['name'], 'bash');
        Assert::same($wire['tools'][0]['input_schema']['required'], ['cmd']);
        Assert::false(array_key_exists('temperature', $wire));

        Assert::same($wire['messages'][0]['role'], 'user');
        Assert::same($wire['messages'][0]['content'][0], ['type' => 'text', 'text' => 'hi']);

        Assert::same($wire['messages'][1]['role'], 'assistant');
        Assert::same($wire['messages'][1]['content'][1], [
            'type' => 'tool_use',
            'id' => 'toolu_1',
            'name' => 'bash',
            'input' => ['cmd' => 'ls'],
        ]);

        Assert::same($wire['messages'][2]['content'][0], [
            'type' => 'tool_result',
            'tool_use_id' => 'toolu_1',
            'content' => 'file.txt',
            'is_error' => false,
        ]);
    }

    #[Test]
    public function omitsOptionalFieldsAndIncludesTemperatureWhenSet(): void
    {
        $bare = ClaudeAgent::encodeRequest(new AgentRequest(
            model: 'm',
            messages: [Message::userText('x')],
        ));
        Assert::false(array_key_exists('system', $bare));
        Assert::false(array_key_exists('tools', $bare));
        Assert::false(array_key_exists('temperature', $bare));

        $withTemp = ClaudeAgent::encodeRequest(new AgentRequest(
            model: 'm',
            messages: [Message::userText('x')],
            temperature: 0.5,
        ));
        Assert::same($withTemp['temperature'], 0.5);
    }

    #[Test]
    public function decodesToolUseResponse(): void
    {
        $response = ClaudeAgent::decodeResponse([
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
            'content' => [
                ['type' => 'text', 'text' => 'Working on it.'],
                ['type' => 'thinking', 'thinking' => 'ignored'],
                ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'read_file', 'input' => ['path' => '/x']],
            ],
        ]);

        Assert::same($response->stopReason, StopReason::ToolUse);
        Assert::same($response->usage->inputTokens, 12);
        Assert::same($response->usage->outputTokens, 7);
        Assert::same($response->text, 'Working on it.');
        Assert::same(count($response->content), 2);   // thinking block dropped
        Assert::same(count($response->toolCalls), 1);
        Assert::same($response->toolCalls[0]->id, 'toolu_9');
        Assert::same($response->toolCalls[0]->name, 'read_file');
        Assert::same($response->toolCalls[0]->input, ['path' => '/x']);
        Assert::true($response->wantsToolUse());
    }

    #[Test]
    public function decodesPlainTextResponse(): void
    {
        $response = ClaudeAgent::decodeResponse([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Done.']],
        ]);

        Assert::same($response->stopReason, StopReason::EndTurn);
        Assert::same($response->text, 'Done.');
        Assert::same($response->toolCalls, []);
        Assert::false($response->wantsToolUse());
    }

    #[Test]
    public function mapsStopReasons(): void
    {
        Assert::same(StopReason::fromWire('end_turn'), StopReason::EndTurn);
        Assert::same(StopReason::fromWire('max_tokens'), StopReason::MaxTokens);
        Assert::same(StopReason::fromWire('refusal'), StopReason::Refusal);
        Assert::same(StopReason::fromWire('pause_turn'), StopReason::Other);
        Assert::same(StopReason::fromWire(null), StopReason::Other);
    }

    #[Test]
    public function sendPostsToMessagesEndpointAndDecodes(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, json_encode([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'hi there']],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
        ], JSON_THROW_ON_ERROR)));

        $agent = new ClaudeAgent($http, 'sk-test', 'https://api.anthropic.com');
        $response = $agent->send(new AgentRequest('m', [Message::userText('hi')]));

        Assert::same($response->text, 'hi there');
        Assert::same($http->lastUrl, 'https://api.anthropic.com/v1/messages');
        Assert::true(in_array('x-api-key: sk-test', $http->lastHeaders, true));
        Assert::true(in_array('anthropic-version: 2023-06-01', $http->lastHeaders, true));
    }

    #[Test]
    public function sendThrowsAgentExceptionOnApiError(): void
    {
        $http = new FakeHttpClient(new HttpResponse(400, json_encode([
            'error' => ['message' => 'bad model'],
        ], JSON_THROW_ON_ERROR)));
        $agent = new ClaudeAgent($http, 'sk-test');

        $threw = false;
        try {
            $agent->send(new AgentRequest('m', [Message::userText('x')]));
        } catch (AgentException $e) {
            $threw = true;
            Assert::true(str_contains($e->getMessage(), 'bad model'));
        }

        Assert::true($threw);
    }

    #[Test]
    public function wrapsTransportErrorAsAgentException(): void
    {
        $agent = new ClaudeAgent(
            new ScriptedHttpClient(new HttpException('connection reset')),
            'sk-test',
            retryPolicy: new BackoffAgentRetryPolicy(maxAttempts: 1),   // no retry, assert classification
        );

        $threw = false;
        try {
            $agent->send(new AgentRequest('m', [Message::userText('x')]));
        } catch (AgentException $e) {
            $threw = true;
            Assert::true(str_contains($e->getMessage(), 'transport'));
        }

        Assert::true($threw);
    }
}
