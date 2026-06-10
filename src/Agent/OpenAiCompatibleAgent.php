<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Exceptions\HttpException;
use Claw\Http\HttpClientInterface;

/**
 * OpenAI-compatible Chat Completions backend. One class for DeepSeek, Groq,
 * Mistral, Qwen, Ollama, OpenRouter, OpenAI — they differ only by base URL,
 * model, and key.
 */
final class OpenAiCompatibleAgent extends AbstractAgent
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        AgentRetryPolicyInterface $retryPolicy = new BackoffAgentRetryPolicy(),
    ) {
        parent::__construct($retryPolicy);
    }

    public static function deepSeek(HttpClientInterface $http, string $apiKey): self
    {
        return new self($http, $apiKey, 'https://api.deepseek.com');
    }

    protected function attempt(AgentRequest $request): AgentResponse
    {
        try {
            $response = $this->http->post(
                rtrim($this->baseUrl, '/') . '/chat/completions',
                json_encode(self::encodeRequest($request), JSON_THROW_ON_ERROR),
                [
                    'authorization: Bearer ' . $this->apiKey,
                    'content-type: application/json',
                ],
            );
        } catch (HttpException $e) {
            throw AgentErrors::fromTransport($e);
        }

        if (!$response->isOk()) {
            throw AgentErrors::fromResponse($response);
        }

        return self::decodeResponse($response->json());
    }

    /**
     * Map a neutral request to a Chat Completions body. Pure. The system prompt
     * becomes a leading `system` message; tool results become `tool` messages.
     *
     * @return array<string, mixed>
     */
    public static function encodeRequest(AgentRequest $request): array
    {
        $messages = [];
        if ($request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }

        foreach ($request->messages as $message) {
            foreach (self::encodeMessage($message) as $encoded) {
                $messages[] = $encoded;
            }
        }

        $body = [
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens,
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(static fn (ToolSpec $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->inputSchema,
                ],
            ], $request->tools);
        }

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        return $body;
    }

    /**
     * Map a Chat Completions response to a neutral response. Pure.
     *
     * @param array<string, mixed> $data
     */
    public static function decodeResponse(array $data): AgentResponse
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $content = [];
        $toolCalls = [];
        $text = null;

        $contentText = $message['content'] ?? null;
        if (is_string($contentText) && $contentText !== '') {
            $content[] = new TextBlock($contentText);
            $text = $contentText;
        }

        foreach ($message['tool_calls'] ?? [] as $call) {
            $arguments = json_decode($call['function']['arguments'] ?? '{}', true);
            $useBlock = new ToolUseBlock(
                (string) ($call['id'] ?? ''),
                (string) ($call['function']['name'] ?? ''),
                is_array($arguments) ? $arguments : [],
            );
            $content[] = $useBlock;
            $toolCalls[] = $useBlock;
        }

        return new AgentResponse(
            content: $content,
            toolCalls: $toolCalls,
            stopReason: self::mapFinishReason($choice['finish_reason'] ?? null, $toolCalls !== []),
            usage: new Usage(
                (int) ($data['usage']['prompt_tokens'] ?? 0),
                (int) ($data['usage']['completion_tokens'] ?? 0),
            ),
            text: $text,
        );
    }

    /**
     * One neutral message may expand into several Chat Completions messages
     * (each tool result is its own `tool` message).
     *
     * @return list<array<string, mixed>>
     */
    private static function encodeMessage(Message $message): array
    {
        $toolResults = array_values(array_filter(
            $message->content,
            static fn (ContentBlock $block): bool => $block instanceof ToolResultBlock,
        ));

        if ($toolResults !== []) {
            return array_map(static fn (ToolResultBlock $block): array => [
                'role' => 'tool',
                'tool_call_id' => $block->toolUseId,
                'content' => $block->content,
            ], $toolResults);
        }

        if ($message->role === Role::Assistant) {
            $text = '';
            $toolCalls = [];
            foreach ($message->content as $block) {
                if ($block instanceof TextBlock) {
                    $text .= $block->text;
                } elseif ($block instanceof ToolUseBlock) {
                    $toolCalls[] = [
                        'id' => $block->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $block->name,
                            'arguments' => json_encode(
                                $block->input === [] ? new \stdClass() : $block->input,
                                JSON_THROW_ON_ERROR,
                            ),
                        ],
                    ];
                }
            }

            $encoded = ['role' => 'assistant', 'content' => $text === '' ? null : $text];
            if ($toolCalls !== []) {
                $encoded['tool_calls'] = $toolCalls;
            }

            return [$encoded];
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return [['role' => 'user', 'content' => $text]];
    }

    private static function mapFinishReason(?string $reason, bool $hasToolCalls): StopReason
    {
        return match ($reason) {
            'stop' => StopReason::EndTurn,
            'tool_calls' => StopReason::ToolUse,
            'length' => StopReason::MaxTokens,
            'content_filter' => StopReason::Refusal,
            default => $hasToolCalls ? StopReason::ToolUse : StopReason::Other,
        };
    }
}
