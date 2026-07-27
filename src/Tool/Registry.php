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
     * The tools named in prose, for the system prompt. The API already advertises them, but a model
     * that is only handed a schema reaches for a tool inconsistently — it notices some and overlooks
     * others, and a model that overlooks the one tool that RECORDS a decision answers in prose
     * instead, leaving the work it did with nothing to show for it. Naming them up front is what
     * made that reliable elsewhere in this system, so it lives here rather than in one caller.
     *
     * Empty string when there are no tools, so a caller can concatenate it unconditionally.
     */
    public function briefing(string $heading = 'Tools available to you — call them by name when useful'): string
    {
        $tools = $this->all();

        if ($tools === []) {
            return '';
        }

        $lines = array_map(static fn (ToolInterface $t): string => "- {$t->name()}: {$t->description()}", $tools);

        return "\n\n{$heading}:\n" . implode("\n", $lines);
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
     * A new registry holding every tool in this one EXCEPT the named ones — the palette narrowed by
     * subtraction. The counterpart to {@see only()}, and the right shape when a scope must be denied
     * one specific capability rather than granted an enumerated few: `only()` has to be revisited every
     * time a tool is added to the run, and the revisit is silent when it is forgotten, so the scope
     * quietly stops seeing new capabilities.
     *
     * An unknown name is an error for the same reason it is in {@see only()}: a scope subtracting a
     * tool that does not exist believes it is protected against something, and it is not.
     *
     * @param list<string> $names
     *
     * @throws ToolException when a name is not registered
     */
    public function except(array $names): self
    {
        foreach ($names as $name) {
            $this->get($name);   // resolve for the side effect: an unknown name throws
        }

        $subset = new self();

        foreach ($this->tools as $tool) {
            if (!\in_array($tool->name(), $names, true)) {
                $subset->add($tool);
            }
        }

        return $subset;
    }

    /**
     * A new registry holding every tool in this one EXCEPT those with any of the given effects — the
     * palette narrowed by CAPABILITY instead of by name. `exceptEffect(Effect::Write)` is a read-only
     * palette: read_file and list_files stay, write_file and a write-capable shell go. Unlike
     * {@see except()}, which subtracts a fixed list and must be revisited each time a new tool is added,
     * this keeps meaning what it says — a Write tool registered later is excluded on its own effect,
     * with no edit here — which is exactly the failure mode {@see \Claw\Tool\Effect} exists to close.
     *
     * A tool with an EMPTY effect list is kept by any exceptEffect(): it declares no capability to deny.
     */
    public function exceptEffect(Effect ...$effects): self
    {
        $subset = new self();

        foreach ($this->tools as $tool) {
            $denied = false;

            foreach ($tool->effects() as $effect) {
                if (\in_array($effect, $effects, true)) {
                    $denied = true;

                    break;
                }
            }

            if (!$denied) {
                $subset->add($tool);
            }
        }

        return $subset;
    }

    /**
     * A new registry holding every tool in this one PLUS $tool, sharing the same instances — the
     * palette WIDENED by one for a scope, without touching this registry. The counterpart to
     * {@see only()}: used to mix a scope-only tool (e.g. the workflow-authoring tool, added just
     * for the generation/repair scope, never the solver's) into a child env's registry.
     */
    public function with(ToolInterface $tool): self
    {
        $widened = new self();

        foreach ($this->tools as $existing) {
            $widened->add($existing);
        }

        $widened->add($tool);

        return $widened;
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
