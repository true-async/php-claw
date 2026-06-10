<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Exceptions\AgentException;

/**
 * Decides retry by the agent's classified error. Separated from the retry loop
 * and the request, and unit-testable in isolation.
 */
interface AgentRetryPolicyInterface
{
    /** Delay in ms before the next attempt, or null to give up. */
    public function delayBeforeRetry(AgentException $error, int $attempt): ?int;
}
