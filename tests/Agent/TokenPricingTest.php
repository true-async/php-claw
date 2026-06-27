<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\TokenPricing;
use Testo\Assert;
use Testo\Test;

final class TokenPricingTest
{
    private function pricing(): TokenPricing
    {
        return new TokenPricing([
            'default'     => ['input' => 1.00, 'cached' => 0.50,  'output' => 4.00],
            'gpt-4o-mini' => ['input' => 0.15, 'cached' => 0.075, 'output' => 0.60],
            'gpt-5'       => ['input' => 5.00, 'cached' => 0.50,  'output' => 30.0],
        ]);
    }

    #[Test]
    public function uncachedInputCountsAtFullWeight(): void
    {
        Assert::same($this->pricing()->normalized(1000, 0, 0, 'gpt-4o-mini'), 1000);
    }

    #[Test]
    public function cachedInputWeighsItsDiscount(): void
    {
        // 1000 input, all cached -> cached weight = 0.075/0.15 = 0.5 -> 500
        Assert::same($this->pricing()->normalized(1000, 1000, 0, 'gpt-4o-mini'), 500);
    }

    #[Test]
    public function outputWeighsItsPremium(): void
    {
        // gpt-4o-mini output weight = 0.60/0.15 = 4 -> 1000 output = 4000
        Assert::same($this->pricing()->normalized(0, 0, 1000, 'gpt-4o-mini'), 4000);
    }

    #[Test]
    public function weightsAreModelSpecific(): void
    {
        // gpt-5 output weight = 30/5 = 6 -> 1000 output = 6000 (vs 4000 on gpt-4o-mini)
        Assert::same($this->pricing()->normalized(0, 0, 1000, 'gpt-5'), 6000);
    }

    #[Test]
    public function modelMatchesByPrefix(): void
    {
        Assert::same($this->pricing()->normalized(0, 0, 1000, 'gpt-5-turbo-2026-01'), 6000);
    }

    #[Test]
    public function unknownModelFallsBackToDefault(): void
    {
        // default output weight = 4 -> 4000
        Assert::same($this->pricing()->normalized(0, 0, 1000, 'mistral-large'), 4000);
    }

    #[Test]
    public function mixedRealisticReplyNormalizes(): void
    {
        // 1000 input (400 cached), 200 output on gpt-4o-mini:
        // uncached 600*1 + cached 400*0.5 + output 200*4 = 600 + 200 + 800 = 1600
        Assert::same($this->pricing()->normalized(1000, 400, 200, 'gpt-4o-mini'), 1600);
    }

    #[Test]
    public function costMicrosIsRealMoneyInMicroUnits(): void
    {
        // gpt-4o-mini prices per 1M: in 0.15, cached 0.075, out 0.60. micro-cost = uncached*in + cached*ca + out*out.
        // 1000 input (400 cached) + 200 output: 600*0.15 + 400*0.075 + 200*0.60 = 90 + 30 + 120 = 240 micro = $0.000240
        Assert::same($this->pricing()->costMicros(1000, 400, 200, 'gpt-4o-mini'), 240);
        Assert::same($this->pricing()->costMicros(0, 0, 1000, 'gpt-4o-mini'), 600);   // output only
    }
}
