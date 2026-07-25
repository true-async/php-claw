<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Agent\Budget;
use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\Message;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\ToolResultBlock;
use Claw\Agent\ToolUseBlock;
use Claw\Agent\TurnLoopInterface;
use Claw\Exceptions\ToolException;
use Claw\Exceptions\WorkflowException;
use Claw\Exec\ExecutorInterface;
use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Tool\DeferredToolInterface;
use Claw\Tool\Registry;
use Claw\Tool\ToolCall;
use Claw\Tool\ToolInterface;
use Claw\Tool\ToolResultMeta;
use Claw\Tool\ToolSearchTool;
use Claw\Trace\Level;
use Claw\Trace\Tracer;

/**
 * The base every workflow extends — a HELPER, not an engine. The workflow itself is a class with state
 * (its own fields); its steps are methods the base drives in declaration order, of two kinds. A
 * {@see StepAI} is a PURE method returning an {@see AiStep} declaration — a prompt, a tool palette, an
 * agent role and any {@see ParamRequest}s — and the base runs, records and resumes that ONE model exchange
 * for it. A {@see Step} is a CODE method: deterministic glue that reads params and calls {@see tool()},
 * re-run whole on resume. The base does not do the AI work; it makes the step's declaration durable and
 * drives it:
 *
 *  - a {@see StepAI}'s exchange runs with a least-privilege tool palette (the turn loop inside is an
 *    internal detail); the model does the work through the tools — reading and writing files, recording
 *    artifacts, asking a person. {@see tool()} runs one tool from a CODE step; {@see param()} reads run
 *    inputs and any value an earlier step addressed to this one.
 *  - {@see step()} runs a step method unless a prior run already did, then snapshots the
 *    workflow's state + progress to the {@see WorkflowStateStoreInterface}. The state is restored at
 *    construction, so a skipped step loses nothing.
 *  - {@see run()} is just the entry point — by default it drives the step methods in order, but
 *    the author may override it and orchestrate by hand (plain if/while), calling step() as needed.
 *
 * A critic, though, IS machinery here: a step — imperative {@see Step} or declarative {@see StepAI} —
 * can declare one, and the driver judges the step's recorded artifacts against it on the reviewer role;
 * while it falls short, the supervisor (the ask channel) guides a re-run — a declarative aspect, not a
 * hand-written sub-step.
 *
 * The critic is a gate the step cannot open from the inside, and there is no longer any way for a step
 * to open it from the inside: a worker does its work and returns, and whether the run ends is decided
 * by the code that drives the steps. A `done` tool once let the worker end the run itself — the party
 * under review deciding whether the review mattered — and every false completion this project has paid
 * for came through it.
 */
abstract class WorkflowAbstract implements WorkflowInterface
{
    /**
     * Default soft cap on critic rework rounds for a step — deliberately SMALL. A critic exists to catch
     * a step and let it fix itself once or twice; if two rounds do not close the findings, the problem is
     * usually a mismatch (the step's prompt vs the critic's rubric) or a task that truly needs a human, not
     * "one more try" — so we escalate rather than churn dozens of rounds burning tokens. A step that
     * legitimately churns (e.g. a test gate) raises it per case via `#[Step(maxRounds: N)]` (or
     * `#[StepAI(maxRounds: N)]`). A checkpoint, not a hard kill; the budget is still the ultimate backstop.
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
     * Every tool run of the CURRENT step's work exchange, keyed by step then by the `run#N` handle the
     * model sees appended to that run's output. It is how {@see recordArtifact()}'s `run` channel freezes
     * a run the model ALREADY did into evidence — the output comes from here, the runtime's own record of
     * the execution, never re-typed by the model, so it is proof the same way {@see recordArtifact()}'s
     * `command` channel is, without running the thing a second time. Written by {@see recordRun()} through
     * the recording executor; read by the `run` channel. Per-step so a later step cannot cite an earlier
     * step's run.
     *
     * @var array<string, array<string, array{call: ToolCall, result: ToolResultBlock}>>
     */
    private array $runLedger = [];

    /**
     * True while a CRITIC's exchange is running — which happens inside the step it is reviewing, so
     * {@see $currentStep} still names that step.
     *
     * It exists because the step's persisted exchange is keyed by step, and the critic would otherwise
     * overwrite the worker's conversation with its own. Seen on a live run: the saved exchange for a
     * step contained "You are a REVIEWER of a workflow step…". Had the process died there, the resumed
     * step would have been handed the reviewer's conversation as its own — a worker continuing a
     * critique of itself, which is nonsense arriving in a form that looks like context.
     *
     * A critic's exchange is not worth persisting anyway: re-reviewing is cheap and, unlike the work,
     * repeating it costs nothing that was already achieved.
     */
    private bool $reviewing = false;

    /**
     * True while an engine-internal continuation (param extraction) is running. Like {@see $reviewing} it
     * suppresses the exchange checkpoint: extraction continues the step's OWN conversation, so without it a
     * crash mid-extraction would leave the step's exchange row holding the extraction Q&A, which a resume
     * would then replay as the step's work.
     */
    private bool $extracting = false;

    /** The verdict the critic recorded through the `verdict` tool during the current review; transient. */
    private ?Verdict $verdict = null;

    /**
     * Paths the model wrote during the current step attempt, collected from each ai() exchange and
     * turned into `file` artifacts once the step body is done — so the step's own recording wins and a
     * path is not doubled. Reset per attempt; see {@see recordWrittenFiles()}. Transient.
     *
     * @var array<string, true>
     */
    private array $writtenPaths = [];

    /**
     * The handoff fed into the current step's model context — what the previous step handed on. Formed
     * lazily from {@see $pendingHandoff} when a downstream step's exchange first reads it, and persisted as
     * it is formed so a resume (a fresh process whose in-memory history is gone) can {@see loadHandoff()} it
     * back here at construction instead. Read by {@see handoffContext()}. '' for the first step.
     */
    private string $incomingHandoff = '';

    /**
     * The previous step's name + the conversation history of its work, awaiting handoff formation —
     * set at step end, consumed when the next step's exchange begins (which continues that history to
     * ask the model for the handoff IN CONTEXT). Null = nothing pending (e.g. on a resume, where the
     * already-formed handoff is restored from the store rather than re-formed).
     *
     * @var ?array{name: string, history: list<Message>}
     */
    private ?array $pendingHandoff = null;

    /**
     * The full message history of the most recent model exchange — kept so a step's handoff can
     * be formed by CONTINUING that exact conversation (the model still holds what it actually did),
     * not from a cold re-summary. Transient.
     *
     * @var list<Message>
     */
    private array $lastHistory = [];

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
     * @var ?list<MethodTool>
     */
    private ?array $localTools = null;

    /** This run's own environment scope — a child of the injected (project) env, so init() overrides locally. */
    private readonly Environment $env;

    /**
     * The scope in force while a model exchange is running: that call's narrowed palette. Null
     * between exchanges, when the run's own scope applies.
     *
     * It exists so {@see tool()} obeys the restriction the step asked for. A tool call made by the MODEL
     * arrives through a {@see MethodTool} while the exchange is in flight, and it reaches the world by
     * the same tool() a step's own code uses — which resolved against the run's full registry, so a step
     * that narrowed its palette to withhold `bash` still handed the model a shell through any local tool
     * that runs commands. Nullable rather than always-set because a step's code before and after its
     * exchanges is the author's, not the model's, and is not what the narrowing was aimed at.
     */
    private ?Environment $activeScope = null;

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
        $names = $this->stepMethods();
        $index = 0;

        while ($index < \count($names)) {
            $this->step($names[$index]);
            $index = $this->backTo === null ? $index + 1 : $this->rewindTo($names, $index);
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

        if ($this->isAiStep($name)) {
            $this->runAiStep($name);   // a #[StepAI] step is driven by the base, not by an imperative body

            return;
        }

        $this->enforceBudget();   // don't begin a new step once the run's budget is spent

        $tracer = $this->tracer();
        $span = $tracer?->enterStep($name);
        $previousStep = $this->currentStep;
        $this->currentStep = $name;   // so artifact() records under this step

        try {
            $step = $this->stepAttribute($name);   // reflect the Step attribute once, read both fields off it
            $rubric = $this->criticRubric($step?->critic, $name);
            $this->critique = null;

            // Run the step; if it declares a critic, judge the ARTIFACTS it produced (its reviewable
            // output — the return value is NOT a channel), and while the critic is unhappy let the
            // supervisor guide a re-run — until the critic passes, the supervisor accepts/stops, the
            // soft round cap escalates, or the budget runs out.
            $round = 0;
            $maxRounds = $this->maxRounds($step?->maxRounds);
            $workHistory = [];
            $priorAttempt = null;   // fingerprint of the previous attempt's artifacts — see the identical-attempt stop

            if ($this->reentryStep === $name) {
                $this->critique = $this->reentryReason;   // the back() reason is its first-attempt guidance
                $this->reentryStep = null;
                $this->reentryReason = '';
            }

            while (true) {
                $this->artifacts[$name] = [];   // a fresh attempt of THIS step regenerates its artifacts; prior steps keep theirs
                $this->lastHistory = [];        // a CODE step drives no model exchange, so it leaves no (stale) history
                $this->writtenPaths = [];       // and re-collects which files it wrote (see recordWrittenFiles)

                $this->{$name}();   // the return value is NOT a channel — the step's output is its artifacts/handoff
                $this->emitWrittenFileArtifacts();   // the files it wrote become artifacts, after its own recording so a path is not doubled
                $workHistory = $this->lastHistory;   // the work exchange — its handoff continues THIS context

                if ($rubric === null) {
                    break;
                }

                $artifacts = $this->renderArtifacts($this->artifacts[$name]);

                // Deterministic guard: a critic'd step that did NOTHING — no model/tool work AND no artifact
                // — produced no result. We see that without spending an AI critic (which would only probe the
                // journal in circles). Report it straight instead.
                //
                // Second deterministic guard, for the churn two real runs paid for: a re-run whose artifacts
                // are byte-identical to the previous attempt's. The guidance changed NOTHING, so re-judging
                // is re-buying the same verdict and re-running is re-buying the same work — hand the
                // supervisor the fact instead and let it settle the round (accept, redirect, or stop).
                // Byte-exact on purpose: evidence that legitimately varies (timings) simply never matches,
                // and the guard stays out of the way.
                $attempt = $this->attemptFingerprint($name);

                $verdict = $this->verdictFor(
                    $name,
                    $rubric,
                    $artifacts,
                    $workHistory === [] && $this->artifacts[$name] === [],
                    $priorAttempt !== null && $attempt === $priorAttempt,
                );
                $priorAttempt = $attempt;

                if ($verdict->passes()) {
                    break;   // the critic is satisfied
                }

                $guidance = $this->superviseStep($name, $artifacts, $verdict, ++$round, $maxRounds);

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

        // Remember the work exchange for this step. The handoff is formed LAZILY — only if a later
        // step actually runs an exchange (no point asking, or paying, when nothing downstream reads it,
        // e.g. the last step, or a step that finishes through a tool) — by CONTINUING this history,
        // and is persisted as it is formed. See {@see formPendingHandoff()}.
        $this->pendingHandoff = ['name' => $name, 'history' => $workHistory];

        $this->done[] = $name;
        $this->env->findStore()->save($this->runId, $this->captureState(), $this->done);

        // The step is finished and its result is in the snapshot and the journal. The half-finished
        // conversation kept for a resume is now only a way to re-enter a step that has already left.
        $this->env->findStore()->clearExchange($this->runId, $name);
    }

    /** Whether $name is an AI step (marked {@see StepAI}) — read by reflection, without running the body. */
    private function isAiStep(string $name): bool
    {
        return $this->aiAttribute($name) !== null;
    }

    /**
     * Drive a {@see StepAI} step. The method itself is pure — it returns an {@see AiStep} declaration and
     * does nothing else; the base runs the one exchange it names, records it as it happens, and on resume
     * CONTINUES the recorded conversation rather than re-running the model from the top. The step's output
     * is its handoff, formed from this exchange for the next step, exactly as an imperative step's is.
     *
     * A crash while the step was PARKED on a person's answer resumes here: {@see runAiExchange()} finds
     * the recorded exchange ending on an unanswered [question] and continues it from the answer. The body
     * is never re-entered after a resume and is pure anyway, so there is nothing to do twice.
     */
    private function runAiStep(string $name): void
    {
        $this->enforceBudget();

        $tracer = $this->tracer();
        $span = $tracer?->enterStep($name);
        $previousStep = $this->currentStep;
        $this->currentStep = $name;
        $this->critique = null;
        $workHistory = [];

        // A back() into this step re-enters it fresh (its exchange was cleared when it finished); continuing
        // the prior attempt is a refinement the imperative path has and this one will grow. The reason is
        // carried in as this re-entry's guidance so the pure body can fold it into the AiStep it declares
        // (via critique()) — the same contract back() documents and the imperative path already honours;
        // without this the reason was silently dropped. Clear the arming so it does not leak to a later step.
        if ($this->reentryStep === $name) {
            $this->critique = $this->reentryReason;
            $this->reentryStep = null;
            $this->reentryReason = '';
        }

        try {
            $declaration = $this->{$name}();

            if (!$declaration instanceof AiStep) {
                throw new \LogicException("step '{$name}' is marked #[StepAI] but did not return an AiStep declaration");
            }

            $attribute = $this->aiAttribute($name);
            $rubric = $this->criticRubric($attribute?->critic, $name);
            $maxRounds = $this->maxRounds($attribute?->maxRounds);

            $this->formPendingHandoff();   // form the PREVIOUS step's handoff before this one's exchange runs
            $workHistory = $this->reviewedExchange($name, $declaration, $rubric, $maxRounds);
            $this->extractParams($declaration, $workHistory);   // hand any declared machine-readable values to later steps
        } finally {
            $this->critique = null;
            $this->currentStep = $previousStep;
            $tracer?->exit($span);
        }

        $this->pendingHandoff = ['name' => $name, 'history' => $workHistory];
        $this->done[] = $name;
        $this->env->findStore()->save($this->runId, $this->captureState(), $this->done);
        $this->env->findStore()->clearExchange($this->runId, $name);
    }

    /** The {@see StepAI} attribute on $name, instantiated, or null when the method is not an AI step. */
    private function aiAttribute(string $name): ?StepAI
    {
        $attributes = new \ReflectionMethod($this, $name)->getAttributes(StepAI::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * The AI step's exchange under its critic. Round 0 runs (or resumes) the declared exchange; while the
     * critic is unhappy the supervisor guides a re-run that CONTINUES the prior attempt with the guidance
     * as its next turn — until the critic passes, the supervisor accepts/stops, or the cap escalates. With
     * no rubric it is a single exchange. The judge/guard/supervise is the same the imperative path uses
     * (via {@see verdictFor()} and {@see superviseStep()}); only PRODUCING an attempt differs — a declared
     * exchange, not a hand-written body.
     *
     * @return list<Message>
     */
    private function reviewedExchange(string $name, AiStep $step, ?string $rubric, int $maxRounds): array
    {
        $round = 0;
        $priorAttempt = null;
        $workHistory = [];

        while (true) {
            $this->artifacts[$name] = [];
            $this->writtenPaths = [];

            if ($round === 0) {
                $this->runAiExchange($name, $step);
            } else {
                $this->runTurns((string) $this->critique, $step->tools, $step->agent, $workHistory);   // continue with guidance
            }

            $workHistory = $this->lastHistory;   // capture BEFORE the critic, whose own ai() clobbers lastHistory
            $this->recordWrittenFiles($workHistory);
            $this->emitWrittenFileArtifacts();

            if ($rubric === null) {
                break;
            }

            $artifacts = $this->renderArtifacts($this->artifacts[$name]);
            $attempt = $this->attemptFingerprint($name);
            $verdict = $this->verdictFor(
                $name,
                $rubric,
                $artifacts,
                $workHistory === [] && $this->artifacts[$name] === [],
                $priorAttempt !== null && $attempt === $priorAttempt,
            );
            $priorAttempt = $attempt;

            if ($verdict->passes()) {
                break;
            }

            $guidance = $this->superviseStep($name, $artifacts, $verdict, ++$round, $maxRounds);

            if ($guidance === null) {
                break;   // the supervisor accepted the work as-is
            }

            $this->critique = $guidance;
            $this->enforceBudget();
        }

        return $workHistory;
    }

    /**
     * Deliver a {@see StepAI}'s machine-readable outputs. For each declared {@see ParamRequest}, CONTINUE
     * the accepted work conversation with a dedicated request for exactly that value and pin it with
     * {@see setParam()} for the target step — the same mechanism {@see formPendingHandoff()} uses for the
     * prose handoff, addressed to a named step instead of the next one automatically. Runs under
     * {@see $extracting} so its own turns are not checkpointed into the step's exchange row.
     *
     * @param list<Message> $workHistory
     */
    private function extractParams(AiStep $step, array $workHistory): void
    {
        if ($step->params === [] || $workHistory === []) {
            return;
        }

        $this->extracting = true;

        try {
            foreach ($step->params as $request) {
                $value = trim($this->runTurns(
                    "Before this step ends, answer EXACTLY this and nothing else: {$request->instruction}\n\n"
                    . 'Reply with only the value — no prose, no explanation, no quotes.',
                    [],
                    'extract',
                    $workHistory,
                ));
                $this->setParam($request->forStep, $request->name, $value);
            }
        } finally {
            $this->extracting = false;
        }
    }

    /**
     * Run — or continue — the one exchange a {@see StepAI} declares. What the store already holds for this
     * (run, step) decides which:
     *
     *  - PARKED — the recorded exchange ends on an unanswered [question]: continue it from the person's
     *    answer, which becomes the next user turn, so the model picks up where it paused instead of being
     *    asked the same thing again. This is the resume the two-kind model exists to make deterministic.
     *  - otherwise (nothing recorded, or a partial non-parked tail) — open the declared prompt. A partial
     *    tail is carried in as prior context and the in-flight turn is re-run, which is cheap and correct
     *    since a non-parked tail was never the answer.
     *
     * The exchange goes through {@see runTurns()} like any other, so its palette, agent, ask channel,
     * budget and per-turn checkpointing are exactly a work exchange's.
     */
    private function runAiExchange(string $name, AiStep $step): void
    {
        $recorded = $this->env->findStore()->loadExchange($this->runId, $name);
        $pending = $recorded === [] ? null : DefaultTurnLoop::pendingQuestion($recorded);

        if ($pending !== null) {
            $ask = $this->env->find(EnvKey::Ask);

            if (!$ask instanceof SpeakerInterface) {
                throw new WorkflowException("step '{$name}' paused on a person's answer, but no ask channel is configured to resume it");
            }

            $this->runTurns($ask->reply($pending) ?? '', $step->tools, $step->agent, $recorded);

            return;
        }

        $this->runTurns($step->prompt, $step->tools, $step->agent, $recorded);
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
     *  - $evidence — the VERBATIM output of a command the step ran, with $from carrying THE COMMAND
     *    ITSELF (not a tool name: the critic replays $from via rerun_evidence, so `from: 'bash'`
     *    would replay the literal word). Use this whenever the rubric turns on a fact a command can
     *    settle: `$out = $this->tool('bash', ['command' => $cmd]); $this->artifact('tests',
     *    evidence: $out, from: $cmd)`. It is the only channel a step cannot compose, which is
     *    exactly why it exists — a step once recorded "All tests passed." while the suite was
     *    erroring, and the run closed the issue. Pass $text alongside it to add the step's own
     *    reading of that output; it is kept and shown separately, as the step's claim.
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
        ?ToolResultMeta $meta = null,
        string $type = '',
    ): void {
        // Exactly one CONTENT channel — enforce the contract rather than silently preferring one
        // (dropping the other) or recording an empty artifact when none is given. $text is the one
        // exception: alongside $evidence it is not content but the step's own note about it, so it
        // rides along, stored and shown separately.
        $entry = match (true) {
            $evidence !== null && $file === null => Artifact::evidence($label, $evidence, $from, $text ?? '', $meta),
            $file !== null && $text === null => Artifact::file($label, $file, $type),
            $text !== null && $file === null => Artifact::text($label, $text, $lang, $type),
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
            $entry->status,
            $entry->tool,
            $entry->summary,
            $entry->type,
        );
    }

    /**
     * The same three channels, exposed to the MODEL as a tool — present in every workflow, like `recall`.
     *
     * {@see artifact()} is for a step's own PHP, and that is not where most recording belongs: the party
     * that knows what is worth keeping is the model doing the work, and it knows it mid-exchange. A
     * workflow's author has to guess in advance, and when the guess is wrong there is no recourse — the
     * one hand-written library workflow told its model to "call `artifact`" and no such tool existed, so
     * both of its reviewed steps demanded evidence the step had no way to produce.
     *
     * THE EVIDENCE CHANNEL TAKES A COMMAND, NOT ITS OUTPUT, and runs it here. That is the whole point:
     * evidence is the one artifact kind a step cannot compose, and it would stop being that the moment a
     * model could type the text of it. The model decides WHAT to prove; this code produces the proof.
     *
     * What it does not defend against: a model choosing a command whose output says whatever it wants
     * (`echo 'all green'`). Nothing here can — which is why the command itself is recorded as the
     * evidence's source, so a reviewer judges the output KNOWING what produced it, and why every rubric
     * tells the critic to run the thing again.
     *
     * A note on privilege: the run's `bash` is reached through {@see tool()}, which resolves against the
     * run's full registry rather than the narrowed palette of the current `ai()` call. A step that
     * deliberately excluded `bash` can therefore still reach a shell through this. No shipped workflow
     * does, and closing it means teaching tool() about the active palette — worth doing, not here.
     *
     * @throws ToolException on a bad channel combination; the model reads it and corrects itself, which
     *                       a LogicException escaping into the executor would not allow — that is not
     *                       turned into a tool result, so it would take the whole run down, and on a
     *                       generated solver it would then trigger a repair pass for code that is fine
     */
    #[Tool(name: 'artifact', description: 'Record a named output of this step: what you produced, kept in '
        . 'the run journal and shown to the reviewer who judges this step. Pass EXACTLY ONE of: '
        . '`text` (your own words — a decision, a summary, generated source: useful, but it is a claim); '
        . '`file` (the path of a file you wrote — the reviewer opens it itself); '
        . '`run` (the handle of a tool run you ALREADY did this step — shown as `[run#N]` after that '
        . "tool's output — to freeze THAT run's real output as evidence: prefer this over `command` when "
        . 'you have already run the thing, e.g. you ran the tests and they passed — no need to run them '
        . 'again); or `command` (a shell command to RUN NOW, whose verbatim output is recorded as '
        . 'evidence — use when you have NOT run it yet; do not paste output you already have, name the '
        . 'command and it will be run). `note` is optional and meaningful with `command` or `run`: your '
        . 'own reading of the output, kept separate from it and shown as your claim about it.')]
    protected function recordArtifact(
        string $label,
        string $text = '',
        string $file = '',
        string $command = '',
        string $run = '',
        string $note = '',
    ): string {
        if (trim($label) === '') {
            throw new ToolException("artifact: 'label' is required — it is how the reviewer refers to this output");
        }

        $given = array_keys(array_filter(
            ['text' => $text, 'file' => $file, 'command' => $command, 'run' => $run],
            static fn (string $v): bool => trim($v) !== '',
        ));

        if (\count($given) !== 1) {
            throw new ToolException(
                'artifact: pass exactly one of `text`, `file`, `command` or `run`'
                . ($given === [] ? ' — none was given' : ' — got ' . implode(' and ', $given)),
            );
        }

        // A pasted test/lint report is a claim wearing evidence's clothes: it freezes the moment the
        // step chose to copy, carries no exit status, and cannot be re-run. The refusal names the fix,
        // and the violation stops being expressible — see Artifact::looksLikeToolOutput().
        if ($text !== '' && Artifact::looksLikeToolOutput($text)) {
            throw new ToolException(
                'artifact: this text reads as a tool\'s own output — do not paste it. Pass the command '
                . 'that produced it (`command: "…"`); it will be re-run here and recorded verbatim as '
                . 'evidence, with its real exit status.',
            );
        }

        if ($run !== '') {
            $record = $this->runLedger[$this->currentStep][$run] ?? null;

            if ($record === null) {
                $handles = array_keys($this->runLedger[$this->currentStep] ?? []);

                throw new ToolException($handles === []
                    ? "artifact: no run '{$run}' — you have not run a tool this step whose output can be frozen. "
                        . 'Run the command first, then record its `[run#N]`; or use `command` to run and record in one go.'
                    : "artifact: no run '{$run}'. Runs you can freeze this step: '" . implode("', '", $handles) . "'.");
            }

            // The output comes from the ledger — the runtime's verbatim record of a real execution, not text
            // the model typed — so this is evidence exactly as the `command` channel is, without a second run.
            // `from` carries the shell command for a bash run so the reviewer can replay it (rerun_evidence);
            // for any other tool there is nothing bash can replay, so it stays captured-but-not-replayable.
            $call = $record['call'];
            $out = self::clip($record['result']->content);
            $source = $call->name === 'bash' ? (string) ($call->input['command'] ?? '') : '';
            $this->artifact($label, text: $note !== '' ? $note : null, evidence: $out, from: $source, meta: $record['result']->meta);

            return "recorded '{$label}' as evidence — the frozen output of {$run}"
                . ($source !== '' ? " (`{$source}`)." : " ({$call->name}).");
        }

        if ($command !== '') {
            // The command runs HERE, and what it printed is what is kept. `from` is the command itself,
            // not the word 'bash': a reviewer judging an output has to know which one produced it.
            $result = $this->dispatchTool('bash', ['command' => $command]);

            // A command that could not be RUN produces no evidence — most often because this step's
            // palette withholds `bash` on purpose. Recording the failure text as if it were output would
            // manufacture exactly the kind of proof this artifact kind exists to make impossible, so it
            // comes back as a refusal the model can read instead.
            if ($result->isError) {
                throw new ToolException(
                    "artifact: could not run `{$command}` — {$result->content}. Nothing was recorded; if this "
                    . 'step is not allowed to run commands, record what you did with `text` or `file` instead.',
                );
            }

            $out = self::clip($result->content);
            // $result->meta is bash's OWN report of this very execution (exit, program, verdict line) —
            // the artifact stores it; nothing here or downstream parses the output for it.
            $this->artifact($label, text: $note !== '' ? $note : null, evidence: $out, from: $command, meta: $result->meta);

            return "recorded '{$label}' as evidence of `{$command}`. Its output was:\n{$out}";
        }

        $file !== '' ? $this->artifact($label, file: $file) : $this->artifact($label, text: $text);

        return "recorded '{$label}'.";
    }

    /**
     * Where the reviewer's re-runs come from: ONLY commands the reviewed step itself recorded as
     * evidence. The critic used to hold bare bash for this and burned review rounds inventing its
     * own invocations (a phpunit call the project never had, failed on its own typo, three identical
     * rounds); with the label as the sole input, a made-up command is not expressible — the worst
     * possible input is a wrong label, and the refusal lists the right ones.
     *
     * Deliberately dispatched on the RUN's scope, not the review exchange's palette (which withholds
     * `bash` — see {@see judge()}): the model chose only WHICH record to replay; the command text
     * comes from the artifact, so this is not the shell-by-indirection hole the palette closes.
     */
    #[Tool(name: 'rerun_evidence', reviewOnly: true, description: 'Re-run a command the reviewed step '
        . 'recorded as evidence, EXACTLY as recorded, and see its fresh output. Pass `label` — the '
        . 'label of the evidence artifact. This is your only way to execute anything: verification '
        . 'here means replaying recorded evidence, never composing commands of your own.')]
    protected function rerunEvidence(string $label): string
    {
        $runnable = $this->runnableEvidence($this->currentStep);
        $evidence = $runnable[$label] ?? null;

        if ($evidence === null) {
            throw new ToolException($runnable === []
                ? "rerun_evidence: step '{$this->currentStep}' recorded no runnable evidence. If the rubric "
                    . 'makes a claim only a command can settle, your verdict is cannot_verify.'
                : "rerun_evidence: no evidence labeled '{$label}'. Recorded: '"
                    . implode("', '", array_keys($runnable)) . "'.");
        }

        $result = $this->dispatchTool('bash', ['command' => $evidence->source], $this->env);

        if ($result->isError) {
            return "could not re-run `{$evidence->source}`: {$result->content}\nA re-run that failed to start "
                . 'is a tooling problem, never evidence against the work — if no other recorded evidence '
                . 'settles the claim, your verdict is cannot_verify.';
        }

        $exit = $result->meta === null ? '' : " (exit: {$result->meta->status})";

        return "re-ran `{$evidence->source}`{$exit}. Its output was:\n" . self::clip($result->content);
    }

    /**
     * A step's evidence artifacts that can actually be replayed (their source carries the command),
     * keyed by label — the single predicate behind what {@see verificationToolbox()} advertises and
     * what {@see rerunEvidence()} accepts, so the toolbox can never list a label the replay refuses.
     *
     * @return array<string, Artifact>
     */
    private function runnableEvidence(string $step): array
    {
        $runnable = [];

        foreach ($this->artifacts[$step] ?? [] as $artifact) {
            if ($artifact->kind === 'evidence' && $artifact->source !== '') {
                $runnable[$artifact->label] = $artifact;
            }
        }

        return $runnable;
    }

    /**
     * The verdict is a TOOL, not a control word: the reply used to be matched against 'OK', so the
     * difference between "accepted", "cannot check this", and findings lived in prose and was
     * routinely lost. Here the decisions are enumerated and their required citations are parameters —
     * a reject without the rubric item and the observed fact is refused at the call, not discovered
     * in a rework round.
     */
    #[Tool(name: 'verdict', reviewOnly: true, description: 'Record your verdict on the reviewed step — '
        . 'call this exactly once, as your final act of the review. `decision` is one of: accept (the '
        . 'rubric is satisfied), reject (it is not — cite `rubric_item`, the rubric requirement '
        . 'violated, and `fact`, the concrete thing YOU observed that shows it), or cannot_verify (a '
        . 'checkable claim you could not establish with the tools you have — say why in `reason`).')]
    protected function recordVerdict(string $decision, string $rubric_item = '', string $fact = '', string $reason = ''): string
    {
        if ($decision === Verdict::REJECT && (trim($rubric_item) === '' || trim($fact) === '')) {
            throw new ToolException('verdict: a reject must cite both `rubric_item` (which rubric '
                . 'requirement is violated) and `fact` (the concrete thing you observed that shows it)');
        }

        if ($decision === Verdict::CANNOT_VERIFY && trim($reason) === '') {
            throw new ToolException('verdict: cannot_verify must say in `reason` what you could not establish and why');
        }

        $this->verdict = match ($decision) {
            Verdict::ACCEPT => Verdict::accept(),
            Verdict::REJECT => Verdict::reject("Rubric item violated: {$rubric_item}\nObserved: {$fact}"),
            Verdict::CANNOT_VERIFY => Verdict::cannotVerify($reason),
            default => throw new ToolException("verdict: unknown decision '{$decision}' — use accept, reject or cannot_verify"),
        };

        return "verdict recorded: {$decision}.";
    }

    /**
     * Keep a captured output to a size a reviewer's context can hold, from both ends — a test runner puts
     * the summary last and the first failure first, and a middle-out cut keeps both. The marker is left
     * in the text on purpose: a truncated log that does not say so is a log that lies by omission.
     */
    private static function clip(string $output, int $limit = 20_000): string
    {
        if (\strlen($output) <= $limit) {
            return $output;
        }

        $keep = intdiv($limit, 2);
        $dropped = \strlen($output) - $limit;

        return substr($output, 0, $keep)
            . "\n\n[… {$dropped} bytes of output omitted …]\n\n"
            . substr($output, -$keep);
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
        $system = $scope->findSystemPrompt() . $this->handoffContext();   // the tool briefing is the turn loop's now

        // The ask channel (if any) makes the turn loop interactive: the model can pause to ask a
        // person/agent mid-call via the [question] marker, not only through an explicit $this->ask().
        $ask = $scope->find(EnvKey::Ask);

        $loop = $this->makeTurnLoop($scope, $system, $ask instanceof SpeakerInterface ? $ask : null);

        // For as long as this exchange runs, the workflow's OWN reach is the exchange's palette. That is
        // what makes narrowing mean something: a #[Tool] method the model calls mid-exchange — `artifact`
        // with a `command`, say — goes out through tool(), and tool() used to resolve against the run's
        // full registry. So a step that deliberately withheld `bash` still handed the model a shell, one
        // indirection further along. Restored in the finally: a step's own PHP, before and after the
        // exchange, is the author's code and keeps the run's reach.
        $previousScope = $this->activeScope;
        $this->activeScope = $scope;

        try {
            $result = $loop->run([...$prior, Message::userText($prompt)]);
            $this->lastHistory = $result->history;   // kept so a handoff can continue this exact context
            $this->enforceBudget();                  // the loop charged the total — stop the run if that tipped it over

            return $result->text ?? '';
        } finally {
            $this->activeScope = $previousScope;
            $tracer?->exit($span);
        }
    }

    /**
     * Collect the paths a work exchange WROTE — the successful `write_file` targets — for the step to
     * turn into artifacts once its exchange is done. Called from {@see reviewedExchange()} over the step's
     * OWN work history, deliberately not from runTurns (which handoff formation also drives, over the
     * previous step's history, whose write_file calls belong to that step). A write that ERRORED
     * contributes nothing — the call is paired with its result and dropped on failure. Skipped while
     * REVIEWING: a critic does not write, and its exchange is not the step's (the same reason its history
     * is never persisted).
     *
     * @param list<Message> $history
     */
    private function recordWrittenFiles(array $history): void
    {
        if ($this->reviewing) {
            return;
        }

        $failed = [];

        foreach ($history as $message) {
            foreach ($message->content as $block) {
                if ($block instanceof ToolResultBlock && $block->isError) {
                    $failed[$block->toolUseId] = true;
                }
            }
        }

        foreach ($history as $message) {
            foreach ($message->content as $block) {
                if (!$block instanceof ToolUseBlock || $block->name !== 'write_file' || isset($failed[$block->id])) {
                    continue;
                }

                $path = \is_string($block->input['path'] ?? null) ? trim($block->input['path']) : '';

                if ($path !== '') {
                    $this->writtenPaths[$path] = true;
                }
            }
        }
    }

    /**
     * Turn the step's written files into `file` artifacts, so the run's real deliverable becomes a
     * visible result — before this, a step could write src/Foo.php and record nothing, leaving the
     * panel with no answer to "where is the result" and the critic nothing to open. Run once at the
     * end of the step body, AFTER its own artifact() calls, and deduped by path against them: a path
     * the step already recorded by hand, or one written more than once, appears exactly once.
     */
    private function emitWrittenFileArtifacts(): void
    {
        $already = [];

        foreach ($this->artifacts[$this->currentStep] ?? [] as $artifact) {
            if ($artifact->kind === 'file') {
                $already[$artifact->value] = true;
            }
        }

        foreach (array_keys($this->writtenPaths) as $path) {
            if (!isset($already[$path])) {
                $this->artifact($path, file: $path);
            }
        }
    }

    /**
     * The child scope one model exchange runs in: the run's registry plus this workflow's own
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

        // When the palette holds occasional (deferred) tools, add search_tools so their schemas can be
        // withheld from the context until the model asks — it carries the palette to answer that ask.
        if ($this->hasDeferred($palette)) {
            $palette = $palette->with(new ToolSearchTool($palette));
        }

        $scope = $this->env->child()->set(EnvKey::Registry, $palette);

        $model = $agent !== null ? $this->agentModel($agent) : null;

        if ($model !== null) {
            $scope->set(EnvKey::ModelId, $model);   // route this call to the role's model
        }

        return $scope;
    }

    /** Whether a palette holds any occasional (deferred) tool — the trigger for adding search_tools. */
    private function hasDeferred(Registry $palette): bool
    {
        foreach ($palette->all() as $tool) {
            if ($tool instanceof DeferredToolInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the turn loop for one exchange from the call's scope. Kept a method (not a newed-up local)
     * so the wiring lives in one place and the loop is an overridable {@see TurnLoopInterface} seam —
     * the budget caps this one exchange and its spend bubbles up to the run total.
     */
    private function makeTurnLoop(Environment $scope, string $system, ?SpeakerInterface $ask): TurnLoopInterface
    {
        // The work exchange's runs are ledgered so the model can later freeze one into an artifact by its
        // handle (see {@see $runLedger}). A critic's or an extraction's exchange is not the step's work —
        // it never records artifacts — so it runs on the raw executor, unledgered.
        $executor = ($this->reviewing || $this->extracting)
            ? $scope->executor()
            : $this->recordingExecutor($scope->executor());

        return new DefaultTurnLoop(
            $scope->findWorker(),
            $executor,
            $scope->findModelId(),
            $system,
            $scope->findRegistry(),
            $scope->findMaxHistory(),
            $this->tracer(),
            $ask,
            $this->turnBudget(),
            null,
            // Write the exchange down as it happens, so an interruption inside a step costs the turn it
            // died on and not the whole step. The run id and the step are captured here because the loop
            // has no idea what a step is; it only knows a turn has landed.
            /** @param list<Message> $history */
            function (array $history): void {
                if ($this->reviewing || $this->extracting) {
                    return;   // a critic's or an extraction's conversation is not the step's work
                }

                $this->env->findStore()->saveExchange($this->runId, $this->currentStep, $history);
            },
        );
    }

    /**
     * Wrap the exchange's executor so every tool run the model makes is ledgered and comes back tagged
     * with a `run#N` handle the model can later hand to `artifact(run:)`. The wrapping is one anonymous
     * {@see ExecutorInterface} — a thin passthrough that reports each result to {@see recordRun()} — so
     * the tool-running middleware chain underneath is untouched.
     */
    private function recordingExecutor(ExecutorInterface $inner): ExecutorInterface
    {
        return new class ($inner, $this->recordRun(...)) implements ExecutorInterface {
            /** @param \Closure(ToolCall, ToolResultBlock): ToolResultBlock $onResult */
            public function __construct(
                private readonly ExecutorInterface $inner,
                private readonly \Closure $onResult,
            ) {
            }

            public function call(ToolCall $call): ToolResultBlock
            {
                return ($this->onResult)($call, $this->inner->call($call));
            }
        };
    }

    /**
     * Ledger one run of the current step's work exchange and hand the model its handle. A handle is the
     * ONLY way to later freeze this run into evidence, so it is appended to the output the model reads —
     * `[run#3]` — and the run (its call and verbatim result) is kept for {@see recordArtifact()}'s `run`
     * channel. Two kinds of run get NO handle, because promoting them is meaningless: a run that ERRORED
     * (there is no real output to freeze), and the meta-tools whose output is not the step's work —
     * `artifact` itself (freezing a recording of a recording) and `search_tools` (a palette lookup).
     */
    private function recordRun(ToolCall $call, ToolResultBlock $result): ToolResultBlock
    {
        if ($result->isError || \in_array($call->name, ['artifact', 'search_tools'], true)) {
            return $result;
        }

        $handle = 'run#' . (\count($this->runLedger[$this->currentStep] ?? []) + 1);
        $this->runLedger[$this->currentStep][$handle] = ['call' => $call, 'result' => $result];

        return new ToolResultBlock($result->toolUseId, $result->content . "\n[{$handle}]", $result->isError, $result->meta);
    }

    /**
     * Form the previous step's handoff — once — when a downstream step's exchange reads it. The handoff
     * is NOT a grab of the return value: the model is EXPLICITLY asked to write it by CONTINUING the
     * step's own work conversation, so it still holds what it actually did (its tool calls, what it
     * read/changed), not a cold re-summary. The result is SAVED to the store keyed by the step that
     * formed it the instant it exists — so a resume in a fresh process, where that conversation is
     * gone, reads it back at construction ({@see loadHandoff()}) instead of re-forming it. Cleared
     * before the inner call so it never re-enters; a step that ran no model exchange hands on ''.
     *
     * The formation exchange is traced under its OWN role, 'handoff' — not 'worker'. It runs at the
     * FIRST ai() of the NEXT step, so before this its span sat inside that step and read as the next
     * step doing work it had not started ('assess' looked like it did nothing, its "work" being the
     * PREVIOUS step's handoff). The role names the exchange for what it is.
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
            'handoff',              // its own trace role, so the exchange is not read as the next step's work
            $pending['history'],    // continue the work conversation — the model still has the full context
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
            if ($tool->reviewOnly() && !$this->reviewing) {
                continue;   // a review-only tool does not exist outside a critic's exchange
            }

            $combined->add($tool);
        }

        return $combined;
    }

    /**
     * This workflow's {@see Tool}-marked methods, each wrapped as a {@see MethodTool}. Discovered once
     * by reflection and cached; empty for a workflow that declares no local tools.
     *
     * @return list<MethodTool>
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
     * A fresh per-turn budget for one model exchange — a child of the run total carrying the
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
     * Stop the run when its TOTAL budget is spent — a hard but RESUMABLE stop: the snapshot survives, so
     * the run picks up where it left off once it is given more budget. It never asks the ask channel.
     * Reacting to "there are no tokens left" by spending tokens to ask a model whether to continue is a
     * contradiction, and on a resumed run that question could consume a person's answer meant for the
     * worker. A spent budget is the operator's to settle out of band — raise the limit and resume — not an
     * in-run prompt.
     *
     * @throws WorkflowException
     */
    private function enforceBudget(): void
    {
        $budget = $this->budget();

        if ($budget === null || !$budget->isExhausted()) {
            return;
        }

        throw WorkflowException::budgetSpent('run stopped: ' . $budget->reason());
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
    private function criticRubric(?string $critic, string $name): ?string
    {
        if ($critic === null || $critic === '') {
            return null;
        }

        $rules = $this->criticRules()[$critic] ?? null;

        if ($rules === null || trim($rules) === '') {
            throw new \LogicException("Step '{$name}' names critic '{$critic}', but criticRules() has no rules for it.");
        }

        return $rules;
    }

    /** The soft critic-round cap a step declared (`#[Step(maxRounds: N)]` / `#[StepAI(...)]`), else the default. */
    private function maxRounds(?int $max): int
    {
        return $max !== null && $max > 0 ? $max : self::DEFAULT_MAX_ROUNDS;
    }

    /**
     * A step attempt's fingerprint — its artifacts as [label, kind, value] triples — for the
     * byte-identical-rerun guard. One definition so both step kinds decide "the same" the same way.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function attemptFingerprint(string $name): array
    {
        return array_map(static fn (Artifact $a): array => [$a->label, $a->kind, $a->value], $this->artifacts[$name]);
    }

    /**
     * The verdict on one attempt: the two deterministic guards first — a step that produced nothing, or a
     * re-run byte-identical to the last, neither worth an AI critic's round — else the AI critic. Shared by
     * both step kinds so the guards and their wording cannot drift between them.
     */
    private function verdictFor(string $name, string $rubric, string $artifacts, bool $producedNothing, bool $identical): Verdict
    {
        if ($producedNothing) {
            return Verdict::reject("step '{$name}' produced nothing: no model/tool work and no artifact. A step "
                . 'must do real work and leave a result; if it needs no review, it should carry no critic.');
        }

        if ($identical) {
            return Verdict::reject('the re-run produced BYTE-IDENTICAL output to the previous attempt — the '
                . 'guidance changed nothing about the work. Another round cannot help: either the '
                . 'finding is wrong, or this step needs different guidance or a person.');
        }

        return $this->critic($name, $rubric, $artifacts);
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
     * Judge a step's work against its rubric on the reviewer role. The critic is an ai on a REVIEW
     * palette: it reads whatever it needs, but it executes only through `rerun_evidence` (replaying
     * commands the step recorded) and it answers only through the `verdict` tool. Its standing role,
     * prepended here, is to REVIEW only: inspect and report, never do or fix the work itself. It
     * judges the step's reviewable output — its rendered artifacts (see {@see renderArtifacts()}).
     */
    private function critic(string $name, string $rubric, string $artifacts): Verdict
    {
        $this->reviewing = true;

        try {
            return $this->judge($name, $rubric, $artifacts);
        } finally {
            $this->reviewing = false;
        }
    }

    /** The review itself — {@see critic()} only marks the exchange as a reviewer's and unmarks it after. */
    /**
     * What the critic is TOLD about verifying this step's work, instead of being left to guess:
     * the exact commands the step recorded as evidence (with the runtime's grading), the task
     * text (it may name how the result is meant to be checked), and whatever the project's
     * knowledge base says about verifying work here. The observed failure this closes: a
     * reviewer re-inventing the test invocation, failing on its own typo, and burning rework
     * rounds on work that was already green.
     */
    private function verificationToolbox(string $name): string
    {
        $sections = [];
        $commands = [];

        foreach ($this->runnableEvidence($name) as $artifact) {
            $graded = $artifact->status === ''
                ? ''
                : " → {$artifact->status}" . ($artifact->summary === '' ? '' : ": {$artifact->summary}");
            $commands[] = "- '{$artifact->label}': `{$artifact->source}`{$graded}";
        }

        if ($commands !== []) {
            $sections[] = "Commands the step already ran as evidence — replay one with rerun_evidence(label):\n"
                . implode("\n", $commands);
        }

        $issue = $this->issue();

        if ($issue !== null) {
            $brief = trim($issue->title . "\n" . $issue->description);

            if ($brief !== '') {
                $sections[] = "The task, which may name how the result is meant to be verified:\n"
                    . self::clip($brief, 600);
            }
        }

        // Deliberately NO knowledge-base lookup here: a per-judge search was tried and it cost an
        // embedding call and a traced tool event per critic round against bases that are empty
        // today. If the base ever carries a verification page, inject it ONCE per run from the
        // runner — never from inside every review.
        return $sections === [] ? '' : "Your verification toolbox:\n\n" . implode("\n\n", $sections) . "\n\n";
    }

    private function judge(string $name, string $rubric, string $artifacts): Verdict
    {
        // The review palette, by subtraction so a tool added to the run later reaches the critic
        // without a revisit here. What goes: every channel that composes a check or ACTS — `bash`
        // and `php_eval` (a critic replays recorded evidence, it does not invent commands),
        // `write_file` (a reviewer reports, it never fixes), `artifact` (recording would land on
        // the step under review and pollute the very record being judged), and `define_workflow` /
        // `project_manager` / `schedule` (on the authoring path the reviewer would otherwise hold
        // the tool that overwrites the very solver it is judging). What arrives instead: the
        // review-only `rerun_evidence` and `verdict`, present because {@see $reviewing} is set.
        $registry = $this->withLocalTools($this->env->findRegistry());
        $names = array_map(static fn (ToolInterface $t): string => $t->name(), $registry->all());
        $toolNames = array_values(array_diff(
            $names,
            ['bash', 'php_eval', 'write_file', 'artifact', 'define_workflow', 'project_manager', 'schedule'],
        ));

        $this->verdict = null;

        $handoff = $this->incomingHandoff === ''
            ? ''
            : "The handoff this step was given by the previous step (what it was set up to do):\n"
                . self::clip($this->incomingHandoff, 800) . "\n\n";

        $reply = trim($this->runTurns(
            $this->criticRole() . "\n\n"
            . "You are checking the work of step '{$name}'.\n\n"
            . "Rubric (judge ONLY against this):\n{$rubric}\n\n"
            . $handoff
            . "Artifacts it recorded:\n{$artifacts}\n\n"
            . $this->renderParams($this->stepParams[$name] ?? [])
            . $this->verificationToolbox($name)
            . 'Your materials are the step\'s artifacts, the handoff it was given, and the project itself, '
            . 'which you can read. Fix your attention on what the step was meant to do (the rubric and the '
            . "handoff) and on its ARTIFACTS.\n\n"
            . "An artifact is one of two things, and that decides how far to trust it:\n"
            . '  - THE STEP\'S OWN ANSWER — what it wrote itself. TEXT (a summary, a decision, generated '
            . 'source) is printed IN FULL above: read it right here, no tool fetches it, and if the rubric '
            . 'is about that text (e.g. reviewing generated source) this inline text IS your subject — do '
            . 'not go looking for it as a file or a note. A FILE is a path it wrote: open it with read_file.'
            . "\n"
            . '  - THE RESULT OF A TOOL RUN — a command\'s real output, recorded verbatim by the runtime '
            . 'with the runtime\'s own grade (ok/fail). This is not the step\'s word, it is what actually '
            . "happened: read the outcome there.\n\n"
            . 'If you think the result is poor, or does not solve the step\'s task, you can replay a '
            . 'recorded run with rerun_evidence(label) and judge the fresh output. You do not compose your '
            . "own commands — only what the step recorded can be replayed. recall(what='step', "
            . "name='{$name}') is also available ONCE if you need to see what the step did.\n\n"
            . 'Trust what you SEE, not a retelling: a checkable claim the step made only in its own TEXT — '
            . '"the tests pass", "the file now contains X" — with no tool-run artifact behind it is not '
            . 'established. Replay the evidence that settles it, or, if nothing recorded and no tool of '
            . "yours can, return cannot_verify — never reject merely because YOU could not check.\n\n"
            . 'And evidence proves only as much as the command behind it. A green result from a WEAK or '
            . 'SELF-AUTHORED check — a test the step wrote so it would pass, an assertion deleted, a '
            . 'command narrowed to the easy case — is not the step\'s job done. Look at the command that '
            . 'produced an evidence artifact (its source), not only its output: does THAT run actually '
            . 'establish what the rubric asks? If the oracle is weak, or the step shaped it to succeed, '
            . "that is a reject.\n\n"
            . 'The rubric decides what counts and it OUTRANKS all of this. If it says the artifact is code '
            . 'or a plan that has NOT run yet, the project as it stands is not evidence about it — judge it '
            . 'on its own terms; do not hold the current files, or a red test suite, against work that was '
            . "never meant to have happened yet.\n\n"
            . 'When you have judged, CALL the `verdict` tool: accept if the rubric is satisfied; reject, '
            . 'citing the rubric item violated and the concrete fact you observed; or cannot_verify with '
            . 'the reason. The tool call is the verdict — prose alone is not one.',
            $toolNames,
            'reviewer',
            [],   // a critic exchange is always fresh — it never continues a prior conversation
        ));

        $verdict = $this->takeVerdict();

        if ($verdict !== null) {
            return $verdict;
        }

        // The reviewer answered in prose instead of calling `verdict` — read the reply the way the
        // old contract did, so a wayward model still lands the round somewhere sane.
        return strtoupper($reply) === 'OK'
            ? Verdict::accept()
            : Verdict::reject($reply === '' ? 'the critic recorded no verdict and returned no findings' : $reply);
    }

    /**
     * The verdict the review exchange recorded, taken and cleared — null when the `verdict` tool was
     * never called. A method rather than an inline read: the field is written by {@see recordVerdict}
     * through the tool executor mid-exchange, which no static view of {@see judge()} can see.
     */
    private function takeVerdict(): ?Verdict
    {
        $verdict = $this->verdict;
        $this->verdict = null;

        return $verdict;
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
        // No artifact does NOT mean "nothing to review": the critic still reads the project, and a
        // genuinely empty step is for it to judge against the rubric, not for the engine to pre-fail.
        return $artifacts === []
            ? '(this step recorded no artifact — inspect its effect yourself: read the files it touched; '
                . 'a claim only a command could settle is cannot_verify, since nothing was recorded to re-run)'
            : implode("\n", array_map(static fn (Artifact $a): string => $a->render(), $artifacts));
    }

    /**
     * The standing role prepended to every critic call — what the reviewer IS and may do. The default
     * casts it as a verify-only reviewer (inspect and report, never do or fix the work). A workflow
     * overrides this when its review needs a different stance; the engine still appends the rubric,
     * the step's result, its artifacts, and the verdict protocol.
     */
    protected function criticRole(): string
    {
        return 'You are the critic of a workflow step. A workflow moves forward one step at a time, and each '
            . "step's work is done by an agent that then declares it is finished. You are the independent "
            . 'check before the workflow believes that and moves on: a fresh, separate look whose one '
            . 'purpose is to decide whether the step ACTUALLY did its job — solved what it was for, to a '
            . 'real standard of quality — or only claims to have. Nothing else in the run re-examines this '
            . 'step; if a weak or unfinished result passes you, it passes for good. You judge and report — '
            . 'you never do, edit, or fix the work yourself: stepping in would destroy the independence that '
            . 'is the whole reason your judgement is worth anything.';
    }

    /**
     * The critic did not pass the step — consult the supervisor (the ask channel; behind it a
     * supervisor agent, then a human). Returns guidance for a re-run, or null to accept the work as-is.
     *
     * Below the step's round cap ($maxRounds, default {@see DEFAULT_MAX_ROUNDS}) this self-corrects on
     * the critic's findings when no one is on the channel (the normal autonomous case). At/after the cap
     * it ESCALATES: the round count looks stuck,
     * so it asks the supervisor whether to accept, retry, or stop — and if there is no one to ask, it
     * stops the step rather than churn the same rework forever.
     *
     * A cannot-verify verdict is put to the supervisor as what it is — a verification failure, not a
     * fault in the work — so the ladder settles it (accept / stop / say how to produce evidence)
     * instead of the findings driving a rework of work nobody faulted. It still counts as a round:
     * a critic that cannot verify attempt after attempt is churn like any other.
     *
     * @throws WorkflowException when the supervisor says to stop, or the cap is hit with no one to ask
     */
    private function superviseStep(string $name, string $work, Verdict $verdict, int $round, int $maxRounds): ?string
    {
        $stuck = $round >= $maxRounds;
        $findings = $verdict->findings;
        $channel = $this->env->find(EnvKey::Ask);

        if (!$channel instanceof SpeakerInterface) {
            if ($stuck) {
                // Name the actual failure: a step that could not be CHECKED did not "fail review".
                throw WorkflowException::stopped($verdict->decision === Verdict::CANNOT_VERIFY
                    ? "step '{$name}' could not be verified after {$round} rounds, with no supervisor to escalate to"
                    : "step '{$name}' still failed review after {$round} rounds, with no supervisor to escalate to");
            }

            // Self-correct: for a reject, on the findings; for a cannot-verify, the reason tells the
            // step what evidence it failed to record — re-running to record it is the productive fix.
            return $findings;
        }

        // $work is the step's result (its artifacts) — the same context the critic had, and on the run
        // path it is ALL the supervisor gets: that tier is built tool-less (see IssueRunner::
        // supervisorSpeaker()), so it judges from this text and cannot go and look. Behind it the human
        // tier can. Do not write here that it can recall() for more; it could not, and the claim stood
        // in this comment for as long as the behaviour contradicted it.
        //
        // The two control words are quoted so the reply can be matched EXACTLY. Anything else is
        // guidance, which is the safe default: a misread must send the step back for another attempt,
        // never close it or kill the run.
        $prompt = match (true) {
            $verdict->decision === Verdict::CANNOT_VERIFY => "Step '{$name}' went through review, but the critic COULD NOT VERIFY it "
                . "— the work is not judged wrong; checking it failed.\n"
                . ($stuck ? "This is round {$round} of {$maxRounds}: verification keeps failing.\n" : '')
                . "What could not be established:\n{$findings}\n\n"
                . "The step's result (artifacts):\n{$work}\n\n"
                . 'Settle it: reply with exactly `accept` to take the work as-is, exactly `stop` to abort, '
                . 'or guidance for one more attempt (for instance, how the step should record runnable '
                . 'evidence). Anything else is read as guidance.',
            $stuck => "Step '{$name}' has failed review {$round} times and the critic is still not satisfied.\n"
                . "Latest findings:\n{$findings}\n\nThe step's result (artifacts):\n{$work}\n\n"
                . 'Is this OK? Reply with exactly `accept` to keep it as is, exactly `stop` to abort, '
                . 'or guidance for one more try. Anything else is read as guidance.',
            default => "Step '{$name}' did not pass review.\nFindings:\n{$findings}\n\n"
                . "The step's result (artifacts):\n{$work}\n\n"
                . 'Reply with guidance to fix it, or exactly `accept` to keep it as is, or exactly '
                . '`stop` to abort. Anything else is read as guidance.',
        };

        $reply = $channel->reply($prompt);
        $answer = trim($reply ?? '');

        // No answer at all — nobody on the channel, or a person who pressed Enter. It is NOT acceptance:
        // silence used to close out work the critic had just rejected, which made the emptiest possible
        // reply the strongest verdict in the system. It is also not a stop; below the cap the step
        // self-corrects on the findings, and only a stuck step with no one to ask gives up.
        if ($answer === '') {
            if ($stuck) {
                throw WorkflowException::stopped("step '{$name}' still failed review after {$round} rounds");
            }

            return $findings;
        }

        // Exact words, not prefixes. `str_starts_with($lower, 'stop')` turned the guidance "Stop
        // rerunning the whole suite, run only the failing test" into a killed run, and anything opening
        // with "acceptable" into acceptance — while this same prompt asks for prose guidance.
        $word = strtolower(trim($answer, " \t`*_.!—–-"));

        if ($word === 'accept') {
            return null;   // accept the work as-is
        }

        if ($word === 'stop') {
            throw WorkflowException::stopped("run stopped at step '{$name}' by the supervisor");
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
        $result = $this->dispatchTool($name, $params);

        if ($result->isError) {
            return "tool '{$name}' failed: " . $result->content;
        }

        return $result->content;
    }

    /**
     * The traced dispatch behind {@see tool()}, returning the whole envelope — for the callers that
     * need more than the text, like the evidence recorder reading the tool's own result report.
     * $scope overrides the resolution scope for the one caller entitled to it ({@see rerunEvidence},
     * which replays a RECORDED command and must reach `bash` past the review palette).
     *
     * @param array<string, mixed> $params
     */
    private function dispatchTool(string $name, array $params, ?Environment $scope = null): ToolResultBlock
    {
        $tracer = $this->tracer();
        $tracer?->toolCall($name, $params);

        // The ACTIVE scope, not the run's: inside an ai() exchange this is that call's narrowed palette,
        // so a tool the step withheld cannot be resolved and comes back as an honest refusal.
        $scope ??= $this->activeScope ?? $this->env;
        $result = $scope->executor()->call(new ToolCall($this->env->findStore()->nextId(), $name, $params));

        $tracer?->toolResult($name, $result->content, $result->isError);

        return $result;
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
     * The workflow's step methods (those marked {@see Step} or {@see StepAI}), in declaration order —
     * what the default run() drives.
     *
     * @return list<string>
     */
    private function stepMethods(): array
    {
        $names = [];

        foreach (new \ReflectionClass($this)->getMethods() as $method) {
            if ($method->getAttributes(Step::class) !== [] || $method->getAttributes(StepAI::class) !== []) {
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
