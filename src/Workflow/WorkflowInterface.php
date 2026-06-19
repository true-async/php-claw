<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * A workflow as the agent writes it: a PHP class with state (its own fields) whose steps are
 * methods. The class is the reusable procedure; each INSTANCE is one run, carrying its id,
 * parameters and state. Step bodies are ordinary code — read parameters, build prompts, call
 * the model and tools — reaching the world only through the capabilities its base
 * ({@see WorkflowAbstract}) provides ($this->ai / $this->tool / $this->param / $this->step).
 *
 * A run is durable by SNAPSHOT: the base persists the workflow's state and which steps are done
 * after each step, and on resume restores the state and skips the steps already done — so a
 * skipped step loses nothing and only the unfinished tail re-executes.
 */
interface WorkflowInterface
{
    /** Stable identifier; matches the generated class name. */
    public function name(): string;

    /** Drive the run. Returns nothing: results are the state the steps leave behind. */
    public function run(): void;
}
