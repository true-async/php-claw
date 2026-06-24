<?php

declare(strict_types=1);

namespace Tests\Tool;

use function Async\delay;

use Claw\Exceptions\ToolException;
use Claw\Tool\Risk;
use Claw\Tool\ScheduleTool;
use Testo\Assert;
use Testo\Test;

final class ScheduleToolTest
{
    #[Test]
    public function deliversTheMessageAfterTheDelay(): void
    {
        /** @var list<string> $delivered */
        $delivered = [];
        $tool = new ScheduleTool(function (string $m) use (&$delivered): void {
            $delivered[] = $m;
        });

        $result = $tool->handle(['after_seconds' => 0.02, 'message' => 'stand up']);

        Assert::same($tool->risk(), Risk::Safe);
        Assert::true(str_contains($result, 'Scheduled'));
        Assert::same($delivered, []);   // nothing has fired yet

        delay(200);   // let the scheduled coroutine wake and deliver

        Assert::same($delivered, ['⏰ stand up']);
    }

    #[Test]
    public function rejectsNonPositiveDelay(): void
    {
        $threw = false;
        try {
            new ScheduleTool(static function (string $m): void {
            })->handle(['after_seconds' => 0, 'message' => 'x']);
        } catch (ToolException $e) {
            $threw = true;
        }

        Assert::true($threw);
    }

    #[Test]
    public function rejectsEmptyMessage(): void
    {
        $threw = false;
        try {
            new ScheduleTool(static function (string $m): void {
            })->handle(['after_seconds' => 1, 'message' => '  ']);
        } catch (ToolException $e) {
            $threw = true;
        }

        Assert::true($threw);
    }
}
