<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * A unit of work inside a step. Tasks may run in parallel (as coroutines). A task
 * is a prompt to an agent, a tool call, or a subworkflow. Required tasks form the
 * step's completion barrier; optional ones are best-effort and their failure
 * degrades the step rather than failing it.
 */
interface Task
{
    public function kind(): TaskKind;

    public function isRequired(): bool;

    /**
     * Run the task through the context (the single door out).
     *
     * @return mixed the task's result
     */
    public function run(WorkflowContext $ctx): mixed;
}
