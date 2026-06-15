<?php

declare(strict_types=1);

namespace Claw\Chat;

use function Async\delay;

/**
 * Telegram gateway. A single long-poll loop pulls updates and routes each one:
 * authorize the sender against the allowlist, demultiplex by chat_id, and queue
 * the text on that chat's Conversation. A new authorized chat surfaces from
 * accept(); the main loop spawns one Session per Conversation.
 *
 * Authorization is the must-have: a bot is public, so an unauthorized sender is
 * silently dropped (no reply — a reply would confirm the bot to a prober). DMs
 * only for now; group updates are ignored.
 */
final class TelegramChat implements ChatInterface
{
    /** @var array<int, TelegramConversation> chat_id => conversation */
    private array $conversations = [];

    /** @var list<TelegramConversation> authorized chats awaiting accept() */
    private array $pending = [];

    private int $offset = 0;

    /**
     * @param \Closure(int): bool $isAllowed authorizes a sender by user id
     */
    public function __construct(
        private readonly TelegramClient $client,
        private readonly \Closure $isAllowed,
    ) {
    }

    /** Long-poll Telegram forever, routing each update. Spawn this once. */
    public function poll(): void
    {
        for (;;) {
            try {
                foreach ($this->client->getUpdates($this->offset) as $update) {
                    $this->offset = max($this->offset, (int) ($update['update_id'] ?? 0) + 1);
                    $this->ingest($update);
                }
            } catch (\Throwable $e) {
                // Network blip or API hiccup: back off so we don't hot-loop.
                fwrite(STDERR, 'telegram: poll error: ' . $e->getMessage() . "\n");
                delay(2000);
            }
        }
    }

    public function accept(): ConversationInterface
    {
        while ($this->pending === []) {
            delay(50);
        }

        return array_shift($this->pending);
    }

    /**
     * Route one raw Telegram update: authorize, demultiplex by chat, queue the
     * text. Unauthorized senders, non-DM chats and non-text messages are dropped.
     * Driven by poll(); public so the routing can be tested without the network.
     *
     * @param array<string, mixed> $update
     */
    public function ingest(array $update): void
    {
        $callback = $update['callback_query'] ?? null;
        if (\is_array($callback)) {
            $this->ingestCallback($callback);

            return;
        }

        $message = $update['message'] ?? null;
        if (!\is_array($message)) {
            return;
        }

        $chat = \is_array($message['chat'] ?? null) ? $message['chat'] : [];
        if (($chat['type'] ?? null) !== 'private') {
            return;   // DMs only for now
        }

        $from = \is_array($message['from'] ?? null) ? $message['from'] : [];
        $userId = (int) ($from['id'] ?? 0);
        if (!($this->isAllowed)($userId)) {
            // Silent drop. Log the id so the owner can find it for the allowlist.
            fwrite(STDERR, "telegram: dropped message from unauthorized id {$userId}\n");

            return;
        }

        $text = $message['text'] ?? null;
        if (!\is_string($text) || trim($text) === '') {
            return;
        }

        $chatId = (int) ($chat['id'] ?? $userId);
        $conversation = $this->conversations[$chatId] ?? null;
        if ($conversation === null) {
            $conversation = new TelegramConversation($chatId, $this->client);
            $this->conversations[$chatId] = $conversation;
            $this->pending[] = $conversation;   // accept() hands it to a new Session
        }

        $conversation->deliver(trim($text));
    }

    /**
     * Route a button press: authorize the clicker, acknowledge it, and deliver its
     * data token to the chat's pending confirm() — the same path as a typed reply.
     *
     * @param array<string, mixed> $callback
     */
    private function ingestCallback(array $callback): void
    {
        $from = \is_array($callback['from'] ?? null) ? $callback['from'] : [];
        if (!($this->isAllowed)((int) ($from['id'] ?? 0))) {
            return;
        }

        $id = (string) ($callback['id'] ?? '');
        if ($id !== '') {
            $this->client->answerCallbackQuery($id);
        }

        $message = \is_array($callback['message'] ?? null) ? $callback['message'] : [];
        $chat = \is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = (int) ($chat['id'] ?? 0);
        $data = $callback['data'] ?? null;

        $conversation = $this->conversations[$chatId] ?? null;
        if ($conversation !== null && \is_string($data)) {
            $conversation->deliver($data);
        }
    }
}
