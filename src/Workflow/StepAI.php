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
 * @see dev/design/workflow-resume.md
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class StepAI
{
}
