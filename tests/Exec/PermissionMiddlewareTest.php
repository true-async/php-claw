<?php

declare(strict_types=1);

namespace Tests\Exec;

use Claw\Agent\ToolResultBlock;
use Claw\Chat\Approval;
use Claw\Exec\PermissionMiddleware;
use Claw\Permission\Policy;
use Claw\Tool\Effect;
use Claw\Tool\Registry;
use Claw\Tool\Risk;
use Claw\Tool\ToolCall;
use Claw\Tool\ToolInterface;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeConversation;

final class PermissionMiddlewareTest
{
    #[Test]
    public function safeToolPassesThroughToNext(): void
    {
        $mw = new PermissionMiddleware(new Policy(), $this->registry('read', Risk::Safe), new FakeConversation());

        $result = $mw->handle(
            new ToolCall('1', 'read', []),
            static fn (ToolCall $c): ToolResultBlock => new ToolResultBlock($c->id, 'ran', false),
        );

        Assert::same($result->content, 'ran');
        Assert::false($result->isError);
    }

    #[Test]
    public function dangerousToolIsBlockedWithoutRunning(): void
    {
        /** @var \ArrayObject<int, int> $reached */
        $reached = new \ArrayObject();
        $mw = new PermissionMiddleware(new Policy(), $this->registry('eval', Risk::Dangerous), new FakeConversation());

        $result = $mw->handle(
            new ToolCall('1', 'eval', []),
            static function (ToolCall $c) use ($reached): ToolResultBlock {
                $reached[] = 1;

                return new ToolResultBlock($c->id, 'ran', false);
            },
        );

        Assert::true($result->isError);
        Assert::true(str_contains($result->content, 'blocked'));
        Assert::same(count($reached), 0);
    }

    #[Test]
    public function mutatingToolAsksAndDeniesOnNo(): void
    {
        $conversation = new FakeConversation();
        $conversation->confirmReplies = [Approval::No];
        $mw = new PermissionMiddleware(new Policy(), $this->registry('bash', Risk::Mutating), $conversation);

        $result = $mw->handle(
            new ToolCall('1', 'bash', ['command' => 'ls']),
            static fn (ToolCall $c): ToolResultBlock => new ToolResultBlock($c->id, 'ran', false),
        );

        Assert::true($result->isError);
        Assert::true(str_contains($result->content, 'denied'));
        Assert::same(count($conversation->confirmed), 1);
    }

    private function registry(string $name, Risk $risk): Registry
    {
        $registry = new Registry();
        $registry->add(new class ($name, $risk) implements ToolInterface {
            public function __construct(
                private string $toolName,
                private Risk $toolRisk,
            ) {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return '';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function effects(): array
            {
                return [Effect::Read, Effect::Write];
            }

            public function risk(): Risk
            {
                return $this->toolRisk;
            }

            public function handle(array $input): string
            {
                return 'ran';
            }
        });

        return $registry;
    }
}
