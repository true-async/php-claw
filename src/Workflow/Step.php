<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Marks a method as a workflow step. The base {@see WorkflowAbstract} discovers these (the
 * default run() drives them in declaration order) and a step is the unit that resume tracks:
 * a completed step is skipped on re-run, its effect already restored from the state snapshot.
 *
 * A step may declare a CRITIC — an aspect, woven by the step driver, not by the step body: after
 * the method runs, its result (the method's return value) is judged against $critic by the reviewer
 * role; if it falls short, the supervisor (the ask channel) is consulted and the step re-runs with
 * that guidance, until the critic is satisfied (the budget is the hard backstop). A step with no
 * critic runs once. See {@see WorkflowAbstract::step()}.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Step
{
    /**
     * @param ?string $critic    the critic name this step's result is judged against (null = no critic)
     * @param ?int    $maxRounds soft cap on critic rework rounds for THIS step before the run escalates
     *                           to the supervisor (null = the workflow default). Raise it for a step
     *                           that legitimately churns — runs a test gate, retries — and lower it for
     *                           a cheap one. The budget is still the ultimate backstop.
     */
    public function __construct(
        public ?string $critic = null,
        public ?int $maxRounds = null,
    ) {
    }
}
