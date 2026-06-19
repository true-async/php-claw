<?php

declare(strict_types=1);

namespace Tests\Chat;

use Claw\Agent\Usage;
use Claw\Chat\Approval;
use Claw\Chat\Status;
use Claw\Chat\TelegramClient;
use Claw\Chat\TelegramConversation;
use Claw\Http\HttpResponse;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeHttpClient;

final class TelegramConversationTest
{
    #[Test]
    public function updateStatusSendsTypingWhenAnimated(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"ok":true}'));
        $conversation = new TelegramConversation(7, new TelegramClient($http, 'TOK'));

        $conversation->updateStatus(Status::typing());

        Assert::true(str_contains((string) $http->lastUrl, '/sendChatAction'));

        $sent = json_decode((string) $http->lastBody, true);
        $sent = is_array($sent) ? $sent : [];
        Assert::same($sent['chat_id'] ?? null, 7);
        Assert::same($sent['action'] ?? null, 'typing');

        $conversation->updateStatus(Status::done(new Usage()));   // stop the keep-alive coroutine
    }

    #[Test]
    public function confirmSendsButtonsAndReadsTheAnswer(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"ok":true}'));
        $conversation = new TelegramConversation(7, new TelegramClient($http, 'TOK'));

        $pending = \Async\spawn(static fn (): Approval => $conversation->confirm('Allow bash?'));
        \Async\delay(20);              // let confirm send the prompt and start waiting
        $conversation->deliver('a');   // simulate the "Always" button (callback_data "a")
        $answer = \Async\await($pending);

        Assert::same($answer, Approval::Always);

        // The prompt carried an inline keyboard.
        $sent = json_decode((string) $http->lastBody, true);
        $sent = is_array($sent) ? $sent : [];
        Assert::true(isset($sent['reply_markup']));
    }

    #[Test]
    public function updateStatusIsSilentWhenClearedOrDone(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"ok":true}'));
        $conversation = new TelegramConversation(7, new TelegramClient($http, 'TOK'));

        $conversation->updateStatus(null);
        $conversation->updateStatus(Status::done(new Usage()));

        Assert::same($http->lastUrl, null);   // no API call was made
    }
}
