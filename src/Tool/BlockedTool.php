<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\StepBlocked;

/**
 * Lets a worker report that it cannot finish its step, and say why.
 *
 * Without this, a worker with nothing left to try simply stops talking, and a turn that ends in prose
 * is indistinguishable from one that ended in success — which is how a gibberish ticket once closed on
 * the model replying "can you clarify?". A blocker that says so is a fact the run can act on; a
 * blocker that trails off is a silence someone downstream mistakes for work.
 *
 * It is deliberately NOT the mirror of the deleted `done` tool. That one let the worker rule on the
 * task, a question others could answer better. This one reports the worker's own state, which nobody
 * else can see, and it settles nothing: the step ends, the reason goes to the critic, and what happens
 * next is the run's decision.
 *
 * Use it for a wall, not a wobble. Needing an answer to carry on is what the `[question]` marker is
 * for — that reaches a person and the work continues.
 */
final readonly class BlockedTool implements ToolInterface
{
    public function name(): string
    {
        return 'blocked';
    }

    public function description(): string
    {
        return 'Report that you CANNOT finish this step, and why. Use it when you have hit a wall: what '
            . 'the task needs is not in this project, the ticket contradicts what you found, a tool or '
            . 'dependency you require is missing, or you have tried what there is to try and it does not '
            . 'work. Give the reason concretely — what you attempted and what stopped you — because it is '
            . 'read by a reviewer and then by a person deciding what to do with the ticket. Do NOT use it '
            . 'merely because you need information: to ask a question and carry on, end your turn with the '
            . '[question] marker instead. This ends the step; it does not fail the ticket.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'description' => 'what you were trying to do and what stopped you, concretely',
                ],
            ],
            'required' => ['reason'],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        throw new StepBlocked(\is_string($input['reason'] ?? null) ? trim($input['reason']) : '');
    }
}
