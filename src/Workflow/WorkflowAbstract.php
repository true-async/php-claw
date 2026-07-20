<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Agent\Budget;
use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\Message;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\TurnLoopInterface;
use Claw\Exceptions\WorkflowException;
use Claw\Exceptions\WorkflowFinished;
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
 *    workflow's state + progress to the {@see WorkflowStateStoreInterface}. The state is restored at
 *    construction, so a skipped step loses nothing.
 *  - {@see run()} is just the entry point — by default it drives the step methods in order, but
 *    the author may override it and orchestrate by hand (plain if/while), calling step() as needed.
 *
 * A critic, though, IS machinery here: a step can declare {@see Step::$critic}, and the driver judges
 * the step's RESULT (the method's return value) against it on the reviewer role; while it falls short,
 * the supervisor (the ask channel) guides a re-run — a declarative aspect, not a hand-written sub-step.
 *
 * The critic is a gate the step cannot open from the inside. A worker that ends the run with the `done`
 * tool does not escape review: the signal is held, the critic judges the work with that claim in hand,
 * and only a passing verdict lets the run finish (see {@see step()}). Otherwise the party being reviewed
 * would decide whether the review happens.
 */
abstract class WorkflowAbstract implements WorkflowInterface
{
    /**
     * Default soft cap on critic rework rounds for a step — deliberately SMALL. A critic exists to catch
     * a step and let it fix itself once or twice; if two rounds do not close the findings, the problem is
     * usually a mismatch (the step's prompt vs the critic's rubric) or a task that truly needs a human, not
     * "one more try" — so we escalate rather than churn dozens of rounds burning tokens. A step that
     * legitimately churns (e.g. a test gate) raises it per case via `#[Step(maxRounds: N)]`. A checkpoint,
     * not a hard kill; the budget is still the ultimate backstop.
     */
    private const int DEFAULT_MAX_ROUNDS = 2;

    /** Reserved snapshot key under which step-set params ride (no subclass field is named this). */
    private const string STEP_PARAMS_KEY = '__params';

    /** @var list<string> step methods already completed (restored from the store) — skipped on re-run */
    private array $done;

    /** The critic/supervisor's latest guidance for the running step, exposed via {@see critique()}; transient. */
    private ?string $critique = null;

    /** The step currently running, so {@see artifact()} attaches its outputs to the right step; transient. */
    private string $currentStep = '';

    /**
     * The handoff fed into the current step's model context — what the previous step handed on. Formed
     * lazily from {@see $pendingHandoff} on the first ai() call of a step, and persisted as it is formed
     * so a resume (a fresh process whose in-memory history is gone) can {@see loadHandoff()} it back
     * here at construction instead. Read by {@see handoffContext()}. '' for the first step.
     */
    private string $incomingHandoff = '';

    /**
     * The previous step's name + the conversation history of its work, awaiting handoff formation —
     * set at step end, consumed by the next step's first ai() call (which continues that history to
     * ask the model for the handoff IN CONTEXT). Null = nothing pending (e.g. on a resume, where the
     * already-formed handoff is restored from the store rather than re-formed).
     *
     * @var ?array{name: string, history: list<Message>}
     */
    private ?array $pendingHandoff = null;

    /**
     * The full message history of the most recent {@see ai()} exchange — kept so a step's handoff can
     * be formed by CONTINUING that exact conversation (the model still holds what it actually did),
     * not from a cold re-summary. Transient.
     *
     * @var list<Message>
     */
    private array $lastHistory = [];

    /**
     * The prior attempt's conversation, carried into a critic re-run (or a {@see back()} jump) so the
     * step's next ai() CONTINUES that history instead of cold-restarting: the model keeps everything it
     * already did and reacts to the critique, rather than re-deriving the whole step from scratch. The
     * attempt's FIRST ai() consumes it (then it clears); empty otherwise. Transient.
     *
     * @var list<Message>
     */
    private array $resumeHistory = [];

    /**
     * Each step's last work conversation, kept so a {@see back()} into an earlier step can CONTINUE it
     * (the model re-enters with full context, not cold). Transient — a resume rebuilds it as steps re-run.
     *
     * @var array<string, list<Message>>
     */
    private array $stepHistory = [];

    /** A {@see back()} request made during the running step: the earlier step to re-enter, and why. */
    private ?string $backTo = null;

    private string $backReason = '';

    /** The step the driver is re-entering via back(), and the reason to hand it — its first-attempt guidance. */
    private ?string $reentryStep = null;

    private string $reentryReason = '';

    /**
     * Artifacts produced this run, kept per step so PRIOR steps' outputs are not lost — only the
     * current step's slot is reset on a critic re-run (it regenerates them). Transient: not part of
     * resume state (the journal is the durable copy a resumed run reads back).
     *
     * @var array<string, list<Artifact>>
     */
    private array $artifacts = [];

    /**
     * This workflow's own #[Tool] methods, wrapped as tools — discovered once by reflection, then cached.
     *
     * @var ?list<ToolInterface>
     */
    private ?array $localTools = null;

    /** This run's own environment scope — a child of the injected (project) env, so init() overrides locally. */
    private readonly Environment $env;

    /**
     * Parameters a step pinned FOR A SPECIFIC later step via {@see setParam()} — a CONCRETE value (path,
     * count, id, flag) the target step reads with {@see param()} and uses in CODE. Keyed by the TARGET
     * step's name, so it is ADDRESSED, not global: a step sees only the params aimed at it and cannot peek
     * another step's (unlike an artifact, which every later step sees). The target step's critic does see
     * them. Durable: ridden in the state snapshot, restored on resume; each set is also journaled (a
     * `param` trace event) so it can be inspected.
     *
     * @var array<string, array<string, mixed>> targetStep => (name => value)
     */
    private array $stepParams = [];

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

        $store = $this->env->findStore();
        $snapshot = $store->load($runId);
        $this->restoreState($snapshot['state']);   // a resumed run sees the state its done steps left behind
        $this->done = $snapshot['done'];

        // Restore the handoff awaiting the next step: the one the LAST finished step formed. A handoff
        // from any earlier step is stale (its reader already ran), so it is ignored — the next step
        // simply gets none, as it would have had the crash struck a moment earlier.
        $saved = $store->loadHandoff($runId);
        $lastDone = $this->done === [] ? null : $this->done[array_key_last($this->done)];

        if ($saved['from'] !== '' && $saved['from'] === $lastDone) {
            $this->incomingHandoff = $saved['handoff'];
        }
    }

    abstract public function name(): string;

    /**
     * The run's entry point. Default: drive every {@see Step} method in declaration order, each
     * skipped if already done. A step may {@see back()} to an earlier step — the driver then re-runs
     * that step onward (so a review can send the work back to where it was produced). Override to
     * orchestrate by hand — it is plain PHP (ordering, if/while, sub-workflows); call
     * $this->step('methodName') to run a step with the same skip-and-snapshot guarantee.
     */
    public function run(): void
    {
        try {
            $names = $this->stepMethods();
            $index = 0;

            while ($index < \count($names)) {
                $this->step($names[$index]);
                $index = $this->backTo === null ? $index + 1 : $this->rewindTo($names, $index);
            }
        } catch (WorkflowFinished $finished) {
            // the model called `done` AND the step's critic let it stand: the task is solved, skip the rest.
            $this->tracer()?->log('done', $finished->summary, [], Level::Notice);
        }
    }

    /**
     * Send the run BACK to an earlier step from inside the current one (e.g. a review that wants the
     * work redone where it was produced). The default {@see run()} re-runs the target onward; the target
     * re-enters CONTINUING its own conversation (so the model keeps its context) and reads $reason as its
     * first-attempt guidance via {@see critique()}. Recorded in the journal so the jump and its reason are
     * visible. Within a hand-written run(), honor it yourself (e.g. loop back to the step).
     */
    protected function back(string $toStep, string $reason): void
    {
        if (!\in_array($toStep, $this->stepMethods(), true)) {
            throw new \LogicException("back('{$toStep}'): no such step");
        }
        $this->backTo = $toStep;
        $this->backReason = $reason;
        $this->tracer()?->back($this->currentStep, $toStep, $reason);
    }

    /**
     * Carry out a back() requested during the step at $from: clear the done-marks of target..$from so they
     * re-run, arm the target's re-entry (continue its history + read the reason), and return the target's
     * index for the driver to jump to.
     *
     * @param list<string> $names
     */
    private function rewindTo(array $names, int $from): int
    {
        $target = (string) $this->backTo;
        $this->backTo = null;
        $to = array_search($target, $names, true);

        if ($to === false || $to > $from) {
            throw new \LogicException("back('{$target}') must name an EARLIER step");
        }

        for ($k = $to; $k <= $from; ++$k) {
            $this->done = array_values(array_filter($this->done, static fn (string $d): bool => $d !== $names[$k]));
        }
        $this->reentryStep = $target;
        $this->reentryReason = $this->backReason;

        return $to;
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
            $step = $this->stepAttribute($name);   // reflect the Step attribute once, read both fields off it
            $rubric = $this->criticRubric($step, $name);
            $this->critique = null;

            // Run the step; if it declares a critic, judge the ARTIFACTS it produced (its reviewable
            // output — the return value is NOT a channel), and while the critic is unhappy let the
            // supervisor guide a re-run — until the critic passes, the supervisor accepts/stops, the
            // soft round cap escalates, or the budget runs out.
            $round = 0;
            $maxRounds = $this->maxRounds($step);
            $workHistory = [];
            $resume = [];   // the prior attempt's conversation; a re-run continues it (empty on the first attempt)
            $finished = null;   // the `done` this attempt raised, held until the critic settles it

            if ($this->reentryStep === $name) {
                $resume = $this->stepHistory[$name] ?? [];   // a back() into this step continues its prior conversation
                $this->critique = $this->reentryReason;        // the back() reason is its first-attempt guidance
                $this->reentryStep = null;
                $this->reentryReason = '';
            }

            while (true) {
                $this->artifacts[$name] = [];   // a fresh attempt of THIS step regenerates its artifacts; prior steps keep theirs
                $this->lastHistory = [];        // so a step that makes no ai() call leaves no (stale) history
                $this->resumeHistory = $resume; // a re-run's first ai() CONTINUES the prior attempt, not a cold restart
                $finished = null;               // each attempt must EARN the right to end the run afresh

                try {
                    $this->{$name}();   // the return value is NOT a channel — the step's output is its artifacts/handoff
                } catch (WorkflowFinished $signal) {
                    // The worker called `done`: it declares the WHOLE TASK solved. That is a CLAIM, not a
                    // verdict — and the one making it is the very work under review. So the signal is HELD
                    // here and the critic runs anyway; only a passing review lets it end the run (below).
                    // Without this, `done` walked straight past the gate: a worker that ran `php -l` and
                    // called it a day closed issues whose test step never ran.
                    $finished = $signal;
                }
                $workHistory = $this->lastHistory;   // the work exchange — its handoff continues THIS context

                if ($rubric === null) {
                    break;
                }

                $artifacts = $this->renderArtifacts($this->artifacts[$name]);

                // Deterministic guard: a critic'd step that did NOTHING — no model/tool work AND no artifact
                // — produced no result. We see that without spending an AI critic (which would only probe the
                // journal in circles). Report it straight instead.
                if ($workHistory === [] && $this->artifacts[$name] === []) {
                    $findings = "step '{$name}' produced nothing: no model/tool work and no artifact. A step "
                        . 'must do real work and leave a result; if it needs no review, it should carry no critic.';
                } else {
                    $findings = $this->critic($name, $rubric, $artifacts, $finished?->summary);
                }

                if ($findings === null) {
                    break;   // the critic is satisfied
                }

                $guidance = $this->superviseStep($name, $artifacts, $findings, ++$round, $maxRounds);

                if ($guidance === null) {
                    break;   // the supervisor accepted the work as-is
                }

                $this->critique = $guidance;   // the re-run reads this via critique()
                $resume = $workHistory;        // the next attempt continues THIS attempt's conversation
                $this->enforceBudget();        // the round spent tokens; stop here if the budget is gone
            }
        } finally {
            $this->critique = null;
            $this->currentStep = $previousStep;
            $tracer?->exit($span);
        }

        // Remember the work exchange for this step. The handoff is formed LAZILY — only if a later
        // step actually calls ai() (no point asking, or paying, when nothing downstream reads it,
        // e.g. the last step, or a step that finishes through a tool) — by CONTINUING this history,
        // and is persisted as it is formed. See {@see formPendingHandoff()}.
        $this->pendingHandoff = ['name' => $name, 'history' => $workHistory];
        $this->stepHistory[$name] = $workHistory;   // kept so a later back() into this step continues its context

        $this->done[] = $name;
        $this->env->findStore()->save($this->runId, $this->captureState(), $this->done);

        // The step is closed and snapshotted; only NOW may a reviewed `done` end the run. Re-raising
        // after the bookkeeping (rather than from inside the step) is what makes the finish durable —
        // the finishing step used to leave no trace in the snapshot at all.
        if ($finished !== null) {
            throw $finished;
        }
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

    /**
     * Read a parameter addressed to the CURRENTLY running step (pinned by an earlier step via
     * {@see setParam()}), else a run INPUT param (the value that makes the workflow describe one task).
     * Null if neither set it. A step sees ONLY params aimed at it — it cannot read those sent to another
     * step.
     */
    protected function param(string $name): mixed
    {
        return $this->stepParams[$this->currentStep][$name] ?? $this->params[$name] ?? null;
    }

    /**
     * Pin a parameter FOR A SPECIFIC step ($forStep — the target step's method name): a concrete value
     * (path, count, id, flag) that step reads back with {@see param()} and uses in code. The THIRD
     * inter-step channel beside artifact (content for the model/critic) and handoff (a prose baton), and
     * the one that is ADDRESSED: unlike an artifact (global — every later step and the critic see it), only
     * $forStep reads a param; no other step can peek (the target step's OWN critic does see it). Use it
     * when a step decides an exact value the code of a particular later step needs deterministically.
     * Durable — saved with the snapshot, survives a resume — and journaled so it can be inspected.
     * Entirely optional: a workflow that passes nothing this way is perfectly valid.
     */
    protected function setParam(string $forStep, string $name, mixed $value): void
    {
        $this->stepParams[$forStep][$name] = $value;
        $this->tracer()?->param($forStep, $name, $value);
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
     * Record a named output the current step produced. Artifacts are journaled (so they show in
     * `claw log`) and handed to the step's critic for review; a step that declares a critic SHOULD
     * emit the artifacts the rubric is judged against.
     *
     * Three channels, and the choice is about WHO WROTE THE CONTENT:
     *
     *  - $evidence — the VERBATIM output of a tool the step ran, with $from naming the tool. Use this
     *    whenever the rubric turns on a fact a command can settle: `$out = $this->tool('bash', [...]);
     *    $this->artifact('tests', evidence: $out, from: 'bash')`. It is the only channel a step cannot
     *    compose, which is exactly why it exists — a step once recorded "All tests passed." while the
     *    suite was erroring, and the run closed the issue. Pass $text alongside it to add the step's
     *    own reading of that output; it is kept and shown separately, as the step's claim.
     *  - $file — a path (relative to the project) the step wrote; the critic opens it itself.
     *  - $text — the step's own words. Fine for a decision or generated source; not proof of anything.
     *
     * For inline text, $lang names the content type (e.g. 'php', 'json', 'diff') so a viewer can render
     * it properly; omit it to let the content be sniffed. A file's type comes from its path.
     */
    protected function artifact(
        string $label,
        ?string $text = null,
        ?string $file = null,
        string $lang = '',
        ?string $evidence = null,
        string $from = '',
    ): void {
        // Exactly one CONTENT channel — enforce the contract rather than silently preferring one
        // (dropping the other) or recording an empty artifact when none is given. $text is the one
        // exception: alongside $evidence it is not content but the step's own note about it, so it
        // rides along, stored and shown separately.
        $entry = match (true) {
            $evidence !== null && $file === null => Artifact::evidence($label, $evidence, $from, $text ?? ''),
            $file !== null && $text === null => Artifact::file($label, $file),
            $text !== null && $file === null => Artifact::text($label, $text, $lang),
            default => throw new \LogicException(
                "artifact('{$label}') needs exactly one of \$text, \$file or \$evidence "
                . '(a $text alongside $evidence is allowed — it is the step\'s note about that output).',
            ),
        };
        $this->artifacts[$this->currentStep][] = $entry;
        $this->tracer()?->artifact(
            $entry->label,
            $entry->kind,
            $entry->value,
            $entry->ext,
            $entry->mime,
            $entry->source,
            $entry->note,
        );
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
        $this->formPendingHandoff();   // a downstream step is reading: form (and persist) the previous step's handoff

        $prior = $this->resumeHistory;   // a re-run/back continues the prior attempt's conversation, not a cold restart
        $this->resumeHistory = [];       // only the attempt's first ai() continues; later calls start fresh

        return $this->runTurns($prompt, $tools, $agent, $prior);
    }

    /**
     * Drive one model exchange and return its final text. $prior is conversation history to CONTINUE
     * (empty for a fresh call); the prompt is appended as the next user turn. The whole exchange's
     * history is kept in {@see $lastHistory} so the step can later continue it (e.g. to form its
     * handoff IN the same context the work happened, not from a cold summary).
     *
     * @param ?list<string> $tools null = every tool; a list = only those; [] = none
     * @param list<Message> $prior conversation to continue
     */
    private function runTurns(string $prompt, ?array $tools, ?string $agent, array $prior): string
    {
        $scope = $this->paletteScope($tools, $agent);
        $palette = $scope->findRegistry();

        $exposed = array_map(static fn (ToolInterface $t): string => $t->name(), $palette->all());
        $tracer = $this->tracer();
        $span = $tracer?->enterAi($agent ?? 'worker', $scope->findModelId());
        $tracer?->prompt($prompt, $exposed);

        // The handoff from the previous step is fed in automatically — the selective context carry-over —
        // plus the available tools named up front so the model reliably reaches for the right one
        // (recall, done, ...) instead of only sometimes noticing them.
        $system = $scope->findSystemPrompt() . $this->handoffContext() . $palette->briefing('Tools available to you this step — call them by name when useful');

        // The ask channel (if any) makes the turn loop interactive: the model can pause to ask a
        // person/agent mid-call via the [question] marker, not only through an explicit $this->ask().
        $ask = $scope->find(EnvKey::Ask);

        $loop = $this->makeTurnLoop($scope, $system, $ask instanceof SpeakerInterface ? $ask : null);

        try {
            $result = $loop->run([...$prior, Message::userText($prompt)]);
            $this->lastHistory = $result->history;   // kept so a handoff can continue this exact context
            $this->enforceBudget();                  // the loop charged the total — stop the run if that tipped it over

            return $result->text ?? '';
        } catch (WorkflowFinished $signal) {
            // `done` cut the exchange short, so the assignment above never ran. Record the conversation
            // the signal carried anyway: it is the only evidence of what the worker did, and the critic
            // waiting in step() reviews exactly this.
            $this->lastHistory = $signal->history;

            throw $signal;
        } finally {
            $tracer?->exit($span);
        }
    }

    /**
     * The child scope one {@see ai()} call runs in: the run's registry plus this workflow's own
     * #[Tool] methods, full by default or narrowed to exactly $tools for a least-privilege step, and
     * routed to $agent's model when a role is named. The model's specs and what the executor can
     * resolve are the same set either way.
     *
     * @param ?list<string> $tools null = every tool; a list = only those; [] = none
     */
    private function paletteScope(?array $tools, ?string $agent): Environment
    {
        $registry = $this->withLocalTools($this->env->findRegistry());
        $palette = $tools === null ? $registry : $registry->only($tools);
        $scope = $this->env->child()->set(EnvKey::Registry, $palette);

        $model = $agent !== null ? $this->agentModel($agent) : null;

        if ($model !== null) {
            $scope->set(EnvKey::ModelId, $model);   // route this call to the role's model
        }

        return $scope;
    }

    /**
     * Build the turn loop for one exchange from the call's scope. Kept a method (not a newed-up local)
     * so the wiring lives in one place and the loop is an overridable {@see TurnLoopInterface} seam —
     * the budget caps this one exchange and its spend bubbles up to the run total.
     */
    private function makeTurnLoop(Environment $scope, string $system, ?SpeakerInterface $ask): TurnLoopInterface
    {
        return new DefaultTurnLoop(
            $scope->findWorker(),
            $scope->executor(),
            $scope->findModelId(),
            $system,
            $scope->findRegistry()->specs(),
            $scope->findMaxHistory(),
            $this->tracer(),
            $ask,
            $this->turnBudget(),
        );
    }

    /**
     * Form the previous step's handoff — once — when a downstream step's ai() reads it. The handoff
     * is NOT a grab of the return value: the model is EXPLICITLY asked to write it by CONTINUING the
     * step's own work conversation, so it still holds what it actually did (its tool calls, what it
     * read/changed), not a cold re-summary. The result is SAVED to the store keyed by the step that
     * formed it the instant it exists — so a resume in a fresh process, where that conversation is
     * gone, reads it back at construction ({@see loadHandoff()}) instead of re-forming it. Cleared
     * before the inner call so it never re-enters; a step that ran no model exchange hands on ''.
     */
    private function formPendingHandoff(): void
    {
        $pending = $this->pendingHandoff;

        if ($pending === null) {
            return;
        }
        $this->pendingHandoff = null;   // clear FIRST: the formation below drives the turn loop again

        $this->incomingHandoff = $pending['history'] === [] ? '' : trim($this->runTurns(
            'Now, before this step ends, CONSCIOUSLY write the HANDOFF to the NEXT step: in a few '
            . 'sentences, state what you accomplished here and the findings the next step must pay '
            . 'attention to — decisions made, files/paths touched, what remains, gotchas. Pass on only '
            . 'what matters, not everything. Reply with that handoff only.',
            [],
            null,
            $pending['history'],   // continue the work conversation — the model still has the full context
        ));

        // Persist it the moment it is formed, keyed by the step that formed it. A resume that lands on
        // the next step loads it straight back instead of re-asking the model (whose context is gone).
        $this->env->findStore()->saveHandoff($this->runId, $pending['name'], $this->incomingHandoff);

        if ($this->incomingHandoff !== '') {
            $this->tracer()?->handoff($this->incomingHandoff);
        }
    }

    /** The previous step.s handoff as a context block for the system prompt, or '' for the first step. */
    private function handoffContext(): string
    {
        if ($this->incomingHandoff === '') {
            return '';
        }

        return "\n\nThe previous step handed this to you (what it did and what to watch for):\n" . $this->incomingHandoff;
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

    /**
     * The model id a named agent role runs on. Resolution — including the fallback chain that keeps a
     * strong role off the cheap default — belongs to {@see Environment::findAgentModel()}, which the
     * run pipeline shares; null here means "the scope's default already", so the caller leaves
     * {@see EnvKey::ModelId} untouched rather than re-setting it to itself.
     */
    private function agentModel(string $agent): ?string
    {
        $model = $this->env->findAgentModel($agent);

        return $model === $this->env->findModelId() ? null : $model;
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

    /** The {@see Step} attribute on a step method, instantiated once per step run, or null if absent. */
    private function stepAttribute(string $name): ?Step
    {
        $attributes = new \ReflectionMethod($this, $name)->getAttributes(Step::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * The rules a step's result is judged against. The {@see Step} attribute names a critic; the
     * actual rules live in {@see criticRules()}, keyed by that name. Null when the step has no critic.
     * An unknown name is a generation bug — fail loud rather than judge against an empty rubric.
     */
    private function criticRubric(?Step $step, string $name): ?string
    {
        $critic = $step?->critic;

        if ($critic === null || $critic === '') {
            return null;
        }

        $rules = $this->criticRules()[$critic] ?? null;

        if ($rules === null || trim($rules) === '') {
            throw new \LogicException("Step '{$name}' names critic '{$critic}', but criticRules() has no rules for it.");
        }

        return $rules;
    }

    /** The soft critic-round cap for a step — its `#[Step(maxRounds: N)]`, else the workflow default. */
    private function maxRounds(?Step $step): int
    {
        $max = $step?->maxRounds;

        return $max !== null && $max > 0 ? $max : self::DEFAULT_MAX_ROUNDS;
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
     * here, is to REVIEW only: inspect and report, never do or fix the work itself. It judges the step's
     * reviewable output — its rendered artifacts (`$output`, see {@see renderArtifacts()}).
     *
     * $doneClaim is the summary a worker passed to `done` when it declared the whole task solved, or null
     * if it simply returned. It is handed over as a claim to CHECK, not as a fact: a worker that mistakes
     * a clean `php -l` for a finished task is exactly what this review exists to catch.
     */
    private function critic(string $name, string $rubric, string $artifacts, ?string $doneClaim = null): ?string
    {
        $verdict = trim($this->ai(
            $this->criticRole() . "\n\n"
            . "You are checking the work of step '{$name}'.\n\n"
            . "Rubric (judge ONLY against this):\n{$rubric}\n\n"
            . "Artifacts it recorded:\n{$artifacts}\n\n"
            . $this->renderDoneClaim($doneClaim)
            . $this->renderParams($this->stepParams[$name] ?? [])
            . 'An artifact is what the step SAYS it did. It is a claim, not evidence: a step writes its own '
            . 'artifact text and can assert success it never achieved — one reported "All tests passed" '
            . 'while the suite was erroring, and was believed. So when the step CLAIMS to have ALREADY '
            . 'achieved something checkable — the tests pass, the lint is clean, a file now contains '
            . 'something, a command succeeded — you MUST establish it yourself with a tool and judge the '
            . 'OUTPUT YOU SAW, never the summary. Replying OK on a claim you did not check is the one '
            . "failure this review cannot have.\n\n"
            . 'The RUBRIC decides what counts, and it OUTRANKS this instruction. In particular: if it tells '
            . 'you the artifact is code or a plan that has NOT run yet, the project as it stands is not '
            . 'evidence about it. Judge it on its own terms and do NOT hold the current state of the files, '
            . "or a red test suite, against work that was never supposed to have happened yet.\n\n"
            . 'Where verification does apply, verify against the PROJECT (read the changed files, run the '
            . 'tests or `php -l`), which is cheap and conclusive. Do NOT go spelunking the journal: '
            . "recall(what='step', name='{$name}') is available ONCE if you need to see what the step did, "
            . "and not beyond that.\n\n"
            . 'If it satisfies the rubric, reply with exactly: OK. Otherwise reply with the concrete problems '
            . 'that must be fixed.',
            null,   // every tool — a critic is a normal AI and must be able to verify, not just read
            'reviewer',
        ));

        return strtoupper($verdict) === 'OK' ? null : $verdict;
    }

    /**
     * The `done` claim put to the critic, or '' when the step just returned. Spelled out as a claim under
     * test — the worker ends the WHOLE run with it, so an unearned one is the costliest thing the review
     * can miss, and naming the usual mistake (a lint pass read as a finished task) is worth the tokens.
     */
    private function renderDoneClaim(?string $doneClaim): string
    {
        if ($doneClaim === null) {
            return '';
        }

        return 'The worker ENDED THE WHOLE RUN here: it called `done`, declaring the entire task solved and '
            . "every remaining step unnecessary, with this summary:\n{$doneClaim}\n\n"
            . 'Treat that as a CLAIM to verify, not a fact. It only holds if the task\'s real deliverable '
            . 'exists AND has been proven to work — running a syntax check, or asserting success without '
            . 'evidence, is NOT proof. If the rubric calls for tests, confirm they were actually run and '
            . "green; run them yourself if you must. If the claim does not hold, say so in your findings.\n\n";
    }

    /**
     * Render the concrete params addressed to a step for its critic — so the reviewer sees the exact
     * inputs an earlier step pinned for this one. Empty string when there are none.
     *
     * @param array<string, mixed> $params
     */
    private function renderParams(array $params): string
    {
        if ($params === []) {
            return '';
        }

        $lines = [];

        foreach ($params as $key => $value) {
            $lines[] = "- {$key} = " . json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return "Parameters addressed to this step (concrete values an earlier step pinned for it):\n"
            . implode("\n", $lines) . "\n\n";
    }

    /**
     * Render a step's artifacts as the reviewable output handed to the critic and the supervisor — the
     * step's TWO output channels are artifact and handoff, never its return value, so this is the work
     * they judge. No artifact at all means the step has nothing to show for itself, which is a finding in
     * its own right (a critic'd step must record at least one artifact).
     *
     * @param list<Artifact> $artifacts
     */
    private function renderArtifacts(array $artifacts): string
    {
        // No artifact does NOT mean "nothing to review": the critic is a real AI with every tool, so it
        // verifies the step's effect by inspecting the project (reading the files it changed, running
        // `php -l`/the tests). A genuinely empty step is for the critic to judge against the rubric, not
        // for the engine to pre-fail.
        return $artifacts === []
            ? '(this step recorded no artifact — verify its effect yourself: read the files it changed and run `php -l` / the tests)'
            : implode("\n", array_map(static fn (Artifact $a): string => $a->render(), $artifacts));
    }

    /**
     * The standing role prepended to every critic call — what the reviewer IS and may do. The default
     * casts it as a verify-only reviewer (inspect and report, never do or fix the work). A workflow
     * overrides this when its review needs a different stance; the engine still appends the rubric,
     * the step's result, its artifacts, and the OK/findings protocol.
     */
    protected function criticRole(): string
    {
        return 'You are a REVIEWER of a workflow step. Your ONLY job is to verify the work against the '
            . 'rubric: read the files the step touched and run the linters/tests yourself, then report on '
            . 'what you actually observed. Assume nothing from a summary — the step wrote that summary. '
            . 'Do NOT implement, edit, or fix anything yourself: you judge and list findings, nothing more.';
    }

    /**
     * The critic rejected the step — consult the supervisor (the ask channel; behind it a supervisor
     * agent, then a human). Returns guidance for a re-run, or null to accept the work as-is.
     *
     * Below the step's round cap ($maxRounds, default {@see DEFAULT_MAX_ROUNDS}) this self-corrects on
     * the critic's findings when no one is on the channel (the normal autonomous case). At/after the cap
     * it ESCALATES: the round count looks stuck,
     * so it asks the supervisor whether to accept, retry, or stop — and if there is no one to ask, it
     * stops the step rather than churn the same rework forever.
     *
     * @throws WorkflowException when the supervisor says to stop, or the cap is hit with no one to ask
     */
    private function superviseStep(string $name, string $work, string $findings, int $round, int $maxRounds): ?string
    {
        $stuck = $round >= $maxRounds;
        $channel = $this->env->find(EnvKey::Ask);

        if (!$channel instanceof SpeakerInterface) {
            if ($stuck) {
                throw new WorkflowException(
                    "step '{$name}' still failed review after {$round} rounds, with no supervisor to escalate to",
                );
            }

            return $findings;   // no supervisor/human -> self-correct using the critic's findings
        }

        // $work is the step's result (its artifacts) — the same context the critic had. The supervisor is
        // an agent with tools, so it can recall(what='step', name='…') for more if it needs to.
        $prompt = $stuck
            ? "Step '{$name}' has failed review {$round} times and the critic is still not satisfied.\n"
                . "Latest findings:\n{$findings}\n\nThe step's result (artifacts):\n{$work}\n\n"
                . "Is this OK? Reply 'accept' to keep it as is, 'stop' to abort, or guidance for one more try."
            : "Step '{$name}' did not pass review.\nFindings:\n{$findings}\n\n"
                . "The step's result (artifacts):\n{$work}\n\n"
                . "Reply with guidance to fix it, or 'accept' to keep it as is, or 'stop' to abort.";

        $reply = $channel->reply($prompt);

        if ($reply === null) {
            if ($stuck) {
                throw new WorkflowException("step '{$name}' still failed review after {$round} rounds");
            }

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
     * Strip a ``` ... ``` fence if the model wrapped the code in one — a base-level concern shared by
     * any code-generating workflow (the solver generator, the supervisor's repair), so it lives here.
     */
    protected function extractCode(string $text): string
    {
        $text = trim($text);

        if (preg_match('/```(?:php)?\s*(.+?)\s*```/s', $text, $m) === 1) {
            return trim($m[1]);
        }

        return $text;
    }

    /**
     * The substring `define_workflow` returns on a successful save. Sniffing the tool's prose is the
     * current save/reject protocol; the sentinel lives in one place so the code-generating workflows
     * that branch on it cannot drift.
     */
    protected const string WORKFLOW_SAVED_MARKER = 'saved as';

    /**
     * Save a generated workflow through the `define_workflow` tool, with one repair pass — the
     * save/detect/repair/retry control flow shared by every code-generating workflow. On the first
     * rejection it hands the validator's complaint to $revise (which re-drafts the source on the
     * appropriate role) and retries once; a second rejection throws. Returns the saved source.
     *
     * @param callable(string): string $revise given the rejection text, returns corrected source
     *
     * @throws WorkflowException on a second rejection
     */
    protected function saveGeneratedWorkflow(string $name, string $code, callable $revise): string
    {
        $result = $this->tool('define_workflow', ['name' => $name, 'code' => $code, 'shared' => true]);

        if (str_contains($result, self::WORKFLOW_SAVED_MARKER)) {
            return $code;
        }

        $code = $revise($result);
        $result = $this->tool('define_workflow', ['name' => $name, 'code' => $code, 'shared' => true]);

        if (!str_contains($result, self::WORKFLOW_SAVED_MARKER)) {
            throw new WorkflowException($result);   // a second failure surfaces to the run-path
        }

        return $code;
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
            if (!$property->isInitialized($this)) {
                continue;
            }

            $value = $property->getValue($this);

            // The snapshot is JSON-persisted; a closure or resource is not durable state and would
            // corrupt the store or fail opaquely later. Fail loud here, naming the offending field.
            if ($value instanceof \Closure || \is_resource($value)) {
                throw new \LogicException(sprintf(
                    "Workflow '%s' field \$%s holds a %s, which is not durable state — keep step state in "
                    . 'plain serializable properties (scalars, arrays, enums).',
                    static::class,
                    $property->getName(),
                    $value instanceof \Closure ? 'closure' : 'resource',
                ));
            }

            $state[$property->getName()] = $value;
        }

        // Step-set params ride in the snapshot too, under a reserved key (no subclass field can be named
        // it), so a resumed run reads back the concrete values earlier steps pinned. Only when non-empty.
        if ($this->stepParams !== []) {
            $state[self::STEP_PARAMS_KEY] = $this->stepParams;
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
        // Restore step-set params first (the reserved key is not a subclass field, so the loop skips it).
        if (\is_array($state[self::STEP_PARAMS_KEY] ?? null)) {
            $this->stepParams = $state[self::STEP_PARAMS_KEY];
        }

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
