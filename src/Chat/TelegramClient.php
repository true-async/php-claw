<?php

declare(strict_types=1);

namespace Claw\Chat;

use Claw\Exceptions\HttpException;
use Claw\Http\HttpClientInterface;

/**
 * Thin wrapper over the Telegram Bot API (plain HTTPS): long-poll for updates and
 * send messages. Uses the shared async HTTP client, so getUpdates parks the
 * coroutine for the long-poll duration without blocking the thread.
 */
final readonly class TelegramClient
{
    private string $base;

    public function __construct(
        private HttpClientInterface $http,
        string $token,
    ) {
        $this->base = 'https://api.telegram.org/bot' . $token . '/';
    }

    /**
     * Long-poll for message updates after $offset.
     *
     * @return list<array<string, mixed>> the raw updates (may be empty)
     *
     * @throws HttpException on transport failure or a Telegram API error
     */
    public function getUpdates(int $offset, int $timeoutSeconds = 25): array
    {
        $url = $this->base . 'getUpdates?' . http_build_query([
            'offset' => $offset,
            'timeout' => $timeoutSeconds,
            'allowed_updates' => '["message","callback_query"]',
        ]);

        $data = $this->http->get($url)->json();

        if (($data['ok'] ?? false) !== true) {
            throw new HttpException('Telegram getUpdates failed: ' . json_encode($data['description'] ?? $data));
        }

        $result = $data['result'] ?? [];

        if (!\is_array($result)) {
            return [];
        }

        $updates = [];

        foreach ($result as $item) {
            if (\is_array($item)) {
                /** @var array<string, mixed> $item */
                $updates[] = $item;
            }
        }

        return $updates;
    }

    /**
     * @param array<string, mixed>|null $replyMarkup optional inline keyboard, etc.
     */
    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $this->call('sendMessage', $payload);
    }

    /** Show a transient chat action (e.g. "typing…"); Telegram clears it after ~5s. */
    public function sendChatAction(int $chatId, string $action): void
    {
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
    }

    /** Acknowledge a button press so Telegram stops showing the client-side spinner. */
    public function answerCallbackQuery(string $callbackQueryId): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackQueryId]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function call(string $method, array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Telegram returns HTTP 200 with {"ok":false,"description":...} for application-level failures
        // (message too long, chat blocked, bad markup). Check ok like getUpdates so a failed write does
        // not silently look like success.
        $data = $this->http->post($this->base . $method, $body, ['Content-Type: application/json'])->json();

        if (($data['ok'] ?? false) !== true) {
            throw new HttpException("Telegram {$method} failed: " . json_encode($data['description'] ?? $data));
        }
    }
}
