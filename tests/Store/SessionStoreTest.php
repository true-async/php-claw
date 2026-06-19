<?php

declare(strict_types=1);

namespace Tests\Store;

use Claw\Agent\Message;
use Claw\Agent\Role;
use Claw\Agent\TextBlock;
use Claw\Agent\ToolResultBlock;
use Claw\Agent\ToolUseBlock;
use Claw\Store\SessionStore;
use Testo\Assert;
use Testo\Test;

final class SessionStoreTest
{
    #[Test]
    public function loadsEmptyWhenNoHistory(): void
    {
        $path = self::tempDb();

        try {
            Assert::same((new SessionStore($path))->load(), []);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function roundTripsEveryBlockTypeAcrossReopen(): void
    {
        $path = self::tempDb();

        try {
            (new SessionStore($path))->append(
                Message::userText('hello'),
                new Message(Role::Assistant, [new ToolUseBlock('u1', 'bash', ['command' => 'ls'])]),
                new Message(Role::User, [new ToolResultBlock('u1', 'file.txt', false)]),
                new Message(Role::Assistant, [new TextBlock('done')]),
            );

            // A fresh store on the same file simulates a restart.
            $loaded = (new SessionStore($path))->load();

            Assert::same(count($loaded), 4);

            Assert::same($loaded[0]->role, Role::User);
            $text = $loaded[0]->content[0];
            Assert::true($text instanceof TextBlock);
            Assert::same($text->text, 'hello');

            $use = $loaded[1]->content[0];
            Assert::true($use instanceof ToolUseBlock);
            Assert::same($use->id, 'u1');
            Assert::same($use->name, 'bash');
            Assert::same($use->input, ['command' => 'ls']);

            $result = $loaded[2]->content[0];
            Assert::true($result instanceof ToolResultBlock);
            Assert::same($result->toolUseId, 'u1');
            Assert::same($result->content, 'file.txt');
            Assert::false($result->isError);

            $last = $loaded[3]->content[0];
            Assert::true($last instanceof TextBlock);
            Assert::same($last->text, 'done');
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function persistsAlwaysAllowRulesAcrossReopen(): void
    {
        $path = self::tempDb();

        try {
            $store = new SessionStore($path);
            Assert::false($store->isToolAllowed('bash'));

            $store->allowTool('bash');

            // A fresh store on the same file still sees the rule.
            $reopened = new SessionStore($path);
            Assert::true($reopened->isToolAllowed('bash'));
            Assert::false($reopened->isToolAllowed('write_file'));
        } finally {
            @unlink($path);
        }
    }

    private static function tempDb(): string
    {
        return sys_get_temp_dir() . '/claw-store-' . uniqid('', true) . '.db';
    }
}
