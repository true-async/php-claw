<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Exceptions\WorkflowException;
use Claw\Exec\ExecutorInterface;
use Claw\Tool\ToolCall;

/**
 * The only door a workflow has to the outside world. Generated workflow code is
 * forbidden from touching tools or other workflows directly; it goes through this
 * context, so every nested call is funneled through the same executor (permission,
 * audit, timeout) and recursion stays bounded.
 */
final class WorkflowContext
{
    private int $calls = 0;

    public function __construct(
        private readonly ExecutorInterface $executor,
        private readonly WorkflowRunner $runner,
        private readonly int $depth = 0,
        private readonly int $maxDepth = 16,
    ) {
    }

    /**
     * Call a tool by name, through the same middleware chain as a normal tool call.
     *
     * @param array<string, mixed> $input
     *
     * @throws WorkflowException if the tool reports an error
     */
    public function call(string $tool, array $input): string
    {
        $id = 'wf-' . $this->depth . '-' . (++$this->calls);
        $result = $this->executor->call(new ToolCall($id, $tool, $input));

        if ($result->isError) {
            throw new WorkflowException("tool '{$tool}' failed: " . $result->content);
        }

        return $result->content;
    }

    /**
     * Run another workflow as a subworkflow, bounded against runaway recursion.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws WorkflowException when the recursion limit is reached
     */
    public function run(string $workflow, array $input): array
    {
        if ($this->depth >= $this->maxDepth) {
            throw new WorkflowException("workflow recursion limit ({$this->maxDepth}) reached at '{$workflow}'");
        }

        return $this->runner->run($workflow, $input, $this->depth + 1);
    }
}
