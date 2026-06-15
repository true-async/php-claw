<?php

declare(strict_types=1);

namespace Claw\Chat;

use function Async\delay;

/**
 * One Telegram chat, bound to its chat_id. Inbound messages are queued by the
 * gateway (TelegramChat) into the inbox; receive() and confirm() consume them.
 * Replies go back out via sendMessage. There is no "typing" status in this
 * version, and a chat never closes (receive() waits for the next message).
 */
final class TelegramConversation implements ConversationInterface
{
    /** @var list<string> Inbound messages awaiting consumption. */
    private array $inbox = [];

    public function __construct(
        private readonly int $chatId,
        private readonly TelegramClient $client,
    ) {
    }

    public function id(): string
    {
        return (string) $this->chatId;
    }

    /** Queue an inbound message (called by the gateway). */
    public function deliver(string $text): void
    {
        $this->inbox[] = $text;
    }

    // Return type stays ?string to match ConversationInterface (other channels
    // return null at EOF); a Telegram chat never closes, so it always blocks for
    // the next message.
    public function receive(): ?string // @phpstan-ignore return.unusedType
    {
        while ($this->inbox === []) {
            delay(50);
        }

        return array_shift($this->inbox);
    }

    public function send(string $text): void
    {
        $this->client->sendMessage($this->chatId, $text);
    }

    public function confirm(string $prompt): Approval
    {
        // Inline buttons. Their callback_data are the same y/a/n tokens a typed
        // reply uses, so a click and a text reply both flow through deliver() into
        // the inbox — confirm() just waits for whichever arrives.
        $this->client->sendMessage($this->chatId, $prompt, [
            'inline_keyboard' => [[
                ['text' => '✅ Once', 'callback_data' => 'y'],
                ['text' => '♾️ Always', 'callback_data' => 'a'],
                ['text' => '✖️ No', 'callback_data' => 'n'],
            ]],
        ]);

        while ($this->inbox === []) {
            delay(50);
        }

        return Approval::fromInput((string) array_shift($this->inbox));
    }

    public function updateStatus(?Status $status): void
    {
        // Map an "in progress" status to Telegram's "typing…" action. A static
        // status (Done) or a cleared one needs nothing: the reply itself clears
        // the indicator, and Telegram expires it after ~5s anyway.
        if ($status !== null && $status->animated) {
            $this->client->sendChatAction($this->chatId, 'typing');
        }
    }
}
