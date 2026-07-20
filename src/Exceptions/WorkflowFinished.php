<?php

declare(strict_types=1);

namespace Claw\Exceptions;

use Claw\Agent\Message;

/**
 * Not an error — a control signal: the model decided the task is fully solved and the workflow should
 * stop now, skipping any remaining steps. The {@see \Claw\Tool\FinishTool} throws it from inside a
 * step's ai() exchange; because the executor only converts {@see ToolException} into a tool result,
 * this propagates up through the turn loop and {@see \Claw\Workflow\WorkflowAbstract::step()} holds it
 * until the step's critic has judged the work — `done` is a CLAIM the reviewer settles, not a bypass.
 *
 * It carries the model's summary and the conversation that produced it. The history is not decoration:
 * a step that ends by throwing never reaches the line where its work exchange is normally recorded, so
 * without it the critic would review an empty step and the handoff could not be formed.
 */
final class WorkflowFinished extends ClawException
{
    /** @param list<Message> $history the exchange that ended in `done`, for the critic to review */
    public function __construct(
        public readonly string $summary = '',
        public readonly array $history = [],
    ) {
        parent::__construct($summary === '' ? 'workflow finished' : $summary);
    }
}
