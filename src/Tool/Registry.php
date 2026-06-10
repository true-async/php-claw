<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Holds the available tools, keyed by name. Independent of the agent — the
 * mapping of tools to the agent's advertised specs happens at the agent/tool
 * boundary, not here.
 */
final class Registry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function add(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ToolInterface
    {
        return $this->tools[$name] ?? throw new ToolException("Unknown tool: {$name}");
    }

    /**
     * @return list<ToolInterface>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }
}
