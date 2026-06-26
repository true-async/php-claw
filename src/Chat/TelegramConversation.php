<?php

declare(strict_types=1);

namespace Claw\Chat;

use Async\Coroutine;

use function Async\delay;
use function Async\spawn;

/**
 * One Telegram chat, bound to its chat_id. Inbound messages are queued by the
 * gateway (TelegramChat) into the inbox; receive() and confirm() consume them.
 * Replies go back out via sendMessage, and an "in progress" status shows as
 * Telegram's "typing…" action — refreshed by a keep-alive coroutine for as long
 * as the agent works. A chat never closes (receive() waits for the next message).
 */
final class TelegramConversation implements ConversationInterface
{
    /** @var list<string> Inbound messages awaiting consumption. */
    private array $inbox = [];

    /** @var Coroutine<mixed>|null Keep-alive coroutine re-sending "typing…" while the agent works. */
    private ?Coroutine $typing = null;

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
        $this->cancelTyping();   // the reply itself ends the "typing…" indicator
        $this->client->sendMessage($this->chatId, $text);
    }

    public function showDeferred(string $message): void
    {
        // No local UI on Telegram; queued messages need no separate display.
    }

    public function flushDeferred(): void
    {
    }

    public function confirm(string $prompt): Approval
    {
        $this->cancelTyping();   // permanent output ends the "typing…" keep-alive, same as send()

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
        // Map an "in progress" status to Telegram's "typing…" action. Telegram
        // expires it after ~5s, so we show it now and a keep-alive coroutine
        // refreshes it until the status clears. A static "Done"/null status stops
        // the refresh — the reply (or the ~5s lapse) clears the indicator.
        if ($status !== null && $status->animated) {
            if ($this->typing === null) {
                $this->client->sendChatAction($this->chatId, 'typing');   // show it now
                $this->typing = spawn($this->keepTyping(...));            // then keep it alive
            }

            return;
        }

        $this->cancelTyping();
    }

    /** Re-send "typing…" before Telegram lets the ~5s action lapse, for the whole think. */
    private function keepTyping(): void
    {
        for (;;) {
            delay(4000);   // already shown once by updateStatus(); refresh before ~5s lapse
            $this->client->sendChatAction($this->chatId, 'typing');
        }
    }

    private function cancelTyping(): void
    {
        if ($this->typing !== null) {
            $this->typing->cancel();
            $this->typing = null;
        }
    }
}
