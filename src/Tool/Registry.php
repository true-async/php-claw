<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Agent\ToolSpec;
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

    /**
     * The advertised specs for every tool in this registry — name, description, input schema.
     * Narrowing is the registry's job, not a filter here: a scope holds a registry narrowed via
     * {@see only()}, so the registry IS the palette and specs() advertises all of it.
     *
     * @return list<ToolSpec>
     */
    public function specs(): array
    {
        $specs = [];

        foreach ($this->tools as $tool) {
            $specs[] = new ToolSpec($tool->name(), $tool->description(), $tool->inputSchema());
        }

        return $specs;
    }

    /**
     * A new registry holding only the named tools, sharing the same tool instances — the
     * palette narrowed to a least-privilege subset for a scope. Unlike {@see specs()}, which
     * only hides tools from the model, the subset is authoritative: a tool absent from it
     * cannot be resolved via {@see get()}, so it cannot be run. An unknown name is an error,
     * not a silent skip — a scope asking for a tool that does not exist is a mistake worth
     * surfacing. Order follows the argument list.
     *
     * @param list<string> $names
     *
     * @throws ToolException when a requested name is not registered
     */
    public function only(array $names): self
    {
        $subset = new self();

        foreach ($names as $name) {
            $subset->add($this->get($name));
        }

        return $subset;
    }

    /**
     * The registered tools that are agents — resolved through their own path so the caller
     * asks for an agent explicitly, not out of the generic tool pile.
     *
     * @return list<AgentToolInterface>
     */
    public function agents(): array
    {
        $agents = [];

        foreach ($this->tools as $tool) {
            if ($tool instanceof AgentToolInterface) {
                $agents[] = $tool;
            }
        }

        return $agents;
    }
}
