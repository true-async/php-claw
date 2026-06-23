<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\Message;
use Claw\Exceptions\WorkflowException;
use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Tool\ToolCall;
use Claw\Trace\Tracer;

/**
 * The base every workflow extends — a HELPER, not an engine. The workflow itself is a class with
 * state (its own fields); its steps are methods marked {@see Step}, whose bodies the author (a
 * human or the AI) writes by hand: build a prompt, call {@see ai()}, call {@see tool()}, write to
 * a field. The base does not run anything for the step — it only makes that code shorter and
 * durable:
 *
 *  - {@see ai()} talks to the model (the turn loop inside is an internal detail) with a
 *    least-privilege tool palette; {@see tool()} runs one tool; {@see param()} reads run inputs.
 *  - {@see step()} runs a step method unless a prior run already did, then snapshots the
 *    workflow's state + progress to the {@see WorkflowStateStore}. The state is restored at
 *    construction, so a skipped step loses nothing.
 *  - {@see run()} is just the entry point — by default it drives the step methods in order, but
 *    the author may override it and orchestrate by hand (plain if/while), calling step() as needed.
 *
 * Anything richer — a critic, a supervisor — is a SUB-STEP the author calls inside a step (just
 * another ai() call), not machinery baked in here.
 */
abstract class WorkflowAbstract implements WorkflowInterface
{
    /** @var list<string> step methods already completed (restored from the store) — skipped on re-run */
    private array $done;

    /** This run's own environment scope — a child of the injected (project) env, so init() overrides locally. */
    private readonly Environment $env;

    /** @param array<string, mixed> $params */
    public function __construct(
        Environment $env,
        private readonly string $runId = '',
        private readonly array $params = [],
        private readonly ?Issue $issue = null,
        private readonly ?Project $project = null,
    ) {
        $this->env = $env->child();   // the project env is the parent; this run overrides only what it must
        $this->init();                 // the workflow configures its scope before any step runs

        $snapshot = $this->env->findStore()->load($runId);
        $this->restoreState($snapshot['state']);   // a resumed run sees the state its done steps left behind
        $this->done = $snapshot['done'];
    }

    abstract public function name(): string;

    /**
     * The run's entry point. Default: drive every {@see Step} method in declaration order, each
     * skipped if already done. Override to orchestrate by hand — it is plain PHP (ordering,
     * if/while, sub-workflows); call $this->step('methodName') to run a step with the same
     * skip-and-snapshot guarantee.
     */
    public function run(): void
    {
        foreach ($this->stepMethods() as $name) {
            $this->step($name);
        }
    }

    /**
     * Configure the run before it executes — a hook the workflow overrides to set up its own
     * values via {@see set()}, reading the project's defaults via {@see find()}. Default is a
     * no-op: take whatever the project environment provides.
     */
    protected function init(): void
    {
    }

    /**
     * Run a step — the method named $name (marked {@see Step}) — unless a prior run already
     * completed it (then it is skipped, its effect already restored from the state snapshot).
     * After it runs, the workflow's state and progress are snapshotted to the store, so a crash
     * resumes from exactly here.
     */
    protected function step(string $name): void
    {
        if (\in_array($name, $this->done, true)) {
            return;
        }

        $tracer = $this->tracer();
        $span = $tracer?->enterStep($name);

        try {
            $this->{$name}();
        } finally {
            $tracer?->exit($span);
        }

        $this->done[] = $name;
        $this->env->findStore()->save($this->runId, $this->captureState(), $this->done);
    }

    /** Read a value from the run's environment — this scope, then the parent project settings. */
    protected function find(EnvKey|string $key): mixed
    {
        return $this->env->find($key);
    }

    /** Set a value in the run's OWN scope, shadowing the project's — used from init() to override. */
    protected function set(EnvKey|string $key, mixed $value): void
    {
        $this->env->set($key, $value);
    }

    /** Read a run parameter — the value that makes the workflow describe one task, not a class of them. */
    protected function param(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }

    /** The issue this run was started under, if any — climb to it for wider context. */
    protected function issue(): ?Issue
    {
        return $this->issue;
    }

    /** The project this run belongs to, if any. */
    protected function project(): ?Project
    {
        return $this->project;
    }

    /**
     * A model call: one exchange over $prompt with the named tools, returning the final text. The
     * turn loop that drives it (tool round-trips and all) is an internal detail. The tools are a
     * least-privilege palette — the model is shown, and can run, only these.
     *
     * Pass $agent to route the call to a named agent role (worker/reviewer/supervisor/planner, set
     * up in the run's {@see EnvKey::Agents} map): the role's model is used for just this call, on
     * the same access. An unknown role falls back to the scope's default model.
     *
     * @param list<string> $tools tool names to expose to the model for this call
     */
    protected function ai(string $prompt, array $tools = [], ?string $agent = null): string
    {
        // The palette is a child scope holding a registry narrowed to exactly these tools, so what
        // the model is shown (specs) and what the executor can resolve (get) are one set.
        $scope = $this->env->child()->set(EnvKey::Registry, $this->env->findRegistry()->only($tools));

        $model = $agent !== null ? $this->agentModel($agent) : null;
        if ($model !== null) {
            $scope->set(EnvKey::ModelId, $model);   // route this call to the role's model
        }

        $tracer = $this->tracer();
        $span = $tracer?->enterAi($agent ?? 'worker', $scope->findModelId());
        $tracer?->prompt($prompt, $tools);

        $loop = new DefaultTurnLoop(
            $scope->findWorker(),
            $scope->executor(),
            $scope->findModelId(),
            $scope->findSystemPrompt(),
            $scope->findRegistry()->specs(),
            $scope->findMaxHistory(),
            $tracer,
        );

        try {
            return $loop->run([Message::userText($prompt)])->text ?? '';
        } finally {
            $tracer?->exit($span);
        }
    }

    /** The run's hierarchical tracer, if one is configured — else null (no tracing). */
    private function tracer(): ?Tracer
    {
        $tracer = $this->env->find(EnvKey::Tracer);

        return $tracer instanceof Tracer ? $tracer : null;
    }

    /** The model id configured for a named agent role, or null to keep the scope's default. */
    private function agentModel(string $agent): ?string
    {
        $agents = $this->env->find(EnvKey::Agents);
        if (\is_array($agents) && isset($agents[$agent]) && \is_string($agents[$agent]) && $agents[$agent] !== '') {
            return $agents[$agent];
        }

        return null;
    }

    /**
     * A tool call through the run's executor. Throws if the tool reports an error.
     *
     * @param array<string, mixed> $params
     *
     * @throws WorkflowException
     */
    protected function tool(string $name, array $params): string
    {
        $tracer = $this->tracer();
        $tracer?->toolCall($name, $params);

        $result = $this->env->executor()->call(new ToolCall($this->env->findStore()->nextId(), $name, $params));

        $tracer?->toolResult($name, $result->content, $result->isError);
        if ($result->isError) {
            throw new WorkflowException("tool '{$name}' failed: " . $result->content);
        }

        return $result->content;
    }

    /**
     * Note something the workflow's own code did (a "task"). There is no Task class; the AI writes
     * its task methods and logs their specifics here — it lands under the current span in the trace.
     *
     * @param array<string, mixed> $context
     */
    protected function log(string $action, string $message = '', array $context = []): void
    {
        $this->tracer()?->log($action, $message, $context);
    }

    /**
     * The workflow's step methods (those marked {@see Step}), in declaration order — what the
     * default run() drives.
     *
     * @return list<string>
     */
    private function stepMethods(): array
    {
        $names = [];
        foreach ((new \ReflectionClass($this))->getMethods() as $method) {
            if ($method->getAttributes(Step::class) !== []) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }

    /**
     * Snapshot the workflow's own declared properties — its state — for the store. The base's
     * machinery (env, run id, params, …) is excluded; only the subclass's fields are persisted.
     *
     * @return array<string, mixed>
     */
    private function captureState(): array
    {
        $state = [];
        foreach ($this->stateProperties() as $property) {
            if ($property->isInitialized($this)) {
                $state[$property->getName()] = $property->getValue($this);
            }
        }

        return $state;
    }

    /**
     * Restore a snapshot onto the workflow's properties, so a resumed run sees the state its
     * completed steps left behind — the reason a skipped step loses nothing.
     *
     * @param array<string, mixed> $state
     */
    private function restoreState(array $state): void
    {
        foreach ($this->stateProperties() as $property) {
            if (\array_key_exists($property->getName(), $state)) {
                $property->setValue($this, $state[$property->getName()]);
            }
        }
    }

    /**
     * The subclass's own non-static properties — the workflow's state. The base's own fields stay
     * out (iteration stops at WorkflowAbstract), so only what the workflow declares is persisted.
     *
     * @return list<\ReflectionProperty>
     */
    private function stateProperties(): array
    {
        $properties = [];
        $class = new \ReflectionClass($this);
        while ($class !== false && $class->getName() !== self::class) {
            foreach ($class->getProperties() as $property) {
                if (!$property->isStatic()) {
                    $properties[] = $property;
                }
            }
            $class = $class->getParentClass();
        }

        return $properties;
    }
}
