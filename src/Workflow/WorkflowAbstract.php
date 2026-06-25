<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Agent\Budget;
use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\Message;
use Claw\Agent\SpeakerInterface;
use Claw\Exceptions\WorkflowException;
use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Tool\Registry;
use Claw\Tool\ToolCall;
use Claw\Tool\ToolInterface;
use Claw\Trace\Level;
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
 * A critic, though, IS machinery here: a step can declare {@see Step::$critic}, and the driver judges
 * the step's RESULT (the method's return value) against it on the reviewer role; while it falls short,
 * the supervisor (the ask channel) guides a re-run — a declarative aspect, not a hand-written sub-step.
 */
abstract class WorkflowAbstract implements WorkflowInterface
{
    /** @var list<string> step methods already completed (restored from the store) — skipped on re-run */
    private array $done;

    /** The critic/supervisor's latest guidance for the running step, exposed via {@see critique()}; transient. */
    private ?string $critique = null;

    /** The step currently running, so {@see artifact()} attaches its outputs to the right step; transient. */
    private string $currentStep = '';

    /**
     * Artifacts the current step has produced this attempt — handed to the critic, reset each re-run.
     * Transient (not part of resume state): a re-run regenerates them.
     *
     * @var list<Artifact>
     */
    private array $stepArtifacts = [];

    /**
     * This workflow's own #[Tool] methods, wrapped as tools — discovered once by reflection, then cached.
     *
     * @var ?list<ToolInterface>
     */
    private ?array $localTools = null;

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

        $this->enforceBudget();   // don't begin a new step once the run's budget is spent

        $tracer = $this->tracer();
        $span = $tracer?->enterStep($name);
        $previousStep = $this->currentStep;
        $this->currentStep = $name;   // so artifact() records under this step

        try {
            $rubric = $this->stepCritic($name);
            $this->critique = null;

            // Run the step; if it declares a critic, judge its RESULT (the return value) and the
            // ARTIFACTS it produced, and while the critic is unhappy let the supervisor guide a re-run
            // — until the critic passes, the supervisor accepts/stops, or the budget runs out.
            while (true) {
                $this->stepArtifacts = [];   // a fresh attempt produces a fresh set
                $raw = $this->{$name}();
                $result = \is_string($raw) ? $raw : '';
                if ($rubric === null) {
                    break;
                }

                $findings = $this->critic($name, $result, $rubric, $this->stepArtifacts);
                if ($findings === null) {
                    break;   // the critic is satisfied
                }

                $guidance = $this->superviseStep($name, $result, $findings);
                if ($guidance === null) {
                    break;   // the supervisor accepted the work as-is
                }

                $this->critique = $guidance;   // the re-run reads this via critique()
                $this->enforceBudget();        // the round spent tokens; stop here if the budget is gone
            }
        } finally {
            $this->critique = null;
            $this->currentStep = $previousStep;
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

    /**
     * The critic/supervisor's latest guidance for the running step, or null. A step with a critic
     * reads this and folds it into its work, so a re-run actually addresses the findings.
     */
    protected function critique(): ?string
    {
        return $this->critique;
    }

    /**
     * Record a named output the current step produced — a piece of text, or a file the step wrote.
     * Artifacts are journaled (so they show in `claw log`) and handed to the step's critic for review;
     * a step that declares a critic SHOULD emit the artifacts the rubric is judged against. Pass either
     * $text or $file (a path relative to the project), not both.
     */
    protected function artifact(string $label, ?string $text = null, ?string $file = null): void
    {
        $entry = $file !== null ? Artifact::file($label, $file) : Artifact::text($label, $text ?? '');
        $this->stepArtifacts[] = $entry;
        $this->tracer()?->artifact($entry->label, $entry->kind, $entry->value);
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
     * A model call: one exchange over $prompt, returning the final text. The turn loop that drives it
     * (tool round-trips and all) is an internal detail.
     *
     * By default the model is shown — and can run — EVERY tool the run has: a capable agent should
     * reach for whatever the task needs, so a full palette is the norm. Narrowing is the exception,
     * a deliberate least-privilege choice for a step that must NOT act a certain way: pass an explicit
     * list to expose only those tools, or `[]` to forbid tools entirely (a pure-reasoning judge, or a
     * call whose whole job is to return text/code rather than do anything).
     *
     * Pass $agent to route the call to a named agent role (worker/reviewer/supervisor/planner, set
     * up in the run's {@see EnvKey::Agents} map): the role's model is used for just this call, on
     * the same access. An unknown role falls back to the scope's default model.
     *
     * @param ?list<string> $tools null = every tool (default); a list = only those; [] = none
     */
    protected function ai(string $prompt, ?array $tools = null, ?string $agent = null): string
    {
        $this->enforceBudget();   // refuse to start a model call once the run's total budget is spent

        // The palette is a child scope holding the run's registry plus this workflow's own #[Tool]
        // methods — full by default, or narrowed to exactly $tools when a step asks for least
        // privilege. The model's specs and what the executor can resolve are the same set either way.
        $registry = $this->withLocalTools($this->env->findRegistry());
        $palette = $tools === null ? $registry : $registry->only($tools);
        $scope = $this->env->child()->set(EnvKey::Registry, $palette);

        $model = $agent !== null ? $this->agentModel($agent) : null;
        if ($model !== null) {
            $scope->set(EnvKey::ModelId, $model);   // route this call to the role's model
        }

        $exposed = array_map(static fn (ToolInterface $t): string => $t->name(), $palette->all());
        $tracer = $this->tracer();
        $span = $tracer?->enterAi($agent ?? 'worker', $scope->findModelId());
        $tracer?->prompt($prompt, $exposed);

        // The ask channel (if any) makes the turn loop interactive: the model can pause to ask a
        // person/agent mid-call via the [question] marker, not only through an explicit $this->ask().
        $ask = $scope->find(EnvKey::Ask);

        $loop = new DefaultTurnLoop(
            $scope->findWorker(),
            $scope->executor(),
            $scope->findModelId(),
            $scope->findSystemPrompt(),
            $scope->findRegistry()->specs(),
            $scope->findMaxHistory(),
            $tracer,
            $ask instanceof SpeakerInterface ? $ask : null,
            $this->turnBudget(),   // caps this one exchange; its spend bubbles up to the run total
        );

        try {
            $text = $loop->run([Message::userText($prompt)])->text ?? '';
            $this->enforceBudget();   // the loop charged the total — stop the run if that tipped it over

            return $text;
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

    /**
     * The run's registry combined with this workflow's own {@see Tool}-marked methods. When the
     * workflow defines none (the common case), the run's registry is returned untouched; otherwise a
     * fresh registry holds both, the locals last so a workflow can shadow a global tool by name.
     */
    private function withLocalTools(Registry $registry): Registry
    {
        $local = $this->localTools();
        if ($local === []) {
            return $registry;
        }

        $combined = new Registry();
        foreach ($registry->all() as $tool) {
            $combined->add($tool);
        }
        foreach ($local as $tool) {
            $combined->add($tool);
        }

        return $combined;
    }

    /**
     * This workflow's {@see Tool}-marked methods, each wrapped as a {@see MethodTool}. Discovered once
     * by reflection and cached; empty for a workflow that declares no local tools.
     *
     * @return list<ToolInterface>
     */
    private function localTools(): array
    {
        if ($this->localTools !== null) {
            return $this->localTools;
        }

        $tools = [];
        foreach (new \ReflectionClass($this)->getMethods() as $method) {
            $attributes = $method->getAttributes(Tool::class);
            if ($attributes !== []) {
                $tools[] = new MethodTool($this, $method, $attributes[0]->newInstance());
            }
        }

        return $this->localTools = $tools;
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

    /** The run's total budget (token+time), if one is configured — else null (unlimited). */
    private function budget(): ?Budget
    {
        $budget = $this->env->find(EnvKey::Budget);

        return $budget instanceof Budget ? $budget : null;
    }

    /**
     * A fresh per-turn budget for one {@see ai()} exchange — a child of the run total carrying the
     * turn caps, so its spend bubbles up. Null when neither a run total nor a turn cap is set.
     */
    private function turnBudget(): ?Budget
    {
        $tokens = (int) $this->numEnv(EnvKey::TurnTokenLimit);
        $seconds = $this->numEnv(EnvKey::TurnTimeLimit);

        $workflow = $this->budget();
        if ($workflow !== null) {
            return $workflow->child($tokens, $seconds);
        }

        return ($tokens > 0 || $seconds > 0.0) ? new Budget($tokens, $seconds) : null;
    }

    /**
     * Act on the run's total budget when it is spent, per the {@see BudgetPolicy}:
     *  - Stop (default): throw — a hard but resumable stop (the snapshot survives).
     *  - Ask: ask the run's ask channel whether to continue; a typed token top-up raises the budget
     *    and resumes, anything else (or no channel) falls back to the hard stop.
     *
     * @throws WorkflowException
     */
    private function enforceBudget(): void
    {
        $budget = $this->budget();
        if ($budget === null || !$budget->isExhausted()) {
            return;
        }

        if ($this->budgetPolicy() === BudgetPolicy::Ask) {
            $channel = $this->env->find(EnvKey::Ask);
            if ($channel instanceof SpeakerInterface) {
                $extra = $this->parseExtraTokens($channel->reply(
                    "Budget spent: {$budget->reason()}. Enter extra tokens to continue, or nothing to stop.",
                ));
                if ($extra > 0) {
                    $budget->raise($extra);
                    $this->tracer()?->log('budget', "raised by {$extra} tokens", [], Level::Notice);

                    return;
                }
            }
        }

        throw new WorkflowException('run stopped: ' . $budget->reason());
    }

    /** The configured reaction to a spent run total — {@see BudgetPolicy::Stop} when unset. */
    private function budgetPolicy(): BudgetPolicy
    {
        $policy = $this->env->find(EnvKey::BudgetPolicy);

        return $policy instanceof BudgetPolicy ? $policy : BudgetPolicy::Stop;
    }

    /** A positive token top-up parsed from an ask answer (e.g. "+100000"), or 0 to stop. */
    private function parseExtraTokens(?string $answer): int
    {
        $digits = ltrim(trim((string) $answer), '+');

        return $digits !== '' && ctype_digit($digits) ? (int) $digits : 0;
    }

    /** Read a numeric environment value (a budget cap), or 0.0 when unset/non-numeric. */
    private function numEnv(EnvKey $key): float
    {
        $value = $this->env->find($key);

        return \is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * The rules a step's result is judged against. The {@see Step} attribute names a critic; the
     * actual rules live in {@see criticRules()}, keyed by that name. Null when the step has no critic.
     * An unknown name is a generation bug — fail loud rather than judge against an empty rubric.
     */
    private function stepCritic(string $name): ?string
    {
        $attributes = new \ReflectionMethod($this, $name)->getAttributes(Step::class);
        if ($attributes === []) {
            return null;
        }

        $critic = $attributes[0]->newInstance()->critic;
        if ($critic === null || $critic === '') {
            return null;
        }

        $rules = $this->criticRules()[$critic] ?? null;
        if ($rules === null || trim($rules) === '') {
            throw new \LogicException("Step '{$name}' names critic '{$critic}', but criticRules() has no rules for it.");
        }

        return $rules;
    }

    /**
     * The rules each critic judges by, keyed by the name used in `#[Step(critic: '<name>')]`. A
     * workflow that uses critics overrides this to spell out, per critic, the concrete criteria the
     * reviewer must check. The base is empty — a workflow with no critics needs nothing here.
     *
     * @return array<string, string>
     */
    protected function criticRules(): array
    {
        return [];
    }

    /**
     * Judge a step's work against its rubric on the reviewer role: null = it passes, else the findings.
     * The critic is an ORDINARY ai — it gets every tool, so it can actually verify (read the files the
     * step wrote, run `php -l` or the tests) rather than judge a blurb. Its standing role, prepended
     * here, is to REVIEW only: inspect and report, never do or fix the work itself. It is handed the
     * step's reported result and the artifacts it produced.
     *
     * @param list<Artifact> $artifacts
     */
    private function critic(string $name, string $result, string $rubric, array $artifacts): ?string
    {
        $rendered = $artifacts === []
            ? '(the step recorded no artifacts)'
            : implode("\n", array_map(static fn (Artifact $a): string => $a->render(), $artifacts));

        $verdict = trim($this->ai(
            "You are a REVIEWER checking the work of step '{$name}'. Your ONLY job is to verify it "
            . 'against the rubric below: inspect the artifacts, read the files, run linters/tests with '
            . 'the tools as needed, and report. Do NOT implement, edit, or fix anything yourself — you '
            . "judge and list findings, nothing more.\n\n"
            . "Rubric (judge ONLY against this):\n{$rubric}\n\n"
            . "What the step reports it did:\n{$result}\n\n"
            . "Artifacts the step produced:\n{$rendered}\n\n"
            . "If the work fully satisfies the rubric, reply with exactly: OK\n"
            . 'Otherwise reply with the concrete problems that must be fixed.',
            null,   // every tool — a critic is a normal AI and must be able to verify, not just read
            'reviewer',
        ));

        return strtoupper($verdict) === 'OK' ? null : $verdict;
    }

    /**
     * The critic rejected the step — consult the supervisor (the ask channel; behind it a supervisor
     * agent, then a human). Returns guidance for a re-run, or null to accept the work as-is. With no
     * channel the run self-corrects on the critic's own findings.
     *
     * @throws WorkflowException when the supervisor says to stop
     */
    private function superviseStep(string $name, string $result, string $findings): ?string
    {
        $channel = $this->env->find(EnvKey::Ask);
        if (!$channel instanceof SpeakerInterface) {
            return $findings;   // no supervisor/human -> self-correct using the critic's findings
        }

        $reply = $channel->reply(
            "Step '{$name}' did not pass review.\nFindings:\n{$findings}\n\n"
            . "The work:\n{$result}\n\n"
            . "Reply with guidance to fix it, or 'accept' to keep it as is, or 'stop' to abort.",
        );
        if ($reply === null) {
            return $findings;   // the chain passed all the way up with no answer -> self-correct
        }

        $answer = trim($reply);
        $lower = strtolower($answer);
        if ($answer === '' || str_starts_with($lower, 'accept')) {
            return null;   // accept the work as-is
        }
        if (str_starts_with($lower, 'stop')) {
            throw new WorkflowException("run stopped at step '{$name}' by the supervisor");
        }

        return $answer;   // guidance for the re-run
    }

    /**
     * A tool call through the run's executor. A tool error does NOT throw: its message is returned
     * as the result string (prefixed so it is unmistakable), exactly as a tool error inside {@see
     * ai()} is handed back to the model rather than crashing the turn. A step that feeds the result
     * into a later ai() thus lets the model see and react to the failure — a wrong path, a red test —
     * instead of the whole run dying on one bad call.
     *
     * @param array<string, mixed> $params
     */
    protected function tool(string $name, array $params): string
    {
        $tracer = $this->tracer();
        $tracer?->toolCall($name, $params);

        $result = $this->env->executor()->call(new ToolCall($this->env->findStore()->nextId(), $name, $params));

        $tracer?->toolResult($name, $result->content, $result->isError);
        if ($result->isError) {
            return "tool '{$name}' failed: " . $result->content;
        }

        return $result->content;
    }

    /**
     * Ask a question of whoever sits on the run's ask channel — a person at the console, or an agent
     * (any {@see SpeakerInterface} placed in {@see EnvKey::Ask}) — and return their answer. The
     * exchange is two-way, so it runs OFF the trace; the question and answer are noted at
     * {@see Level::Notice}, so they surface even in a quiet run.
     *
     * @throws WorkflowException when no ask channel is configured (an autonomous run with no one to ask)
     */
    protected function ask(string $question): string
    {
        $channel = $this->env->find(EnvKey::Ask);
        if (!$channel instanceof SpeakerInterface) {
            throw new WorkflowException('the workflow asked for input but no ask channel is configured');
        }

        $this->tracer()?->log('ask', $question, [], Level::Notice);
        $answer = $channel->reply($question) ?? '';   // a fully-escalated chain with no answer reads as empty
        $this->tracer()?->log('answer', $answer, ['from' => $channel->name()->value], Level::Notice);

        return $answer;
    }

    /**
     * Note something the workflow's own code did (a "task"). There is no Task class; the AI writes
     * its task methods and logs their specifics here — it lands under the current span in the trace.
     * Pass a higher $level (e.g. {@see Level::Notice}) for a note that should show even when quiet.
     *
     * @param array<string, mixed> $context
     */
    protected function log(string $action, string $message = '', array $context = [], Level $level = Level::Info): void
    {
        $this->tracer()?->log($action, $message, $context, $level);
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
        foreach (new \ReflectionClass($this)->getMethods() as $method) {
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
