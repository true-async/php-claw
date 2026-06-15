<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * The fixed skeleton of a step: structure and capabilities, set by the workflow.
 * The description (what to do) may be a parameter or filled in by an agent, but
 * the agent, critic, tool set and optionality stay fixed and gate that freedom.
 */
final readonly class StepSpec
{
    public function __construct(
        public string $description,
        public ?string $agent = null,     // agent or router name; null = default
        public ?string $critic = null,    // critic name; null = default
        public ?ToolSet $toolSet = null,  // tools available to this step
        public bool $optional = false,    // whether the step itself may be skipped
        public ?string $rubric = null,    // success criteria for the critic; null = critic judges on its own
    ) {
    }
}
