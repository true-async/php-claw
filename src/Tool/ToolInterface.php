<?php

declare(strict_types=1);

namespace Claw\Tool;

/**
 * A capability the agent can invoke. The tool advertises itself (name,
 * description, input schema, risk) and executes a call. It knows nothing about
 * the agent — the dependency goes one way: agent uses tools.
 */
interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON Schema for the tool's input.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    public function risk(): Risk;

    /**
     * Execute the call and return a textual result. May await.
     *
     * @param array<string, mixed> $input
     *
     * @throws \Claw\Exceptions\ToolException on failure
     */
    public function handle(array $input): string;
}
