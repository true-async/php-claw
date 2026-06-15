<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;
use Claw\Exceptions\WorkflowException;
use Claw\Workflow\WorkflowRunner;

/**
 * Runs a previously defined workflow by name. Marked Safe because the workflow is
 * harmless on its own: every tool call inside it goes back through the executor,
 * so any dangerous step is gated by the permission layer at the moment it happens.
 */
final class RunWorkflowTool implements ToolInterface
{
    public function __construct(
        private readonly WorkflowRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'run_workflow';
    }

    public function description(): string
    {
        return 'Run a previously defined workflow by name, optionally with an input object. '
            . 'Returns the workflow\'s structured result as JSON.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Name of the workflow to run'],
                'input' => ['type' => 'object', 'description' => 'Input object passed to the workflow'],
            ],
            'required' => ['name'],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $name = $input['name'] ?? null;
        if (!\is_string($name)) {
            throw new ToolException('run_workflow requires a string "name"');
        }

        $workflowInput = $input['input'] ?? [];
        if (!\is_array($workflowInput)) {
            $workflowInput = [];
        }

        try {
            $result = $this->runner->run($name, $workflowInput);
        } catch (WorkflowException $e) {
            throw new ToolException($e->getMessage());
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
