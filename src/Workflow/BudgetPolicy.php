<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * What a run does when its TOTAL budget is spent — set explicitly (default {@see Stop}) and carried
 * in {@see EnvKey::BudgetPolicy}, so it can be configured at any scope of the {@see Environment}
 * chain (env now; project / issue later). It governs only the run total; a per-turn cap always just
 * soft-closes that one exchange.
 */
enum BudgetPolicy: string
{
    /** Hard stop: throw, ending the run (its snapshot survives, so `claw run` can resume it). */
    case Stop = 'stop';

    /** Pause and ask the run's ask channel whether to continue; a typed token top-up resumes it. */
    case Ask = 'ask';
}
