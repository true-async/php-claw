<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Agent\AgentInterface;
use Claw\Agent\ToolResultBlock;
use Claw\Exceptions\ToolException;
use Claw\Exceptions\WorkflowException;
use Claw\Exec\ChainExecutor;
use Claw\Exec\ExecutorInterface;
use Claw\Journal\JournalInterface;
use Claw\Tool\Registry;
use Claw\Tool\ToolCall;

/**
 * The run's environment: a scoped key->value store with a parent link. find() looks in this
 * scope, then climbs to the parent, up to the root where the defaults live — so the
 * project -> issue -> workflow -> sub-workflow chain inherits values and a child overrides only
 * what it must. It replaces a flat config object: keys are plain strings so anything can be
 * stored under any key, while {@see EnvKey} is the catalog of well-known keys (typo-proof,
 * discoverable) that find()/set() also accept; the hot roles get typed finders so call sites
 * stay type-safe over the mixed bag (find() climbing the chain IS the "default if unset" rule).
 *
 * What belongs here: machinery that has a sensible default and cascades (agent, store, registry,
 * model). What does NOT: a run's identity (runId, params, issue, project) — that is fixed per
 * instance, not a cascading default, so it stays a property.
 */
final class Environment
{
    /** @var array<string, mixed> values set in THIS scope, keyed by the string key */
    private array $values = [];

    public function __construct(private readonly ?Environment $parent = null)
    {
    }

    /** Set a value in this scope (fluent), shadowing anything inherited from a parent. */
    public function set(EnvKey|string $key, mixed $value): self
    {
        $this->values[self::name($key)] = $value;

        return $this;
    }

    /** The value for $key in this scope, else the parent's, else null. */
    public function find(EnvKey|string $key): mixed
    {
        $name = self::name($key);

        return $this->values[$name] ?? $this->parent?->find($name);
    }

    /** The string a key resolves to — an EnvKey is its backing value, a raw string is itself. */
    private static function name(EnvKey|string $key): string
    {
        return $key instanceof EnvKey ? $key->value : $key;
    }

    /** A child scope that inherits this one and can override locally — a sub-workflow's env. */
    public function child(): self
    {
        return new self($this);
    }

    // --- typed finders: the hot roles, resolved type-safely over the mixed bag ---

    public function findWorker(): AgentInterface
    {
        $worker = $this->find(EnvKey::Worker);
        if (!$worker instanceof AgentInterface) {
            throw new WorkflowException('environment has no worker agent');
        }

        return $worker;
    }

    /**
     * Build the run's executor from THIS scope — a {@see ChainExecutor} whose terminal resolves
     * a call against this scope's {@see Registry} and runs it. A narrowed scope holds a narrowed
     * registry (see {@see Registry::only()}), so the executor shares the same palette: a tool
     * outside it cannot be resolved, hence cannot run — visibility and execution can no longer
     * disagree. Permission/audit middleware is the run-path's to add; an autonomous run is allow-all.
     */
    public function executor(): ExecutorInterface
    {
        $registry = $this->findRegistry();

        return new ChainExecutor([], static function (ToolCall $call) use ($registry): ToolResultBlock {
            try {
                return new ToolResultBlock($call->id, $registry->get($call->name)->handle($call->input), false);
            } catch (ToolException $e) {
                return new ToolResultBlock($call->id, $e->getMessage(), true);
            }
        });
    }

    public function findRegistry(): Registry
    {
        $registry = $this->find(EnvKey::Registry);
        if (!$registry instanceof Registry) {
            throw new WorkflowException('environment has no tool registry');
        }

        return $registry;
    }

    public function findStore(): WorkflowStateStore
    {
        $store = $this->find(EnvKey::Store);
        if (!$store instanceof WorkflowStateStore) {
            throw new WorkflowException('environment has no state store');
        }

        return $store;
    }

    public function findModelId(): string
    {
        $id = $this->find(EnvKey::ModelId);

        return \is_string($id) ? $id : '';
    }

    public function findSystemPrompt(): string
    {
        $prompt = $this->find(EnvKey::SystemPrompt);

        return \is_string($prompt) ? $prompt : '';
    }

    public function findMaxHistory(): int
    {
        $max = $this->find(EnvKey::MaxHistory);

        return \is_int($max) ? $max : 0;
    }

    /** The run's journal, or null when none is configured — null = nothing is journaled. */
    public function findJournal(): ?JournalInterface
    {
        $journal = $this->find(EnvKey::Journal);

        return $journal instanceof JournalInterface ? $journal : null;
    }
}
