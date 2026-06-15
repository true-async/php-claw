<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * A compiled, reusable procedure: a PHP class the agent writes once from the
 * available building blocks and then runs as plain code. A workflow is itself a
 * building block, so workflows compose — one can call another as a subworkflow
 * through the WorkflowContext it is handed.
 */
interface WorkflowInterface
{
    /** Stable identifier; matches the generated class name. */
    public function name(): string;

    /** What it does, for humans and for the model choosing it from the palette. */
    public function description(): string;

    /**
     * JSON Schema for the input, so calls can be validated and the workflow can
     * be advertised to the model as a building block.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Run the workflow. All tool and subworkflow calls must go through $ctx — the
     * only door to the outside world — so every nested call passes the same
     * permission/audit/timeout layer. May await.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> structured result, so workflows can feed each other
     *
     * @throws \Claw\Exceptions\WorkflowException
     */
    public function run(array $input, WorkflowContext $ctx): array;
}
