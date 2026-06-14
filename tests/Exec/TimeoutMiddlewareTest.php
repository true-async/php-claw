<?php

declare(strict_types=1);

namespace Tests\Exec;

use function Async\delay;

use Claw\Agent\ToolResultBlock;
use Claw\Exec\TimeoutMiddleware;
use Claw\Tool\ToolCall;
use Testo\Assert;
use Testo\Test;

final class TimeoutMiddlewareTest
{
    #[Test]
    public function returnsErrorWhenTheToolExceedsTheDeadline(): void
    {
        $mw = new TimeoutMiddleware(20);

        $result = $mw->handle(
            new ToolCall('1', 'slow', []),
            static function (ToolCall $c): ToolResultBlock {
                delay(300);   // outlives the 20ms deadline

                return new ToolResultBlock($c->id, 'late', false);
            },
        );

        Assert::true($result->isError);
        Assert::true(str_contains($result->content, 'timed out'));
    }

    #[Test]
    public function passesThroughWhenTheToolIsFastEnough(): void
    {
        $mw = new TimeoutMiddleware(500);

        $result = $mw->handle(
            new ToolCall('1', 'fast', []),
            static function (ToolCall $c): ToolResultBlock {
                delay(10);

                return new ToolResultBlock($c->id, 'quick', false);
            },
        );

        Assert::false($result->isError);
        Assert::same($result->content, 'quick');
    }
}
