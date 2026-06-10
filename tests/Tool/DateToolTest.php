<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\DateTool;
use Claw\Tool\Risk;
use Testo\Assert;
use Testo\Test;

final class DateToolTest
{
    #[Test]
    public function returnsCurrentDateInRequestedFormat(): void
    {
        $tool = new DateTool();

        Assert::same($tool->risk(), Risk::Safe);
        Assert::same($tool->handle(['format' => 'Y']), date('Y'));
        Assert::true((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $tool->handle([])));
    }

    #[Test]
    public function rejectsInvalidTimezone(): void
    {
        $threw = false;
        try {
            (new DateTool())->handle(['timezone' => 'Nowhere/Bad']);
        } catch (ToolException $e) {
            $threw = true;
        }

        Assert::true($threw);
    }
}
