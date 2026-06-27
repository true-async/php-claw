<?php

declare(strict_types=1);

namespace Claw\Run;

use Claw\Agent\AgentInterface;
use Claw\Agent\AgentSpeaker;
use Claw\Agent\Budget;
use Claw\Agent\DefaultTurnLoop;
use Claw\Agent\EscalatingSpeaker;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Claw\Config;
use Claw\Exceptions\ClawException;
use Claw\Exceptions\WorkflowFinished;
use Claw\Project\Issue;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
use Claw\Project\RunStatus;
use Claw\Tool\RecallTool;
use Claw\Tool\ToolFactory;
use Claw\Tool\Workspace;
use Claw\Trace\Tracer;
use Claw\Trace\TraceReader;
use Claw\Workflow\BudgetPolicy;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;
use Claw\Workflow\GenerateIssueWorkflow;
use Claw\Workflow\SqliteStateStore;
use Claw\Workflow\SuperviseWorkflow;
use Claw\Workflow\WorkflowAbstract;
use Claw\Workflow\WorkflowStore;

/**
 * Runs one issue's solver workflow to completion, headless. The shared run engine behind both
 * `claw run` (console) and the dashboard server's POST /start (a coroutine on the event loop): it
 * wires the run environment, generates or reuses the solver, runs it, and on a runtime crash asks
 * the supervisor to repair it and resumes the same run from its durable snapshot.
 *
 * Everything that differs between the console and the server is behind one {@see RunFrontendInterface}
 * (the human tier, the solver-approval decision, progress reporting, the live trace sink), so the
 * pipeline itself holds no I/O opinion.
 */
final readonly class IssueRunner
{
    /** How many times the supervisor may repair-and-resume a crashing solver before giving up. */
    private const int MAX_REPAIRS = 2;

    /** The supervisor agent's standing role — it settles in-run escalations or defers to the human. */
    private const string SUPERVISOR_SYSTEM = <<<'PROMPT'
        You are the SUPERVISOR of an autonomous coding workflow. You are consulted when a step is stuck:
        a worker pauses with a question, or a step's work failed review and the run asks whether to keep
        going. Your job is to UNBLOCK with the smallest sound decision, so the run does not churn.

        How to answer (reply with ONLY the decision, no preamble):
        - To resolve a "did not pass review / is this OK?" escalation, reply with exactly one of:
          `accept` — the work is good enough as is, stop reworking;
          `stop`   — the goal cannot be reached here (e.g. a required tool is missing, the gate is
                     unsatisfiable in this environment) or it is looping with no progress — abort the step;
          or a short, concrete GUIDANCE for ONE more attempt (only if a specific fix is likely to work).
        - To answer a worker's question, give the briefest concrete answer that lets it proceed.

        Bias to ending churn: if a step has failed several times for the same reason, or the blocker is
        environmental (a missing test runner, an absent dependency) and cannot change, choose `accept`
        (if the actual work looks correct) or `stop` — do NOT keep saying "try again".

        Reply exactly `ESCALATE` only when the decision genuinely needs a human (a scope or product call
        you must not make alone); it will then be passed up to the person.
        PROMPT;

    public function __construct(
        private string $projectsDir,
        private ProjectStore $store,
        private Config $config,
        private AgentInterface $agent,
        private RunFrontendInterface $frontend,
    ) {
    }

    /**
     * Generate/reuse the solver for the issue and run it. Returns a process-style exit code: 0 on a
     * finished run (or a solver saved-but-not-run), 1 on a failed generation/run. The issue moves to
     * InProgress at the start and Done when every step has run.
     */
    public function run(Issue $issue): int
    {
        $project = $this->store->project();

        // The palette acts on the REAL project folder: this run works on the user's repo.
        $workspace = new Workspace($project->path);
        $workflowStore = new WorkflowStore($this->projectsDir . '/' . $project->id . '-workflows', $project->id);
        $projectDb = $this->store->pdo();   // the one open connection: shared by the state store + trace
        $registry = ToolFactory::forRun($project, $workspace, $workflowStore);

        // The store is durable (a killed run resumes from its snapshot); budgets cap the run total and
        // each exchange (0 = unlimited); named agent roles share the access and override only the model.
        $env = new Environment()
            ->set(EnvKey::Worker, $this->agent)
            ->set(EnvKey::Registry, $registry)
            ->set(EnvKey::ModelId, $this->config->model)
            ->set(EnvKey::SystemPrompt, Config::DEFAULT_SYSTEM)
            ->set(EnvKey::MaxHistory, $this->config->maxHistory)
            ->set(EnvKey::Store, new SqliteStateStore($projectDb))
            ->set(EnvKey::Agents, $this->config->agents)
            ->set(EnvKey::Budget, new Budget($this->config->budgetTokens, (float) $this->config->budgetSeconds))
            ->set(EnvKey::TurnTokenLimit, $this->config->turnTokens)
            ->set(EnvKey::TurnTimeLimit, (float) $this->config->turnSeconds)
            ->set(EnvKey::BudgetPolicy, BudgetPolicy::from($this->config->budgetPolicy));

        $solverName = 'Issue' . (string) preg_replace('/[^A-Za-z0-9]/', '', $issue->id) . 'Solver';
        $solverClass = $workflowStore->classFor($solverName, true);

        // Resume an interrupted run (status still 'running') for this issue's solver, else start a new
        // one. The run id ties the ledger row, the trace, and the durable state snapshot together — so
        // resuming reuses it: the solver restores its saved state and re-runs only the unfinished tail.
        $runId = $this->store->resumableRun($issue->id, $solverName);
        $resuming = $runId !== null;
        if ($runId === null) {
            $runId = $this->store->recordRun($issue->id, $solverName);
        }
        $this->store->setIssueStatus($issue->id, IssueStatus::InProgress);

        $tracer = new Tracer($runId, ...$this->frontend->traceSinks($projectDb));
        $env->set(EnvKey::Tracer, $tracer);

        // The ask channel is a ladder: a SUPERVISOR AGENT first (it can unblock a stuck step or settle a
        // critic escalation on its own judgement), then the human tier behind it. The human tier is built
        // here, now the tracer exists, because the HTTP gate records its question/answer through it.
        $env->set(EnvKey::Ask, new EscalatingSpeaker(
            $this->supervisorSpeaker($env),
            $this->frontend->human($tracer),
        ));

        $taskBrief = "Title: {$issue->title}\n\nDescription: {$issue->description}";
        $registry->add(new RecallTool(new TraceReader($projectDb), $runId, $taskBrief));
        if ($resuming) {
            $this->frontend->report("Resuming run #{$runId} for issue #{$issue->id}…", false);
        }

        $ctx = new RunContext(
            $env,
            $tracer,
            $this->store,
            $workflowStore,
            $runId,
            $issue,
            $project,
            $solverName,
            $solverClass,
        );

        $early = $this->ensureSolver($ctx);
        if ($early !== null) {
            return $early;   // generation failed, or the solver was saved without running it
        }

        return $this->runSolver($ctx);
    }

    /**
     * Make sure a solver workflow exists for the run: reuse the one on disk, or generate one and have
     * the front-end decide whether to run it. Returns null to proceed to running, or an exit code to stop
     * here — a failed generation (1), or the solver saved without being run yet (0).
     */
    private function ensureSolver(RunContext $ctx): ?int
    {
        $solverPath = $ctx->workflowStore->path($ctx->solverName, true);
        if (is_file($solverPath)) {
            $this->frontend->report("Reusing solver {$ctx->solverClass}.", false);

            return null;
        }

        $this->frontend->report("Generating a solver workflow for issue #{$ctx->issue->id}…", false);

        $gen = $ctx->tracer->enterWorkflow('generate-issue-workflow');
        try {
            new GenerateIssueWorkflow($ctx->env, $ctx->runId . '-gen', [
                'solverName' => $ctx->solverName,
                'solverNamespace' => $ctx->workflowStore->namespaceFor(true),
                'solverTools' => ['read_file', 'write_file', 'list_files', 'bash'],
            ], $ctx->issue, $ctx->project)->run();
        } catch (\Cancellation $cancellation) {
            throw $cancellation;   // a cancelled run must stop, not be reported as a generation failure
        } catch (\Throwable $e) {
            $ctx->tracer->exit($gen);
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
            $this->frontend->report('generation failed: ' . $e->getMessage(), true);

            return 1;
        }
        $ctx->tracer->exit($gen);

        if (!is_file($solverPath)) {
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
            $this->frontend->report('no solver workflow was produced', true);

            return 1;
        }

        if (!$this->frontend->approveSolver($solverPath, (string) file_get_contents($solverPath))) {
            $ctx->store->setRunStatus($ctx->runId, RunStatus::Generated);
            $this->frontend->report("Saved. Not run — review it, then run issue #{$ctx->issue->id} again.", false);

            return 0;
        }

        return null;
    }

    /**
     * Run the solver to completion; on a runtime crash, ask the supervisor to repair it (a new class
     * version) and resume the same runId — its snapshot skips the finished steps. Bounded by MAX_REPAIRS.
     */
    private function runSolver(RunContext $ctx): int
    {
        $solverSpan = $ctx->tracer->enterWorkflow($ctx->solverName);
        $currentClass = $ctx->solverClass;
        $attempt = 0;
        while (true) {
            try {
                $solver = new $currentClass($ctx->env, $ctx->runId, [], $ctx->issue, $ctx->project);
                if (!$solver instanceof WorkflowAbstract) {
                    throw new ClawException("{$currentClass} is not a workflow");
                }
                $solver->run();
                break;
            } catch (WorkflowFinished) {
                break;   // the solver called `done`: a clean finish, not a crash to repair
            } catch (\Cancellation $cancellation) {
                throw $cancellation;   // a cancelled run must stop — never "repair" a cancellation
            } catch (\Throwable $e) {
                // a generated solver throws Error (TypeError, ParseError, ...) as readily as Exception,
                // so catch the lot here and repair-and-resume — that is exactly this boundary's job
                if (++$attempt > self::MAX_REPAIRS) {
                    $ctx->tracer->exit($solverSpan);
                    $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
                    $message = "run #{$ctx->runId} failed after {$attempt} repair attempt(s): {$e->getMessage()}";
                    $this->frontend->report($message, true);

                    return 1;
                }

                $this->frontend->report("Run hit an error; repairing (attempt {$attempt})…", false);
                $fixed = $this->repairSolver($ctx, $currentClass, $e->getMessage(), $attempt);
                if ($fixed === null) {
                    $ctx->tracer->exit($solverSpan);
                    $ctx->store->setRunStatus($ctx->runId, RunStatus::Failed);
                    $this->frontend->report("the supervisor could not repair run #{$ctx->runId}", true);

                    return 1;
                }
                $currentClass = $fixed;   // resume with the fixed class on the next loop turn
            }
        }
        $ctx->tracer->exit($solverSpan);

        $ctx->store->setRunStatus($ctx->runId, RunStatus::Done);
        $ctx->store->setIssueStatus($ctx->issue->id, IssueStatus::Done);   // every step ran -> issue resolved
        $this->frontend->report("Run #{$ctx->runId} finished for issue #{$ctx->issue->id}.", false);

        return 0;
    }

    /**
     * The supervisor tier of the ask channel: an agent on the `supervisor` model that settles in-run
     * escalations (accept / stop / guidance) on its own judgement, so a stuck step does not wait on —
     * or churn against — the human. It runs tool-less (it judges from the escalation text). Replying
     * `ESCALATE` returns null, so {@see EscalatingSpeaker} passes the decision up to the human tier.
     */
    private function supervisorSpeaker(Environment $env): SpeakerInterface
    {
        $configured = $this->config->agents['supervisor'] ?? null;
        $model = \is_string($configured) && $configured !== '' ? $configured : $this->config->model;

        $loop = new DefaultTurnLoop($this->agent, $env->executor(), $model, self::SUPERVISOR_SYSTEM);
        $supervisor = new AgentSpeaker(SpeakerRole::Supervisor, $loop);

        return new class ($supervisor) implements SpeakerInterface {
            public function __construct(private readonly AgentSpeaker $supervisor)
            {
            }

            public function name(): SpeakerRole
            {
                return SpeakerRole::Supervisor;
            }

            public function reply(string $incoming): ?string
            {
                $answer = trim($this->supervisor->reply($incoming));

                // ESCALATE (or an empty answer) -> pass up to the next tier (the human).
                return $answer === '' || str_contains(strtoupper($answer), 'ESCALATE') ? null : $answer;
            }
        };
    }

    /**
     * Repair a crashed solver: read its source, hand it and the error to {@see SuperviseWorkflow}
     * (the supervisor role), which writes a corrected version under a new class name. Returns that
     * fully-qualified class name, or null if the repair produced nothing.
     */
    private function repairSolver(RunContext $ctx, string $brokenClass, string $error, int $attempt): ?string
    {
        $fixedName = $ctx->solverName . 'R' . $attempt;
        $fixedClass = $ctx->workflowStore->classFor($fixedName, true);
        $fixedNamespace = $ctx->workflowStore->namespaceFor(true);

        $brokenShort = WorkflowStore::shortName($brokenClass);
        $brokenPath = $ctx->workflowStore->path($brokenShort, true);
        $brokenCode = is_file($brokenPath) ? (string) file_get_contents($brokenPath) : '';

        $span = $ctx->tracer->enterWorkflow('supervise-run');
        try {
            new SuperviseWorkflow($ctx->env, $ctx->runId . '-fix' . $attempt, [
                'brokenName' => $brokenShort,
                'brokenCode' => $brokenCode,
                'error' => $error,
                'fixedName' => $fixedName,
                'fixedNamespace' => $fixedNamespace,
            ], $ctx->issue, $ctx->project)->run();
        } catch (\Cancellation $cancellation) {
            throw $cancellation;
        } catch (\Throwable $e) {
            $ctx->tracer->exit($span);
            $this->frontend->report('repair failed: ' . $e->getMessage(), true);

            return null;
        }
        $ctx->tracer->exit($span);

        return is_file($ctx->workflowStore->path($fixedName, true)) ? $fixedClass : null;
    }
}
