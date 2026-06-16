<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Runs the actual work of a step, filling its working context and artifacts. In
 * production this drives one or more turnLoops through the step's agent; in tests
 * it can be a fake. Kept abstract so the engine's control logic (evaluate, decide,
 * retry) can be exercised without an LLM.
 */
interface StepExecutorInterface
{
    public function execute(Step $step): void;
}
