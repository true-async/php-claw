<?php

declare(strict_types=1);

namespace Tests\Chat;

use Claw\Chat\TelegramClient;
use Claw\Exceptions\HttpException;
use Claw\Http\HttpResponse;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeHttpClient;

final class TelegramClientTest
{
    #[Test]
    public function getUpdatesParsesResults(): void
    {
        $body = '{"ok":true,"result":[{"update_id":10,"message":{"text":"hi"}}]}';
        $client = new TelegramClient(new FakeHttpClient(new HttpResponse(200, $body)), 'TOK');

        $updates = $client->getUpdates(0);

        Assert::same(count($updates), 1);
        Assert::same($updates[0]['update_id'] ?? null, 10);
    }

    #[Test]
    public function getUpdatesThrowsOnApiError(): void
    {
        $client = new TelegramClient(
            new FakeHttpClient(new HttpResponse(401, '{"ok":false,"description":"Unauthorized"}')),
            'TOK',
        );

        $threw = false;
        try {
            $client->getUpdates(0);
        } catch (HttpException $e) {
            $threw = true;
        }

        Assert::true($threw);
    }

    #[Test]
    public function sendMessagePostsChatIdAndText(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"ok":true}'));

        new TelegramClient($http, 'TOK')->sendMessage(42, 'hello');

        Assert::true(str_contains((string) $http->lastUrl, '/sendMessage'));

        $sent = json_decode((string) $http->lastBody, true);
        $sent = is_array($sent) ? $sent : [];
        Assert::same($sent['chat_id'] ?? null, 42);
        Assert::same($sent['text'] ?? null, 'hello');
    }
}
