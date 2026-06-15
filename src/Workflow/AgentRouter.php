<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Agent\AgentInterface;

/**
 * Picks which agent executes a step, possibly based on context. This is how
 * "an agent can be chosen by another agent" is modelled: a router selects a
 * subagent (or tool-agent) suited to the step.
 */
interface AgentRouter
{
    public function pick(StepSpec $spec, ContextNode $node): AgentInterface;
}
