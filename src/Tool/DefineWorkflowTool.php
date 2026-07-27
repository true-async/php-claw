<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;
use Claw\Exceptions\WorkflowException;
use Claw\Workflow\WorkflowStore;
use Claw\Workflow\WorkflowValidator;

/**
 * Lets the agent define a workflow: it hands over a PHP class, which is validated
 * and saved. Dangerous by definition (it writes code that will later execute), so
 * the permission layer gates it.
 */
final readonly class DefineWorkflowTool implements ToolInterface, DeferredToolInterface
{
    /** @return list<string> */
    public function searchTags(): array
    {
        return ['workflow', 'save', 'generate', 'define', 'procedure', 'author', 'create'];
    }

    public function __construct(
        private WorkflowStore $store,
        private WorkflowValidator $validator,
    ) {
    }

    public function name(): string
    {
        return 'define_workflow';
    }

    public function description(): string
    {
        return 'Save a reusable workflow as a PHP class. The class must extend '
            . 'Claw\\Workflow\\WorkflowAbstract and implement name(). Keep state in plain fields; '
            . 'write each AI step as a pure method marked #[\\Claw\\Workflow\\StepAI] that returns '
            . 'new Claw\\Workflow\\AiStep($prompt, $tools, $agent) declaring one model exchange, and any '
            . 'deterministic glue as a #[\\Claw\\Workflow\\Step] method that calls $this->tool(...). '
            . 'The default run() drives the step methods in declaration order; override run() to '
            . 'orchestrate by hand. Set "shared" to make it available to every session.';
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

    public function effects(): array
    {
        return [Effect::Write];
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
            throw new ToolException($e->getMessage(), 0, $e);
        }
    }
}
