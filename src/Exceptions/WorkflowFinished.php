<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * Not an error — a control signal: the model decided the task is fully solved and the workflow should
 * stop now, skipping any remaining steps. The {@see \Claw\Tool\FinishTool} throws it from inside a
 * step's ai() exchange; because the executor only converts {@see ToolException} into a tool result,
 * this propagates up through the turn loop and {@see \Claw\Workflow\WorkflowAbstract::run()} catches
 * it and finishes cleanly. Carries the model's summary of what was done.
 */
final class WorkflowFinished extends ClawException
{
    public function __construct(public readonly string $summary = '')
    {
        parent::__construct($summary === '' ? 'workflow finished' : $summary);
    }
}
