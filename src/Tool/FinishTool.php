<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\WorkflowFinished;

/**
 * Lets the model declare the task SOLVED and end the workflow now, skipping any remaining steps —
 * for when the work is genuinely complete (a small task that did not need every planned step). It
 * throws a {@see WorkflowFinished} signal that the workflow catches and finishes on; the executor
 * passes it through untouched (only {@see \Claw\Exceptions\ToolException} becomes a tool result).
 * Safe — it ends work, it does not perform any.
 */
final readonly class FinishTool implements ToolInterface
{
    public function name(): string
    {
        return 'done';
    }

    public function description(): string
    {
        return 'Declare the task fully solved and finish the workflow now, skipping any remaining '
            . 'steps. Call this ONLY when the work is actually complete AND verified by evidence you '
            . 'have seen — the tests were run and are green, not merely a clean syntax check. Your '
            . 'summary is handed to a reviewer who checks it against the real files before the run is '
            . 'allowed to end; an unearned claim is sent back as rework. Pass a short summary of what '
            . 'was done and how you verified it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'a short summary of what was accomplished'],
            ],
            'required' => ['summary'],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        throw new WorkflowFinished(\is_string($input['summary'] ?? null) ? $input['summary'] : '');
    }
}
