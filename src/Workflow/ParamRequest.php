<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * One machine-readable value a {@see StepAI}'s output must yield, addressed to the CODE step that reads
 * it. After the AI step's work settles, the base asks the model — continuing that step's own conversation
 * — for exactly this value and pins it with {@see WorkflowAbstract::setParam()}; the target step reads it
 * back with {@see WorkflowAbstract::param()}. It is the same mechanism that forms a handoff, addressed to
 * a named step instead of "the next one, automatically" — the machine-readable sibling of the prose baton.
 *
 * @see dev/design/workflow-resume.md
 */
final readonly class ParamRequest
{
    /**
     * @param string $forStep     the method name of the CODE step that reads this value with param()
     * @param string $name        the key that step reads it under
     * @param string $instruction what to ask the model for — phrased so the answer IS the value
     */
    public function __construct(
        public string $forStep,
        public string $name,
        public string $instruction,
    ) {
    }
}
