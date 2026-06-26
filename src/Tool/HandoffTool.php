<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Trace\Tracer;

/**
 * The baton between steps. Each step's ai() starts fresh and sees nothing of the steps before it — by
 * design. `handoff` is the deliberate, SELECTIVE carry-over: not the whole prior context, only what the
 * next step must pay attention to — a clear summary of what was done and the findings that matter. The
 * next step reads it with recall(what='handoff'). Recorded in the journal so the relay is visible too.
 */
final class HandoffTool implements ToolInterface
{
    public function __construct(private readonly Tracer $tracer)
    {
    }

    public function name(): string
    {
        return 'handoff';
    }

    public function description(): string
    {
        return 'Hand the baton to the NEXT step. Record a concise SUMMARY of what this step accomplished '
            . 'and the FINDINGS the next step should pay attention to (decisions made, paths touched, what '
            . "is left, gotchas). The next step reads this via recall(what='handoff'). Steps do NOT share "
            . 'context automatically — this is how you pass forward exactly what matters. Call it once, when the step is done.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'what this step accomplished, concisely'],
                'findings' => ['type' => 'string', 'description' => 'what the next step should watch for — decisions, paths, gotchas, what remains'],
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
        $summary = \is_string($input['summary'] ?? null) ? $input['summary'] : '';
        $findings = \is_string($input['findings'] ?? null) ? $input['findings'] : '';
        $this->tracer->handoff($summary, $findings);

        return 'Handoff recorded — the next step can read it with recall(what=\'handoff\').';
    }
}
