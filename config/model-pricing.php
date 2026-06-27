<?php

declare(strict_types=1);

/**
 * Model pricing — the basis for NORMALIZED tokens (see {@see \Claw\Agent\TokenPricing}).
 *
 * Prices are per 1M tokens, in any consistent unit (USD here) — only the RATIOS matter for
 * normalization, so the currency/scale is irrelevant as long as a model's three numbers share it:
 *   - input  — the standard input (prompt) token price
 *   - cached — the price of a cached input token (prompt-caching read); cheaper, so it weighs less
 *   - output — the completion token price; pricier, so it weighs more
 *
 * Normalized tokens express the run's real cost in FULL-input-token equivalents:
 *   normalized = (input - cached) + cached*(cached/input) + output*(output/input)
 *
 * Keys are matched by PREFIX against the model id (so `gpt-4o-mini-2024-07-18` matches `gpt-4o-mini`);
 * the longest matching prefix wins, and `default` is the fallback. Add or adjust a model by editing
 * this file — no code change. Override the path with CLAW_PRICING_FILE.
 *
 * @return array<string, array{input: float, cached: float, output: float}>
 */
return [
    // typical ratios when a model is unknown: cached at 50% off, output at 4x input
    'default'     => ['input' => 1.00, 'cached' => 0.50,  'output' => 4.00],

    'gpt-4o-mini' => ['input' => 0.15, 'cached' => 0.075, 'output' => 0.60],
    'gpt-4o'      => ['input' => 2.50, 'cached' => 1.25,  'output' => 10.00],
    'gpt-4.1'     => ['input' => 2.00, 'cached' => 0.50,  'output' => 8.00],
    'gpt-5'       => ['input' => 5.00, 'cached' => 0.50,  'output' => 30.00],

    'claude-3-5-haiku'  => ['input' => 0.80, 'cached' => 0.08, 'output' => 4.00],
    'claude-3-5-sonnet' => ['input' => 3.00, 'cached' => 0.30, 'output' => 15.00],
    'claude-sonnet-4'   => ['input' => 3.00, 'cached' => 0.30, 'output' => 15.00],
    'claude-opus-4'     => ['input' => 15.00, 'cached' => 1.50, 'output' => 75.00],
];
