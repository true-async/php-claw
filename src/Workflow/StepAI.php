<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Marks a method as an AI step: a PURE method that returns an {@see AiStep} declaration, whose one
 * exchange the base ({@see WorkflowAbstract::runAiStep()}) runs, records, and continues on resume.
 *
 * The sibling of {@see Step} — a CODE step, re-run whole on resume because it is cheap and deterministic.
 * The base drives both and tells them apart by which attribute the method carries, so the resume path
 * decides continue-vs-re-run BEFORE it ever calls the body. That is the whole reason the kind is an
 * attribute and not the method's return type: reflection reads it without running anything.
 *
 * A critic rides on the attribute, not on the returned declaration, so the resume path can read it (and
 * the round cap) without running the body — the same reason the kind itself is an attribute. The base
 * judges the step's recorded artifacts against it exactly as it does an imperative step's.
 *
 * @see dev/design/workflow-resume.md
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class StepAI
{
    /**
     * @param ?string $critic    the critic name this step's output is judged against (null = no critic);
     *                           its rules live in {@see WorkflowAbstract::criticRules()} under this key
     * @param ?int    $maxRounds soft cap on critic rework rounds before the run escalates to the
     *                           supervisor (null = the workflow default)
     */
    public function __construct(
        public ?string $critic = null,
        public ?int $maxRounds = null,
    ) {
    }
}
