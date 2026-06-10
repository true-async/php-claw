<?php

declare(strict_types=1);

namespace Tests;

use Claw\Scheduler;
use Testo\Assert;
use Testo\Test;

final class SchedulerTest
{
    #[Test]
    public function runsJobWhenDueAndReschedules(): void
    {
        $hits = 0;
        $scheduler = new Scheduler();
        $scheduler->every(1000, function () use (&$hits): void {
            $hits++;
        });

        Assert::same($scheduler->tick(500), 0);    // not due yet
        Assert::same($scheduler->tick(1000), 1);   // due
        Assert::same($scheduler->tick(1500), 0);   // rescheduled to 2000
        Assert::same($scheduler->tick(2000), 1);   // due again

        Assert::same($hits, 2);
    }

    #[Test]
    public function handlesMultipleIndependentIntervals(): void
    {
        $a = 0;
        $b = 0;
        $scheduler = new Scheduler();
        $scheduler->every(1000, function () use (&$a): void {
            $a++;
        });
        $scheduler->every(2000, function () use (&$b): void {
            $b++;
        });

        $scheduler->tick(1000);   // a
        $scheduler->tick(2000);   // a + b

        Assert::same($a, 2);
        Assert::same($b, 1);
    }

    #[Test]
    public function rejectsNonPositiveInterval(): void
    {
        $threw = false;
        try {
            (new Scheduler())->every(0, static fn (): null => null);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }

        Assert::true($threw);
    }
}
