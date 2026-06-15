<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;
use Claw\Exceptions\WorkflowException;
use Claw\Workflow\WorkflowStore;
use Claw\Workflow\WorkflowValidator;

/**
 * Lets the agent compile a workflow: it hands over a PHP class built from the
 * palette, which is validated and saved. Dangerous by definition (it writes code
 * that will later execute), so the permission layer gates it.
 */
final class DefineWorkflowTool implements ToolInterface
{
    public function __construct(
        private readonly WorkflowStore $store,
        private readonly WorkflowValidator $validator,
    ) {
    }

    public function name(): string
    {
        return 'define_workflow';
    }

    public function description(): string
    {
        return 'Save a reusable workflow as a PHP class built from the available tools. '
            . 'The class must implement Claw\\Workflow\\WorkflowInterface and reach tools '
            . 'and subworkflows only through its WorkflowContext ($ctx->call / $ctx->run). '
            . 'Set "shared" to make it available to every session.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'PascalCase workflow name, also the class name'],
                'code' => ['type' => 'string', 'description' => 'Full PHP source of the workflow class'],
                'shared' => ['type' => 'boolean', 'description' => 'Save to the shared Common folder (default: session-only)'],
            ],
            'required' => ['name', 'code'],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Dangerous;
    }

    public function handle(array $input): string
    {
        $name = $input['name'] ?? null;
        $code = $input['code'] ?? null;
        $shared = (bool) ($input['shared'] ?? false);

        if (!\is_string($name) || !\is_string($code)) {
            throw new ToolException('define_workflow requires string "name" and "code"');
        }

        try {
            $class = $this->store->classFor($name, $shared);
            $this->validator->validate($code, $class);
            $this->store->write($name, $code, $shared);

            return "Workflow '{$name}' saved as {$class}.";
        } catch (WorkflowException $e) {
            throw new ToolException($e->getMessage());
        }
    }
}
