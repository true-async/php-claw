<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Exceptions\AgentException;
use Claw\Exceptions\HttpException;
use Claw\Http\HttpClientInterface;

/**
 * Anthropic Messages API backend.
 *
 * Uses the shared HttpClientInterface (raw HTTP via curl) rather than the
 * official SDK: the SDK's HTTP is synchronous and would block the single-thread
 * reactor. Transport/retry live in the HTTP client; this class only maps the
 * neutral types to/from the Messages API wire format.
 */
final class ClaudeAgent extends AbstractAgent
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private readonly string $anthropicVersion = '2023-06-01',
        AgentRetryPolicyInterface $retryPolicy = new BackoffAgentRetryPolicy(),
    ) {
        parent::__construct($retryPolicy);
    }

    protected function attempt(AgentRequest $request): AgentResponse
    {
        try {
            $response = $this->http->post(
                rtrim($this->baseUrl, '/') . '/v1/messages',
                json_encode(self::encodeRequest($request), JSON_THROW_ON_ERROR),
                [
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: ' . $this->anthropicVersion,
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
     * Map a neutral request to the Messages API request body. Pure.
     *
     * @return array<string, mixed>
     */
    public static function encodeRequest(AgentRequest $request): array
    {
        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'messages' => array_map(self::encodeMessage(...), $request->messages),
        ];

        if ($request->system !== '') {
            $body['system'] = $request->system;
        }

        if ($request->tools !== []) {
            $body['tools'] = array_map(static fn (ToolSpec $tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->inputSchema,
            ], $request->tools);
        }

        // temperature is rejected (400) by Opus 4.7+/Fable 5 — send only when set.
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        return $body;
    }

    /**
     * Map a Messages API response body to a neutral response. Pure. Unknown
     * block types (e.g. thinking) are ignored for v1.
     *
     * @param array<string, mixed> $data
     */
    public static function decodeResponse(array $data): AgentResponse
    {
        $content = [];
        $toolCalls = [];
        $texts = [];

        foreach ($data['content'] ?? [] as $block) {
            switch ($block['type'] ?? '') {
                case 'text':
                    $textBlock = new TextBlock((string) ($block['text'] ?? ''));
                    $content[] = $textBlock;
                    $texts[] = $textBlock->text;
                    break;

                case 'tool_use':
                    $useBlock = new ToolUseBlock(
                        (string) ($block['id'] ?? ''),
                        (string) ($block['name'] ?? ''),
                        (array) ($block['input'] ?? []),
                    );
                    $content[] = $useBlock;
                    $toolCalls[] = $useBlock;
                    break;
            }
        }

        return new AgentResponse(
            content: $content,
            toolCalls: $toolCalls,
            stopReason: StopReason::fromWire($data['stop_reason'] ?? null),
            usage: new Usage(
                (int) ($data['usage']['input_tokens'] ?? 0),
                (int) ($data['usage']['output_tokens'] ?? 0),
            ),
            text: $texts === [] ? null : implode('', $texts),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function encodeMessage(Message $message): array
    {
        return [
            'role' => $message->role->value,
            'content' => array_map(self::encodeBlock(...), $message->content),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function encodeBlock(ContentBlockInterface $block): array
    {
        return match (true) {
            $block instanceof TextBlock => ['type' => 'text', 'text' => $block->text],
            $block instanceof ToolUseBlock => [
                'type' => 'tool_use',
                'id' => $block->id,
                'name' => $block->name,
                'input' => (object) $block->input,
            ],
            $block instanceof ToolResultBlock => [
                'type' => 'tool_result',
                'tool_use_id' => $block->toolUseId,
                'content' => $block->content,
                'is_error' => $block->isError,
            ],
            default => throw new AgentException('Unknown content block: ' . $block::class),
        };
    }
}
