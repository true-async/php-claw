<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;
use Claw\Trace\TraceReader;

/**
 * Recall, from the run's own journal, what has already happened — so a step's model can look up what
 * a SIBLING step did instead of starting blind. Each ai() call gets only its prompt by design (steps
 * are isolated); this tool is the deliberate door back to the run's history: a step's recorded
 * subtree, every call to a tool, the artifacts a step produced, or a quick map of the workflow.
 *
 * Scoped to ONE run (its id is fixed at construction), reading the trace {@see TraceReader} renders
 * back. Read-only and safe. Registered per run by the composition root, so it is in every run's tool
 * palette automatically.
 */
final readonly class RecallTool implements ToolInterface
{
    public function __construct(
        private TraceReader $journal,
        private string $runId,
        private string $task = '',   // the issue/task brief this run was started for
    ) {
    }

    public function name(): string
    {
        return 'recall';
    }

    public function description(): string
    {
        return 'Recall context about THIS workflow run from its journal. '
            . "what='task' returns the original task/issue brief the run was started for; "
            . "what='workflow' maps the workflow and the steps run so far; "
            . "what='step' (name=the step) returns that step's recorded history — its model turns, tool "
            . "calls and artifacts; what='tool' (name=the tool) returns every call to that tool; "
            . "what='artifacts' (name optional) returns the artifacts a step produced, or all of them. "
            . 'Use it to re-read the task or see what a sibling step actually did before you build on it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'what' => [
                    'type' => 'string',
                    'enum' => ['task', 'workflow', 'step', 'tool', 'artifacts'],
                    'description' => 'which slice of the run to recall',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => "the step or tool name; required for what='step' and what='tool', optional for 'artifacts'",
                ],
            ],
            'required' => ['what'],
        ];
    }

    public function effects(): array
    {
        return [Effect::Read];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $what = \is_string($input['what'] ?? null) ? $input['what'] : '';
        $name = \is_string($input['name'] ?? null) ? trim($input['name']) : '';

        // Models (esp. OpenAI) often address a tool with a namespaced id like `functions.write_file`; the
        // recorded name is the bare `write_file`. Strip a leading `prefix.` so the lookup actually matches
        // — without this, every `recall(what='tool', name='functions.X')` returns "no tool called yet",
        // which a model then re-probes for in an expensive loop.
        $name = (string) preg_replace('/^[A-Za-z_]\w*\./', '', $name);

        return match ($what) {
            'task' => $this->task !== '' ? $this->task : 'No task brief is attached to this run.',
            'workflow' => $this->journal->describe($this->runId),
            'artifacts' => $this->journal->artifacts($this->runId, $name),
            'step' => $name === ''
                ? throw new ToolException("recall what='step' needs a 'name' (the step to recall)")
                : $this->journal->stepHistory($this->runId, $name),
            'tool' => $name === ''
                ? throw new ToolException("recall what='tool' needs a 'name' (the tool to recall)")
                : $this->journal->toolHistory($this->runId, $name),
            default => throw new ToolException("recall: unknown what '{$what}' (use task|workflow|step|tool|artifacts)"),
        };
    }
}
