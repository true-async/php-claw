<?php

declare(strict_types=1);

namespace Tests\Chat;

use Claw\Chat\TelegramChat;
use Claw\Chat\TelegramClient;
use Claw\Http\HttpResponse;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeHttpClient;

final class TelegramChatTest
{
    #[Test]
    public function routesAuthorizedDmAndDemuxesByChat(): void
    {
        $chat = $this->chat([111]);

        $chat->ingest(self::dm(fromId: 111, chatId: 111, text: 'hello'));

        $conversation = $chat->accept();
        Assert::same($conversation->id(), '111');
        Assert::same($conversation->receive(), 'hello');

        // A second message from the same chat reuses the conversation (no new accept).
        $chat->ingest(self::dm(fromId: 111, chatId: 111, text: 'again'));
        Assert::same($conversation->receive(), 'again');
    }

    #[Test]
    public function dropsUnauthorizedGroupAndNonTextUpdates(): void
    {
        $chat = $this->chat([111]);

        $chat->ingest(self::dm(fromId: 999, chatId: 999, text: 'sneaky'));      // not on allowlist
        $chat->ingest(self::group(fromId: 111, chatId: -100, text: 'in group')); // group, even from owner
        $chat->ingest(['message' => ['from' => ['id' => 111], 'chat' => ['id' => 111, 'type' => 'private']]]); // no text

        // The next authorized DM is the FIRST thing accept() yields — proving the
        // three dropped updates created no conversation.
        $chat->ingest(self::dm(fromId: 111, chatId: 111, text: 'ok'));

        $conversation = $chat->accept();
        Assert::same($conversation->id(), '111');
        Assert::same($conversation->receive(), 'ok');
    }

    /**
     * @param list<int> $allowed
     */
    private function chat(array $allowed): TelegramChat
    {
        $client = new TelegramClient(new FakeHttpClient(new HttpResponse(200, '{"ok":true,"result":[]}')), 'TOK');

        return new TelegramChat($client, static fn (int $id): bool => \in_array($id, $allowed, true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function dm(int $fromId, int $chatId, string $text): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'from' => ['id' => $fromId],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function group(int $fromId, int $chatId, string $text): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'from' => ['id' => $fromId],
                'chat' => ['id' => $chatId, 'type' => 'group'],
                'text' => $text,
            ],
        ];
    }
}
