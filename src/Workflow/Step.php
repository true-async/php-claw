<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Marks a method as a workflow step. The base {@see WorkflowAbstract} discovers these (the
 * default run() drives them in declaration order) and a step is the unit that resume tracks:
 * a completed step is skipped on re-run, its effect already restored from the state snapshot.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Step
{
}
