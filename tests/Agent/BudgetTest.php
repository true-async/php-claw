<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\Budget;
use Testo\Assert;
use Testo\Test;

final class BudgetTest
{
    #[Test]
    public function tokenLimitExhaustsOnceSpent(): void
    {
        $budget = new Budget(tokenLimit: 100);

        $budget->spend(60);
        Assert::false($budget->isExhausted());

        $budget->spend(40);
        Assert::true($budget->isExhausted());   // 100 >= 100
    }

    #[Test]
    public function timeLimitExhaustsAsTheClockAdvances(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $budget = new Budget(secondsLimit: 5.0, clock: $clock);

        $now = 4.9;
        Assert::false($budget->isExhausted());

        $now = 5.0;
        Assert::true($budget->isExhausted());
    }

    #[Test]
    public function spendingAChildChargesTheParentToo(): void
    {
        $root = new Budget(tokenLimit: 100);
        $child = $root->child(tokenLimit: 30);

        $child->spend(30);

        Assert::true($child->isExhausted());    // its own 30-token cap is hit
        Assert::same($root->tokens(), 30);      // and the parent was charged
        Assert::false($root->isExhausted());    // but the run total (100) is not yet spent
    }

    #[Test]
    public function aChildIsExhaustedWhenItsParentIs(): void
    {
        $root = new Budget(tokenLimit: 50);
        $child = $root->child();   // the child itself is unlimited

        $child->spend(50);

        Assert::true($child->isExhausted());   // exhausted via the parent
        Assert::true($root->isExhausted());
    }

    #[Test]
    public function zeroLimitsNeverExhaust(): void
    {
        $budget = new Budget();   // 0 tokens, 0 seconds = unlimited

        $budget->spend(1_000_000);

        Assert::false($budget->isExhausted());
    }
}
