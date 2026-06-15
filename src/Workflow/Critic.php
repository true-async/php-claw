<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Judges the result of a step and returns a soft Score (not a command). Which
 * critic runs on which step is set by the workflow or the context, and critics
 * may differ per step. The workflow's policy turns the score into a StepOutcome.
 */
interface Critic
{
    public function evaluate(Step $step): Score;
}
