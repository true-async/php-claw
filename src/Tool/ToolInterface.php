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
     * What this tool does to the world — {@see Effect::Read}, {@see Effect::Write}, or both. It is how a
     * palette is narrowed by capability rather than by name: a reviewer gets `exceptEffect(Effect::Write)`,
     * so a Write tool added to the run later stays out of its palette without anyone revisiting a list.
     *
     * @return list<Effect>
     */
    public function effects(): array;

    /**
     * Execute the call and return a textual result. May await.
     *
     * @param array<string, mixed> $input
     *
     * @throws \Claw\Exceptions\ToolException on failure
     */
    public function handle(array $input): string;
}
