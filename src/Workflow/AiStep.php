<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * What a {@see StepAI} method DECLARES instead of doing: the base runs the ONE `ai()` exchange this
 * describes ({@see WorkflowAbstract::runAiStep()}). The method that returns it is PURE — it builds a
 * prompt from durable inputs and returns, doing no work and no side effect, which is exactly what makes
 * re-calling it on a resume free of consequence: the impure part, the exchange, is the base's, and the
 * base records and continues it. Exactly one exchange per step — that single exchange is the atomic unit
 * a resume continues from, so a step never carries two.
 *
 * @see dev/design/workflow-resume.md
 */
final readonly class AiStep
{
    /**
     * @param ?list<string> $tools null = every tool (default); a list = only those; [] = none — the same
     *                             contract {@see WorkflowAbstract::ai()} already used
     * @param ?string       $agent a named agent role (worker/reviewer/planner/…) whose model runs this
     *                             exchange, or null for the run's default
     */
    public function __construct(
        public string $prompt,
        public ?array $tools = null,
        public ?string $agent = null,
    ) {
    }
}
