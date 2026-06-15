<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Exec\ExecutorInterface;

/**
 * Resolves a workflow by name, hands it a fresh WorkflowContext, and runs it.
 * Subworkflows re-enter here through the context, carrying the recursion depth.
 */
final class WorkflowRunner
{
    public function __construct(
        private readonly WorkflowStore $store,
        private readonly ExecutorInterface $executor,
        private readonly int $maxDepth = 16,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function run(string $name, array $input, int $depth = 0): array
    {
        $workflow = $this->store->load($name);
        $ctx = new WorkflowContext($this->executor, $this, $depth, $this->maxDepth);

        return $workflow->run($input, $ctx);
    }
}
