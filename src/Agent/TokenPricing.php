<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * Normalized-token math, driven by a configurable per-model price table ({@see config/model-pricing.php}).
 *
 * Three numbers describe a run's token use: ABSOLUTE (raw input+output as the provider counts them, which
 * re-counts the resent history every turn), CACHED (the subset the provider served from its prompt cache,
 * billed cheaper), and NORMALIZED — the one that actually matters. Normalized expresses real cost in
 * FULL-input-token equivalents, weighting each token by its price relative to a standard input token:
 *
 *   normalized = (input - cached) + cached*(cachedPrice/inputPrice) + output*(outputPrice/inputPrice)
 *
 * so a cached token (cheap) weighs a fraction, an output token (dear) weighs several. The weights come
 * from the price table, so they are model-specific and edited in config, never hard-coded here.
 */
final class TokenPricing
{
    /** @var array{input: float, cached: float, output: float} */
    private const DEFAULT_RATES = ['input' => 1.0, 'cached' => 0.5, 'output' => 4.0];

    /** @param array<string, array{input: float, cached: float, output: float}> $prices per-1M, prefix-keyed */
    public function __construct(private readonly array $prices)
    {
    }

    /** The process-wide instance, loaded once from the configured price file. */
    public static function shared(): self
    {
        static $shared = null;

        return $shared ??= self::fromFile();
    }

    /** Load the price table from CLAW_PRICING_FILE, else the bundled config/model-pricing.php. */
    public static function fromFile(?string $path = null): self
    {
        $path ??= (getenv('CLAW_PRICING_FILE') ?: \dirname(__DIR__, 2) . '/config/model-pricing.php');
        $prices = is_file($path) ? require $path : [];

        return new self(\is_array($prices) ? $prices : []);
    }

    /**
     * Normalized tokens for one model round-trip: real cost in full-input-token equivalents. $input is the
     * provider's total prompt tokens (cached included); $cached is the cached subset.
     */
    public function normalized(int $input, int $cached, int $output, string $model): int
    {
        $rates = $this->ratesFor($model);
        $inPrice = $rates['input'] > 0 ? $rates['input'] : 1.0;
        $uncached = max(0, $input - $cached);

        $weighted = $uncached
            + $cached * ($rates['cached'] / $inPrice)
            + $output * ($rates['output'] / $inPrice);

        return (int) round($weighted);
    }

    /**
     * Real money cost of one round-trip, in MICRO-units of the price table's currency (so it stays an
     * integer summable in the trace; divide by 1e6 for the headline figure). Prices are per 1M tokens,
     * so micro-cost = uncached*inputPrice + cached*cachedPrice + output*outputPrice (the 1e6s cancel).
     */
    public function costMicros(int $input, int $cached, int $output, string $model): int
    {
        $rates = $this->ratesFor($model);
        $uncached = max(0, $input - $cached);

        return (int) round(
            $uncached * $rates['input']
            + $cached * $rates['cached']
            + $output * $rates['output'],
        );
    }

    /**
     * The price row for a model: the longest-prefix match, else `default`, else built-in defaults.
     *
     * @return array{input: float, cached: float, output: float}
     */
    private function ratesFor(string $model): array
    {
        $best = $this->prices['default'] ?? self::DEFAULT_RATES;
        $bestLen = -1;

        foreach ($this->prices as $prefix => $rates) {
            if ($prefix !== 'default' && \strlen($prefix) > $bestLen && str_starts_with($model, $prefix)) {
                $best = $rates;
                $bestLen = \strlen($prefix);
            }
        }

        return $best;
    }
}
