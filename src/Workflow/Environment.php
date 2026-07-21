<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Agent\AgentInterface;
use Claw\Agent\ToolResultBlock;
use Claw\Exceptions\ToolException;
use Claw\Exceptions\WorkflowException;
use Claw\Exec\ChainExecutor;
use Claw\Exec\ExecutorInterface;
use Claw\Exec\TimeoutMiddleware;
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
    /**
     * Roles to try, in order, when a role is not configured — before settling for the scope's default
     * model. Only roles that MUST be strong need an entry: the default model is the cheap tier, so a
     * strong role left unconfigured would otherwise silently run on it. See {@see findAgentModel()}.
     *
     * @var array<string, list<string>>
     */
    private const array ROLE_FALLBACKS = [
        'project-manager' => ['supervisor-smart', 'worker-smart', 'planner', 'reviewer'],
    ];

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
     * disagree.
     *
     * What guards a call here, and what does not:
     *
     *  - a per-tool TIMEOUT, when {@see EnvKey::ToolTimeoutMs} is set. Added because it was the one
     *    missing guard with no decision behind it: a hung tool is nobody's intended behaviour.
     *  - AUDIT is not a middleware here and does not need to be. The chat path logs to its session
     *    store; a run is traced instead — {@see \Claw\Trace\Tracer} records every tool call and its
     *    result into the project database, which is the same record kept by a different door.
     *  - PERMISSION is still absent, and deliberately so rather than by oversight. The gate in
     *    {@see \Claw\Permission\PermissionMiddleware} asks a person, and an autonomous run has none by
     *    definition; what it should do instead — refuse, consult the supervisor, or record and allow —
     *    is a policy nobody has chosen yet. See `dev/TODO.md`.
     */
    public function executor(): ExecutorInterface
    {
        $registry = $this->findRegistry();
        $middlewares = [];

        // Bound ONE tool run. Without it the only clock on the autonomous path is the run's total budget,
        // which a hung `bash` does not tick: a subprocess waiting on a prompt that will never come, or on
        // a network read with no timeout of its own, holds the coroutine for as long as the process lives
        // and the budget's seconds are never checked because nothing returns to check them. The cap kills
        // the subprocess through TrueAsync cancellation and hands the model an error it can act on.
        //
        // Innermost, so it caps the tool and not anything wrapped around it.
        $timeout = $this->find(EnvKey::ToolTimeoutMs);

        if (\is_int($timeout) && $timeout > 0) {
            // LOOSER than the deadline a tool holds for itself, deliberately. `bash` enforces this same
            // figure over its own non-blocking read and kills the command; if this fired first it would
            // cancel the coroutine before that could happen, and a cancelled coroutine cannot reach the
            // process handle — which is exactly the leak {@see \Claw\Tool\BashTool::drain()} was written
            // to close. So this is the backstop for tools that cannot bound themselves, and it stands
            // behind the ones that can.
            $middlewares[] = new TimeoutMiddleware($timeout + 5_000);
        }

        return new ChainExecutor($middlewares, static function (ToolCall $call) use ($registry): ToolResultBlock {
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

    public function findStore(): WorkflowStateStoreInterface
    {
        $store = $this->find(EnvKey::Store);

        if (!$store instanceof WorkflowStateStoreInterface) {
            throw new WorkflowException('environment has no state store');
        }

        return $store;
    }

    public function findModelId(): string
    {
        $id = $this->find(EnvKey::ModelId);

        return \is_string($id) ? $id : '';
    }

    /**
     * The model a named agent role runs on: the role's own {@see EnvKey::Agents} entry, else the first
     * configured role in its fallback chain, else the scope's default model.
     *
     * The chain exists because falling straight back to the default model is WRONG for a role whose
     * whole point is being strong. `project-manager` decides the strategy for a ticket — whether one
     * agent suffices, or a bespoke workflow is generated, or the work is decomposed into a tree of
     * sub-tickets with a divided budget. Silently resolving that to the cheap default would put the
     * costliest decision in the system on the weakest model. So an unconfigured strong role walks the
     * chain to another strong role before it settles for the default.
     */
    public function findAgentModel(string $role): string
    {
        foreach ([$role, ...(self::ROLE_FALLBACKS[$role] ?? [])] as $candidate) {
            $model = $this->configuredAgent($candidate);

            if ($model !== null) {
                return $model;
            }
        }

        return $this->findModelId();
    }

    /** The model configured for a role in {@see EnvKey::Agents}, or null when the role is unset/blank. */
    private function configuredAgent(string $role): ?string
    {
        $agents = $this->find(EnvKey::Agents);

        if (!\is_array($agents) || !isset($agents[$role]) || !\is_string($agents[$role]) || $agents[$role] === '') {
            return null;
        }

        return $agents[$role];
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
}
