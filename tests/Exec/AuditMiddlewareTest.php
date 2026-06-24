<?php

declare(strict_types=1);

namespace Tests\Exec;

use Claw\Agent\ToolResultBlock;
use Claw\Exec\AuditMiddleware;
use Claw\Store\SessionStore;
use Claw\Tool\ToolCall;
use Testo\Assert;
use Testo\Test;

final class AuditMiddlewareTest
{
    #[Test]
    public function logsTheCallAndItsOutcome(): void
    {
        $path = sys_get_temp_dir() . '/claw-audit-' . uniqid('', true) . '.db';

        try {
            $store = new SessionStore($path);

            new AuditMiddleware($store)->handle(
                new ToolCall('1', 'bash', ['command' => 'ls']),
                static fn (ToolCall $c): ToolResultBlock => new ToolResultBlock($c->id, 'output', false),
            );

            $trail = $store->auditTrail();
            Assert::same(count($trail), 1);
            Assert::true(str_contains($trail[0]['call'], 'bash'));
            Assert::same($trail[0]['result'], 'output');
            Assert::false($trail[0]['isError']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function withoutAStoreItJustPassesThrough(): void
    {
        $result = new AuditMiddleware(null)->handle(
            new ToolCall('1', 'x', []),
            static fn (ToolCall $c): ToolResultBlock => new ToolResultBlock($c->id, 'ran', false),
        );

        Assert::same($result->content, 'ran');
    }
}
