<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Agent\AgentInterface;
use Claw\Agent\AgentResponse;
use Claw\Agent\Budget;
use Claw\Agent\Role;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\ToolResultBlock;
use Claw\Agent\ToolSpec;
use Claw\Agent\ToolUseBlock;
use Claw\Agent\Usage;
use Claw\Exceptions\WorkflowException;
use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Tool\Registry;
use Claw\Tool\Risk;
use Claw\Tool\ToolCall;
use Claw\Tool\ToolInterface;
use Claw\Trace\ArrayTraceSink;
use Claw\Trace\Tracer;
use Claw\Trace\TraceRecordInterface;
use Claw\Workflow\BudgetPolicy;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;
use Claw\Workflow\InMemoryStateStore;
use Claw\Workflow\Step;
use Claw\Workflow\Tool;
use Claw\Workflow\WorkflowAbstract;
use Claw\Workflow\WorkflowStateStoreInterface;
use Testo\Assert;
use Testo\Test;
use Tests\Support\CriticStepWorkflow;
use Tests\Support\ProbeWorkflow;
use Tests\Support\ScriptedAgent;

final class WorkflowAbstractTest
{
    /** Monotonic id for {@see toolUse()} blocks, so calls in one exchange never collide. */
    private int $toolUseId = 0;

    #[Test]
    public function runDrivesStepMethodsInDeclarationOrder(): void
    {
        $sink = new ArrayTraceSink();
        $wf = new ProbeWorkflow($this->config(tracer: new Tracer('r1', $sink)), 'r1');

        $wf->run();

        Assert::same($wf->trail, 'ab');                              // alpha then beta
        Assert::same($this->stepNames($sink), ['alpha', 'beta']);    // both traced, in order
    }

    #[Test]
    public function stepSnapshotsStateAndProgressToTheStore(): void
    {
        $store = new InMemoryStateStore();
        $wf = new ProbeWorkflow($this->config(store: $store), 'r1');

        $wf->callStep('alpha');

        $snapshot = $store->load('r1');
        Assert::same($snapshot['done'], ['alpha']);
        Assert::same($snapshot['state']['trail'], 'a');
    }

    #[Test]
    public function resumeSkipsCompletedStepsAndRestoresState(): void
    {
        $store = new InMemoryStateStore();

        // First run gets only as far as alpha (a crash before beta).
        $first = new ProbeWorkflow($this->config(store: $store), 'r1');
        $first->callStep('alpha');

        // A fresh instance for the same run resumes: state restored, alpha skipped, beta runs.
        $sink = new ArrayTraceSink();
        $resumed = new ProbeWorkflow($this->config(store: $store, tracer: new Tracer('r1', $sink)), 'r1');
        $resumed->run();

        Assert::same($resumed->trail, 'ab');               // restored 'a' (alpha not re-run) + 'b'
        Assert::same($this->stepNames($sink), ['beta']);   // only beta executed; alpha was skipped
    }

    #[Test]
    public function anAddressedParamReachesOnlyItsStepAndSurvivesResume(): void
    {
        $store = new InMemoryStateStore();
        $make = fn () => new class ($this->config(store: $store), 'r1') extends WorkflowAbstract {
            public string $seen = '';
            public string $seenElsewhere = '';

            public function name(): string
            {
                return 'p';
            }

            public function only(string $name): void
            {
                $this->step($name);
            }

            #[Step]
            protected function decide(): void
            {
                $this->setParam('consume', 'chosen', 'cents');   // pin a value FOR the 'consume' step only
            }

            #[Step]
            protected function consume(): void
            {
                $this->seen = (string) $this->param('chosen');
            }

            #[Step]
            protected function later(): void
            {
                // a step the param was NOT addressed to cannot read it — no peeking across steps
                $this->seenElsewhere = (string) ($this->param('chosen') ?? 'none');
            }
        };

        // First run crashes after 'decide' (the param is pinned and snapshotted), before the rest.
        $make()->only('decide');

        // A fresh instance resumes: 'decide' is skipped; 'consume' reads the RESTORED param (so non-empty
        // 'cents' proves durability — a fresh instance starts with no in-memory params), and 'later' — which
        // the param was not addressed to — sees nothing.
        $resumed = $make();
        $resumed->run();

        Assert::same($resumed->seen, 'cents');         // the addressed step got it, across a resume
        Assert::same($resumed->seenElsewhere, 'none'); // a different step cannot read it
    }

    #[Test]
    public function aReturnsTheModelTextAndAdvertisesOnlyItsToolPalette(): void
    {
        $worker = new ScriptedAgent($this->answer('the answer'));
        $registry = new Registry();
        $registry->add($this->echoTool('read'));
        $registry->add($this->echoTool('bash'));
        $wf = new ProbeWorkflow($this->config(worker: $worker, registry: $registry), 'r1');

        $out = $wf->callAi('hello there', ['read']);

        Assert::same($out, 'the answer');
        Assert::count($worker->requests[0]->tools, 1);          // only the palette is advertised
        Assert::same($worker->requests[0]->tools[0]->name, 'read');
        Assert::true(str_contains($worker->requests[0]->system, '- read:'));   // and named in the system prompt
    }

    #[Test]
    public function aWorkflowsOwnToolMethodIsAdvertisedAlongsideTheGlobalTools(): void
    {
        $worker = new ScriptedAgent($this->answer('ok'));
        $registry = new Registry();
        $registry->add($this->echoTool('read'));
        $wf = new class ($this->config(worker: $worker, registry: $registry), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'local-tool';
            }

            #[Tool(description: 'Shout the text back')]
            public function shout(string $text): string
            {
                return strtoupper($text);
            }

            public function go(): string
            {
                return $this->ai('hi');   // null tools -> the whole palette, locals included
            }
        };

        $wf->go();

        $names = array_map(static fn ($spec): string => $spec->name, $worker->requests[0]->tools);
        Assert::true(\in_array('read', $names, true));    // a global tool
        Assert::true(\in_array('shout', $names, true));   // the workflow's own #[Tool] method
    }

    #[Test]
    public function theModelCanCallAWorkflowsOwnToolMethodAndGetsItsReturnValue(): void
    {
        $shout = new ToolUseBlock('t1', 'shout', ['text' => 'hi']);
        $worker = new ScriptedAgent(
            new AgentResponse([$shout], [$shout], StopReason::ToolUse, new Usage()),
            $this->answer('done'),
        );
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public int $calls = 0;

            public function name(): string
            {
                return 'local-tool';
            }

            #[Tool(description: 'Shout the text back')]
            public function shout(string $text): string
            {
                $this->calls++;

                return strtoupper($text);
            }

            public function go(): string
            {
                return $this->ai('hi');
            }
        };

        Assert::same($wf->go(), 'done');   // the turn loop ran the local tool, then finished
        Assert::same($wf->calls, 1);       // the model's tool call reached the workflow method
    }

    #[Test]
    public function toolResolvesThroughTheRegistryAndReturnsItsContent(): void
    {
        $registry = new Registry();
        $registry->add($this->echoTool('do'));
        $wf = new ProbeWorkflow($this->config(registry: $registry), 'r1');

        Assert::same($wf->callTool('do', ['x' => 'y']), 'ran:y');
    }

    #[Test]
    public function toolReturnsTheErrorAsAStringInsteadOfThrowing(): void
    {
        $wf = new ProbeWorkflow($this->config(), 'r1');   // empty registry

        // An unknown tool errors; tool() hands the failure back as a string (so a step can feed it to
        // the model) rather than crashing the run — mirroring how a tool error inside ai() is handled.
        $result = $wf->callTool('nope', []);

        Assert::true(str_starts_with($result, "tool 'nope' failed: "));
    }

    #[Test]
    public function paramReadsRunParameters(): void
    {
        $wf = new ProbeWorkflow($this->config(), 'r1', ['k' => 'v']);

        Assert::same($wf->callParam('k'), 'v');
        Assert::null($wf->callParam('missing'));
    }

    #[Test]
    public function exposesTheIssueAndProjectTheRunBelongsTo(): void
    {
        $issue = new Issue('i1', 'p1', 'Fix the bug');
        $project = new Project('p1', 'Demo');
        $wf = new ProbeWorkflow($this->config(), 'r1', [], $issue, $project);

        Assert::same($wf->callIssue(), $issue);
        Assert::same($wf->callProject(), $project);
    }

    #[Test]
    public function aCustomRunCanOrchestrateStepsByHand(): void
    {
        $sink = new ArrayTraceSink();
        $wf = new class ($this->config(tracer: new Tracer('r1', $sink)), 'r1') extends WorkflowAbstract {
            public string $trail = '';

            public function name(): string
            {
                return 'custom';
            }

            #[Step]
            public function a(): void
            {
                $this->trail .= 'a';
            }

            #[Step]
            public function b(): void
            {
                $this->trail .= 'b';
            }

            // Override the entry point: run only b, skipping a — orchestration is plain code.
            public function run(): void
            {
                $this->step('b');
            }
        };

        $wf->run();

        Assert::same($wf->trail, 'b');
        Assert::same($this->stepNames($sink), ['b']);
    }

    #[Test]
    public function backReRunsTheTargetStepOnwardAndIsJournaled(): void
    {
        $sink = new ArrayTraceSink();
        $wf = new class ($this->config(tracer: new Tracer('r1', $sink)), 'r1') extends WorkflowAbstract {
            public string $trail = '';

            private int $backs = 0;

            public function name(): string
            {
                return 'backer';
            }

            #[Step]
            public function a(): void
            {
                $this->trail .= 'a';
            }

            #[Step]
            public function b(): void
            {
                $this->trail .= 'b';
            }

            #[Step]
            public function c(): void
            {
                $this->trail .= 'c';

                if ($this->backs === 0) {
                    ++$this->backs;                       // once, so the run terminates
                    $this->back('a', 'redo from a');      // send the run back to step a
                }
            }
        };

        $wf->run();

        // a,b,c ran, then back('a') re-ran a,b,c — the driver honored the backward jump.
        Assert::same($wf->trail, 'abcabc');
        Assert::same($this->stepNames($sink), ['a', 'b', 'c', 'a', 'b', 'c']);

        // and the jump is in the journal, with where it came from / went to / why.
        $backs = array_values(array_filter(
            $sink->records,
            static fn (TraceRecordInterface $record): bool => $record->event()->type === 'back',
        ));
        Assert::count($backs, 1);
        Assert::same($backs[0]->event()->data, ['from' => 'c', 'to' => 'a', 'reason' => 'redo from a']);
    }

    #[Test]
    public function logTracesANote(): void
    {
        $sink = new ArrayTraceSink();
        $wf = new ProbeWorkflow($this->config(tracer: new Tracer('r1', $sink)), 'r1');

        $wf->callLog('did-thing', 'the details');

        $notes = [];

        foreach ($sink->records as $record) {
            if ($record->event()->type === 'note') {
                $notes[] = $record->event()->data;
            }
        }
        Assert::count($notes, 1);
        Assert::same($notes[0]['action'], 'did-thing');
        Assert::same($notes[0]['message'], 'the details');
    }

    #[Test]
    public function aiRoutesToANamedAgentRolesModel(): void
    {
        $worker = new ScriptedAgent($this->answer('ok'), $this->answer('ok'));
        $env = $this->config(worker: $worker)->set(EnvKey::Agents, ['reviewer' => 'model-x']);
        $wf = new ProbeWorkflow($env, 'r1');

        $wf->callAi('hi', [], 'reviewer');
        Assert::same($worker->requests[0]->model, 'model-x');   // routed to the role's model

        $wf->callAi('hi', [], 'unknown');
        Assert::same($worker->requests[1]->model, 'm');         // unknown role -> scope default
    }

    #[Test]
    public function anUnconfiguredProjectManagerFallsBackToAStrongRoleNotTheCheapDefault(): void
    {
        // 'project-manager' decides a ticket's whole strategy, so leaving it unset must NOT drop it to
        // the default (cheap) model the way an ordinary role does — it walks its fallback chain first.
        $worker = new ScriptedAgent($this->answer('ok'), $this->answer('ok'), $this->answer('ok'));
        $env = $this->config(worker: $worker)->set(EnvKey::Agents, ['worker-smart' => 'strong-model']);
        $wf = new ProbeWorkflow($env, 'r1');

        $wf->callAi('hi', [], 'project-manager');
        Assert::same($worker->requests[0]->model, 'strong-model');   // chain reached worker-smart

        // An explicit setting still wins over the chain.
        $own = new ProbeWorkflow(
            $this->config(worker: $worker)->set(EnvKey::Agents, [
                'project-manager' => 'pm-model',
                'worker-smart' => 'strong-model',
            ]),
            'r1',
        );
        $own->callAi('hi', [], 'project-manager');
        Assert::same($worker->requests[1]->model, 'pm-model');

        // With no strong role configured at all there is nothing better to reach for: the default.
        $bare = new ProbeWorkflow($this->config(worker: $worker), 'r1');
        $bare->callAi('hi', [], 'project-manager');
        Assert::same($worker->requests[2]->model, 'm');
    }

    #[Test]
    public function askRoutesToTheChannelAndReturnsItsAnswer(): void
    {
        $channel = new class () implements SpeakerInterface {
            public function name(): SpeakerRole
            {
                return SpeakerRole::Human;
            }

            public function reply(string $incoming): string
            {
                return 'echo:' . $incoming;
            }
        };
        $wf = new ProbeWorkflow($this->config()->set(EnvKey::Ask, $channel), 'r1');

        Assert::same($wf->callAsk('how many?'), 'echo:how many?');
    }

    #[Test]
    public function askThrowsWhenNoChannelIsConfigured(): void
    {
        $wf = new ProbeWorkflow($this->config(), 'r1');   // no EnvKey::Ask set

        $threw = false;

        try {
            $wf->callAsk('anyone?');
        } catch (WorkflowException) {
            $threw = true;
        }

        Assert::true($threw);
    }

    #[Test]
    public function aiThrowsWhenTheRunBudgetIsSpent(): void
    {
        $budget = new Budget(tokenLimit: 10);
        $budget->spend(10);   // already exhausted
        $wf = new ProbeWorkflow($this->config()->set(EnvKey::Budget, $budget), 'r1');

        $threw = false;

        try {
            $wf->callAi('hi');
        } catch (WorkflowException) {
            $threw = true;
        }

        Assert::true($threw);
    }

    #[Test]
    public function stepThrowsWhenTheRunBudgetIsSpent(): void
    {
        $budget = new Budget(tokenLimit: 5);
        $budget->spend(5);   // already exhausted
        $wf = new ProbeWorkflow($this->config()->set(EnvKey::Budget, $budget), 'r1');

        $threw = false;

        try {
            $wf->callStep('alpha');
        } catch (WorkflowException) {
            $threw = true;
        }

        Assert::true($threw);
    }

    #[Test]
    public function askPolicyRaisesTheBudgetAndContinuesOnATopUp(): void
    {
        $budget = new Budget(tokenLimit: 10);
        $budget->spend(10);   // already exhausted

        $channel = new class () implements SpeakerInterface {
            public function name(): SpeakerRole
            {
                return SpeakerRole::Human;
            }

            public function reply(string $incoming): string
            {
                return '+100';   // grant 100 more tokens
            }
        };
        $env = $this->config(worker: new ScriptedAgent($this->answer('done')))
            ->set(EnvKey::Budget, $budget)
            ->set(EnvKey::BudgetPolicy, BudgetPolicy::Ask)
            ->set(EnvKey::Ask, $channel);
        $wf = new ProbeWorkflow($env, 'r1');

        Assert::same($wf->callAi('hi'), 'done');   // topped up, so the call proceeds
    }

    #[Test]
    public function askPolicyStopsWhenNoTopUpIsGiven(): void
    {
        $budget = new Budget(tokenLimit: 10);
        $budget->spend(10);

        $channel = new class () implements SpeakerInterface {
            public function name(): SpeakerRole
            {
                return SpeakerRole::Human;
            }

            public function reply(string $incoming): string
            {
                return '';   // decline -> stop
            }
        };
        $env = $this->config()
            ->set(EnvKey::Budget, $budget)
            ->set(EnvKey::BudgetPolicy, BudgetPolicy::Ask)
            ->set(EnvKey::Ask, $channel);
        $wf = new ProbeWorkflow($env, 'r1');

        $threw = false;

        try {
            $wf->callAi('hi');
        } catch (WorkflowException) {
            $threw = true;
        }

        Assert::true($threw);
    }

    #[Test]
    public function aCriticNameWithNoRulesFailsLoud(): void
    {
        // The step names a critic the workflow never defined rules for — a generation bug we refuse
        // to paper over by judging against an empty rubric.
        $wf = new class ($this->config(worker: new ScriptedAgent($this->answer('x'))), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            #[Step(critic: 'undefined-rules')]
            public function make(): string
            {
                return $this->ai('do it');
            }
        };

        $threw = false;

        try {
            $wf->run();
        } catch (\LogicException) {
            $threw = true;
        }

        Assert::true($threw);
    }

    #[Test]
    public function theCriticsPaletteReplacesComposedCommandsWithRecordedEvidence(): void
    {
        // The critic reads whatever it needs, but the compose-a-command channels are deliberately
        // absent from its palette: it executes only through `rerun_evidence` (replaying commands the
        // reviewed step recorded) and it answers through `verdict`. Bare bash burned real review
        // rounds on invented invocations, and `artifact` would record onto the very step under
        // review. The review-only tools, in turn, must not exist for the working step.
        $worker = new ScriptedAgent($this->answer('the work'), $this->answer('OK'));
        $registry = new Registry();
        $registry->add($this->echoTool('read'));
        $registry->add($this->echoTool('bash'));
        $wf = new class ($this->config(worker: $worker, registry: $registry), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['ok' => 'the work must be fine'];
            }

            #[Step(critic: 'ok')]
            public function make(): string
            {
                return $this->ai('do it');   // the step works on the full palette
            }
        };

        $wf->run();

        // request 0 = the step: the run's tools plus `artifact`; the review-only tools do not exist here.
        $step = array_map(static fn (ToolSpec $spec): string => $spec->name, $worker->requests[0]->tools);
        sort($step);
        Assert::same($step, ['artifact', 'bash', 'read']);

        // request 1 = the critic: bash and artifact are gone; rerun_evidence and verdict arrived.
        $critic = array_map(static fn (ToolSpec $spec): string => $spec->name, $worker->requests[1]->tools);
        sort($critic);
        Assert::same($critic, ['read', 'rerun_evidence', 'verdict']);
    }

    #[Test]
    public function theCriticRerunsRecordedEvidenceVerbatimAndAcceptsThroughTheVerdictTool(): void
    {
        // `bash` is absent from the review palette, yet the replay must still run — on the run's own
        // scope, because the command comes from the step's record, not from the model. And the final
        // text is deliberately NOT 'OK': the verdict tool, not prose, is what accepts the step.
        $bash = new class () implements ToolInterface {
            /** @var list<string> */
            public array $commands = [];

            public function name(): string
            {
                return 'bash';
            }

            public function description(): string
            {
                return 'shell';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function risk(): Risk
            {
                return Risk::Safe;
            }

            public function handle(array $input): string
            {
                $this->commands[] = \is_string($input['command'] ?? null) ? $input['command'] : '';

                return 'OK (3 tests, 5 assertions)';
            }
        };
        $registry = new Registry();
        $registry->add($bash);
        $worker = new ScriptedAgent(
            $this->answer('the work'),                                // the step
            $this->toolUse('rerun_evidence', ['label' => 'tests']),   // the critic replays the record
            $this->toolUse('verdict', ['decision' => 'accept']),      // and accepts through the tool
            $this->answer('reviewed, all good'),                      // closing prose — not 'OK'
        );
        $wf = new class ($this->config(worker: $worker, registry: $registry), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['gate' => 'the tests must be green'];
            }

            #[Step(critic: 'gate')]
            public function make(): void
            {
                $this->ai('do it', []);
                $this->artifact('tests', evidence: 'OK (3 tests, 5 assertions)', from: 'vendor/bin/phpunit --testdox');
            }
        };

        $wf->run();   // completes: the tool-recorded accept is the verdict

        Assert::same($bash->commands, ['vendor/bin/phpunit --testdox']);   // the recorded command, verbatim
    }

    #[Test]
    public function rerunEvidenceRefusesAnUnknownLabelAndNamesTheRecordedOnes(): void
    {
        $worker = new ScriptedAgent(
            $this->answer('the work'),
            $this->toolUse('rerun_evidence', ['label' => 'test']),   // no such label — 'tests' exists
            $this->answer('OK'),
        );
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['gate' => 'the tests must be green'];
            }

            #[Step(critic: 'gate')]
            public function make(): void
            {
                $this->ai('do it', []);
                $this->artifact('tests', evidence: 'OK', from: 'vendor/bin/phpunit');
            }
        };

        $wf->run();

        $refusal = $this->toolResults($worker);
        Assert::true(str_contains($refusal, "no evidence labeled 'test'"));
        Assert::true(str_contains($refusal, "'tests'"));   // the wrong label is answered with the right ones
    }

    #[Test]
    public function aRejectWithoutACitedRubricItemAndFactIsRefusedAtTheCall(): void
    {
        // The citation is a parameter, not an exhortation: a reject that names no broken rule and no
        // observed fact is not expressible — the call is refused and the reviewer must try again.
        $worker = new ScriptedAgent(
            $this->answer('the work'),
            $this->toolUse('verdict', ['decision' => 'reject']),
            $this->answer('OK'),   // the reviewer concedes: nothing concrete to cite
        );
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['ok' => 'must be fine'];
            }

            #[Step(critic: 'ok')]
            public function make(): void
            {
                $this->ai('do it', []);
                $this->artifact('work', 'the work');
            }
        };

        $wf->run();

        Assert::true(str_contains($this->toolResults($worker), 'must cite both'));
    }

    #[Test]
    public function aRejectVerdictCarriesTheRubricItemAndTheFactToTheSupervisor(): void
    {
        $worker = new ScriptedAgent(
            $this->answer('v1'),   // make() #1
            $this->toolUse('verdict', [
                'decision' => 'reject',
                'rubric_item' => 'the work must include a test',
                'fact' => 'the artifact contains no test at all',
            ]),
            $this->answer('rejected'),   // closes the critic's exchange
            $this->answer('v2'),         // make() #2, on the guidance
            $this->answer('OK'),         // critic #2 approves
        );
        $supervisor = new class () implements SpeakerInterface {
            public ?string $heard = null;

            public function name(): SpeakerRole
            {
                return SpeakerRole::Supervisor;
            }

            public function reply(string $incoming): string
            {
                $this->heard = $incoming;

                return 'add the missing test';
            }
        };
        $wf = new CriticStepWorkflow($this->config(worker: $worker)->set(EnvKey::Ask, $supervisor), 'r1');

        $wf->run();

        Assert::same($wf->runs, 2);
        Assert::true(str_contains((string) $supervisor->heard, 'Rubric item violated: the work must include a test'));
        Assert::true(str_contains((string) $supervisor->heard, 'Observed: the artifact contains no test at all'));
    }

    #[Test]
    public function aCannotVerifyVerdictEscalatesAsAVerificationFailureNotAFault(): void
    {
        // v1 had no such lane: "I could not check this" was findings, findings meant rework, and the
        // rework churned on work nobody had faulted. The supervisor now hears what actually happened
        // and settles it — here by accepting, so the step runs exactly once.
        $worker = new ScriptedAgent(
            $this->answer('v1'),
            $this->toolUse('verdict', ['decision' => 'cannot_verify', 'reason' => 'no runnable evidence was recorded']),
            $this->answer('recorded'),
        );
        $supervisor = new class () implements SpeakerInterface {
            public ?string $heard = null;

            public function name(): SpeakerRole
            {
                return SpeakerRole::Supervisor;
            }

            public function reply(string $incoming): string
            {
                $this->heard = $incoming;

                return 'accept';
            }
        };
        $wf = new CriticStepWorkflow($this->config(worker: $worker)->set(EnvKey::Ask, $supervisor), 'r1');

        $wf->run();

        Assert::same($wf->runs, 1);   // accepted as-is; no rework was bought
        Assert::true(str_contains((string) $supervisor->heard, 'COULD NOT VERIFY'));
        Assert::true(str_contains((string) $supervisor->heard, 'no runnable evidence was recorded'));
        Assert::false(str_contains((string) $supervisor->heard, 'did not pass review'));
    }

    #[Test]
    public function aCannotVerifyWithNoSupervisorReRunsTheStepOnTheReason(): void
    {
        // Alone, the productive fix is the step's own: re-run and record the evidence the critic was
        // missing. The reason is the guidance, so the re-run knows exactly what to record.
        $worker = new ScriptedAgent(
            $this->answer('v1'),
            $this->toolUse('verdict', ['decision' => 'cannot_verify', 'reason' => 'record the test run as evidence']),
            $this->answer('recorded'),
            $this->answer('v2'),   // the re-run
            $this->answer('OK'),   // critic #2 approves
        );
        $wf = new CriticStepWorkflow($this->config(worker: $worker), 'r1');

        $wf->run();

        Assert::same($wf->runs, 2);
        Assert::same($wf->sawCritique, 'record the test run as evidence');
    }

    #[Test]
    public function rerunEvidenceOnAStepWithNoRunnableEvidencePointsAtCannotVerify(): void
    {
        $worker = new ScriptedAgent(
            $this->answer('the work'),
            $this->toolUse('rerun_evidence', ['label' => 'tests']),   // nothing runnable was recorded
            $this->answer('OK'),
        );
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['gate' => 'the tests must be green'];
            }

            #[Step(critic: 'gate')]
            public function make(): void
            {
                $this->ai('do it', []);
                $this->artifact('claim', text: 'I ran the tests, trust me');   // a claim, not evidence
            }
        };

        $wf->run();

        $refusal = $this->toolResults($worker);
        Assert::true(str_contains($refusal, 'recorded no runnable evidence'));
        Assert::true(str_contains($refusal, 'cannot_verify'));
    }

    #[Test]
    public function theVerdictToolRefusesEveryMalformedDecisionAtTheCall(): void
    {
        $worker = new ScriptedAgent(
            $this->answer('the work'),
            $this->toolUse('verdict', ['decision' => 'maybe']),            // not a decision
            $this->toolUse('verdict', ['decision' => 'cannot_verify']),    // no reason
            $this->toolUse('verdict', ['decision' => 'accept']),
            $this->answer('done'),
        );
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['ok' => 'must be fine'];
            }

            #[Step(critic: 'ok')]
            public function make(): void
            {
                $this->ai('do it', []);
                $this->artifact('work', 'the work');
            }
        };

        $wf->run();   // completes: the third call is a well-formed accept

        $refusals = $this->toolResults($worker);
        Assert::true(str_contains($refusals, "unknown decision 'maybe'"));
        Assert::true(str_contains($refusals, 'must say in `reason`'));
    }

    #[Test]
    public function aReplayThatFailsToStartIsAToolingProblemNotEvidenceAgainstTheWork(): void
    {
        // No `bash` in the run registry at all — the replay cannot start. The critic must be told
        // this settles nothing about the work, and where that leads: cannot_verify.
        $worker = new ScriptedAgent(
            $this->answer('the work'),
            $this->toolUse('rerun_evidence', ['label' => 'tests']),
            $this->answer('OK'),
        );
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['gate' => 'the tests must be green'];
            }

            #[Step(critic: 'gate')]
            public function make(): void
            {
                $this->ai('do it', []);
                $this->artifact('tests', evidence: 'OK (3 tests)', from: 'vendor/bin/phpunit');
            }
        };

        $wf->run();

        $result = $this->toolResults($worker);
        Assert::true(str_contains($result, 'could not re-run `vendor/bin/phpunit`'));
        Assert::true(str_contains($result, 'tooling problem'));
        Assert::true(str_contains($result, 'cannot_verify'));
    }

    #[Test]
    public function aCriticSeesEvidenceAsCapturedOutputAndTheStepsNoteAsAClaim(): void
    {
        // The reason the kind exists: with only a text artifact, "All tests passed." IS the review's
        // whole input and a step can assert anything. Evidence puts the command's own output in front
        // of the reviewer, with the step's reading of it kept visibly separate.
        $worker = new ScriptedAgent($this->answer('the work'), $this->answer('OK'));
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'ev';
            }

            protected function criticRules(): array
            {
                return ['gate' => 'the tests must be green'];
            }

            #[Step(critic: 'gate')]
            public function make(): void
            {
                $this->ai('do it', []);
                $this->artifact(
                    'tests',
                    text: 'the suite is green',
                    evidence: 'ERRORS! Tests: 10, Errors: 10.',
                    from: 'bash',
                );
            }
        };

        $wf->run();

        $prompt = $this->lastUserText($worker);
        Assert::true(str_contains($prompt, 'CAPTURED OUTPUT'));
        Assert::true(str_contains($prompt, 'ERRORS! Tests: 10, Errors: 10.'));
        // the contradicting summary is shown, but as the step's claim — never as part of the output
        Assert::true(str_contains($prompt, 'which is a claim: the suite is green'));
    }

    /**
     * The `artifact` tool exists, is present in every workflow without anyone declaring it, and its
     * evidence channel takes a COMMAND rather than the output of one.
     *
     * That last part is the whole design. Evidence is the one artifact kind a step cannot compose, and
     * it would stop being that the moment a model could type the text of it — so the model names what
     * to prove and the code runs it. The tool had been instructed in the one hand-written library
     * workflow for some time without ever existing, which made both of that workflow's reviewed steps
     * demand evidence the step had no way to produce.
     */
    #[Test]
    public function theArtifactToolRunsTheCommandItselfSoTheEvidenceIsNotTyped(): void
    {
        $suite = "PHPUnit 9.6.34\n\nOK (34 tests, 91 assertions)";
        $call = new ToolUseBlock('t1', 'artifact', [
            'label' => 'tests',
            'command' => 'vendor/bin/phpunit',
            'note' => 'all green',
        ]);
        $worker = new ScriptedAgent(
            new AgentResponse([$call], [$call], StopReason::ToolUse, new Usage()),
            $this->answer('done'),
        );

        $registry = new Registry();
        $registry->add($this->cannedTool('bash', $suite));

        $sink = new ArrayTraceSink();
        $env = $this->config(worker: $worker, registry: $registry, tracer: new Tracer('r1', $sink));
        $wf = new class ($env, 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'ev';
            }

            #[Step]
            public function make(): void
            {
                $this->ai('prove it');
            }
        };

        $wf->run();

        $recorded = [];

        foreach ($sink->records as $record) {
            if ($record->event()->type === 'artifact') {
                $recorded = $record->event()->data;
            }
        }

        Assert::same($recorded['label'] ?? null, 'tests');
        Assert::same($recorded['kind'] ?? null, 'evidence');
        Assert::same($recorded['value'] ?? null, $suite);                  // verbatim, from the tool that ran
        Assert::same($recorded['source'] ?? null, 'vendor/bin/phpunit');   // the command, not the word 'bash'
        Assert::same($recorded['note'] ?? null, 'all green');              // the model's claim, kept apart
    }

    /**
     * A bad channel combination comes back as a TOOL ERROR the model can read and correct. It must not
     * be the LogicException the PHP-side artifact() throws: the executor turns only ToolException into a
     * tool result, so anything else takes the whole run down — and on a generated solver that crash then
     * triggers a repair pass for code that was never at fault.
     */
    #[Test]
    public function theArtifactToolRefusesABadCallWithoutKillingTheRun(): void
    {
        $call = new ToolUseBlock('t1', 'artifact', ['label' => 'x', 'text' => 'a', 'file' => 'b.txt']);
        $worker = new ScriptedAgent(
            new AgentResponse([$call], [$call], StopReason::ToolUse, new Usage()),
            $this->answer('understood, recorded nothing'),
        );
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public string $out = '';

            public function name(): string
            {
                return 'ev';
            }

            #[Step]
            public function make(): void
            {
                $this->out = $this->ai('record something');
            }
        };

        $wf->run();   // the run survives; the model saw the refusal and carried on

        Assert::same($wf->out, 'understood, recorded nothing');

        // And the refusal reached the model as a tool result it could act on.
        $refusal = '';
        $key = array_key_last($worker->requests) ?? throw new \RuntimeException('no request was made');
        $last = $worker->requests[$key];

        foreach ($last->messages as $message) {
            foreach ($message->content as $block) {
                if ($block instanceof ToolResultBlock) {
                    $refusal = $block->content;
                }
            }
        }

        Assert::true(str_contains($refusal, 'exactly one of'));
    }

    /**
     * A step that withholds a tool withholds it from the MODEL too, including by the back way.
     *
     * `tool()` resolved against the run's full registry, so narrowing a palette only hid tools from the
     * model's own tool list — anything the model could reach INDIRECTLY still got through. Making
     * `artifact` a tool turned that from theory into a shell: `artifact(command: …)` runs its command
     * through `tool()`, so a step that deliberately excluded `bash` handed one over anyway, one
     * indirection further along. A restriction that can be walked around is not a restriction.
     */
    #[Test]
    public function aWithheldToolCannotBeReachedThroughALocalToolEither(): void
    {
        $call = new ToolUseBlock('t1', 'artifact', ['label' => 'sneaky', 'command' => 'echo hello']);
        $worker = new ScriptedAgent(
            new AgentResponse([$call], [$call], StopReason::ToolUse, new Usage()),
            $this->answer('understood'),
        );

        $registry = new Registry();
        $registry->add($this->cannedTool('bash', 'hello'));
        $registry->add($this->echoTool('read'));

        $env = $this->config(worker: $worker, registry: $registry);
        $wf = new class ($env, 'r1') extends WorkflowAbstract {
            public string $out = '';

            public function name(): string
            {
                return 'narrow';
            }

            #[Step]
            public function make(): void
            {
                // Deliberately least-privilege: this step reads, and must not run commands.
                $this->out = $this->ai('look, do not touch', ['read', 'artifact']);
            }
        };

        $wf->run();

        $refusal = '';
        $key = array_key_last($worker->requests) ?? throw new \RuntimeException('no request was made');

        foreach ($worker->requests[$key]->messages as $message) {
            foreach ($message->content as $block) {
                if ($block instanceof ToolResultBlock) {
                    $refusal = $block->content;
                }
            }
        }

        // The command did not run: the artifact tool reached for `bash` and this step has no `bash`.
        Assert::true(str_contains($refusal, 'could not run `echo hello`'));
        Assert::true(str_contains($refusal, 'Unknown tool: bash'));

        // And nothing was recorded — a command that could not run is not evidence of anything.
        Assert::false(str_contains($refusal, "recorded 'sneaky'"));
    }

    /**
     * A tool run on the autonomous path is bounded.
     *
     * It was not, and the only clock on that path is the run's total budget — which a hung tool does not
     * tick, because the seconds are checked when something returns and nothing does. A `bash` waiting on
     * a prompt nobody will type held the run open for as long as the process lived. The chat path has had
     * this cap for as long as it has existed; the path where nobody is watching had none.
     */
    #[Test]
    public function aToolRunIsBoundedOnTheAutonomousPathToo(): void
    {
        $registry = new Registry();
        $registry->add($this->echoTool('read'));

        $bare = new Environment()->set(EnvKey::Registry, $registry);
        Assert::same($bare->executor()->call(new ToolCall('1', 'read', ['x' => 'hi']))->content, 'ran:hi');

        // With the cap set, the same call still works — the guard bounds a tool, it does not change one.
        $capped = new Environment()->set(EnvKey::Registry, $registry)->set(EnvKey::ToolTimeoutMs, 5000);
        $result = $capped->executor()->call(new ToolCall('2', 'read', ['x' => 'hi']));

        Assert::same($result->content, 'ran:hi');
        Assert::false($result->isError);
    }

    /** A tool that ignores its input and returns a fixed string — stands in for a real command run. */
    private function cannedTool(string $name, string $output): ToolInterface
    {
        return new class ($name, $output) implements ToolInterface {
            public function __construct(private readonly string $toolName, private readonly string $output)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'runs a command';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['command' => ['type' => 'string']]];
            }

            public function risk(): Risk
            {
                return Risk::Safe;
            }

            public function handle(array $input): string
            {
                return $this->output;
            }
        };
    }

    #[Test]
    public function anArtifactWithNoContentChannelFailsLoud(): void
    {
        $wf = new class ($this->config(), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'ev';
            }

            public function go(): void
            {
                $this->artifact('nothing');
            }
        };

        $threw = false;

        try {
            $wf->go();
        } catch (\LogicException $e) {
            $threw = str_contains($e->getMessage(), 'needs exactly one of');
        }

        Assert::true($threw);
    }

    #[Test]
    public function theCriticIsToldAnArtifactIsAClaimItMustCheckRatherThanTrust(): void
    {
        // A real run shipped broken work because a generated step hardcoded the artifact "All tests
        // passed" while the suite was erroring, and the critic replied OK in two tokens without opening
        // anything. It was doing as it was told: the prompt used to say the artifacts were "usually
        // enough". Giving the critic tools is worthless if it is told to trust the summary, so the
        // instruction to verify is part of the contract and is asserted here.
        $worker = new ScriptedAgent($this->answer('the work'), $this->answer('OK'));
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['ok' => 'the tests must be green'];
            }

            #[Step(critic: 'ok')]
            public function make(): void
            {
                $this->ai('do it', []);
                $this->artifact('result', text: 'All tests passed.');
            }
        };

        $wf->run();

        $prompt = $this->lastUserText($worker);
        Assert::true(str_contains($prompt, 'claim, not evidence'));
        Assert::true(str_contains($prompt, 'MUST establish it yourself with a tool'));
        // and the old instruction that caused the rubber-stamping must not come back
        Assert::false(str_contains($prompt, 'usually enough'));

        // The demand to verify must stay SUBORDINATE to the rubric. A real run showed why: the critic
        // reviewing a generated solver ran the project's tests, found them red — which they are, the
        // solver has not run yet — and rejected the source over it. A false pass became a false
        // failure, so the carve-out for not-yet-run artifacts is part of the contract too.
        Assert::true(str_contains($prompt, 'OUTRANKS this instruction'));
        Assert::true(str_contains($prompt, 'has NOT run yet'));
    }

    #[Test]
    public function aWorkflowCanOverrideTheCriticsStandingRole(): void
    {
        $worker = new ScriptedAgent($this->answer('OK'));   // the step has no ai(); the critic is request 0
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['ok' => 'must be fine'];
            }

            protected function criticRole(): string
            {
                return 'ACT AS A SECURITY AUDITOR, NOT A STYLE REVIEWER.';
            }

            #[Step(critic: 'ok')]
            public function make(): void
            {
                $this->artifact('work', 'the work');   // a result, so the critic actually runs to judge it
            }
        };

        $wf->run();

        $block = $worker->requests[0]->messages[0]->content[0];
        $prompt = $block instanceof TextBlock ? $block->text : '';
        Assert::true(str_contains($prompt, 'ACT AS A SECURITY AUDITOR'));   // the override reached the critic
    }

    #[Test]
    public function anArtifactIsRecordedInTheRunsJournal(): void
    {
        $sink = new ArrayTraceSink();
        $wf = new class ($this->config(tracer: new Tracer('r1', $sink)), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'art';
            }

            #[Step]
            public function emit(): void
            {
                $this->artifact('result', text: 'subtract added; php -l clean');
                $this->artifact('file', file: 'src/Calculator.php');
            }
        };

        $wf->run();

        $artifacts = [];

        foreach ($sink->records as $record) {
            if ($record->event()->type === 'artifact') {
                $artifacts[] = $record->event()->data['label'] . ':' . $record->event()->data['kind'];
            }
        }

        Assert::same($artifacts, ['result:text', 'file:file']);
    }

    #[Test]
    public function aStuckCriticEscalatesAtTheRoundCapAndStopsWithNoOneToAsk(): void
    {
        // The critic never passes and there is no supervisor channel. Below the cap the step
        // self-corrects on findings; at the per-step cap (2) it escalates — and with no one to ask,
        // it stops rather than rework forever.
        $worker = new ScriptedAgent($this->answer('nope'), $this->answer('nope'), $this->answer('nope'));
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['x' => 'must be perfect'];
            }

            #[Step(critic: 'x', maxRounds: 2)]
            public function make(): string
            {
                return 'work';
            }
        };

        $threw = false;

        try {
            $wf->run();
        } catch (WorkflowException) {
            $threw = true;
        }

        Assert::true($threw);
    }

    #[Test]
    public function aStepsWorkIsHandedToTheNextStepAsAConsciouslyFormedBaton(): void
    {
        // first() does its work in an ai() exchange; the handoff is formed by CONTINUING that exact
        // conversation (request 1), then the second step's ai() (request 2) carries the baton.
        $worker = new ScriptedAgent(
            $this->answer('did the work'),                            // first()'s own ai()
            $this->answer('added subtract(); next, run the tests'),   // the handoff, formed in-context
            $this->answer('ok'),                                      // second()'s own ai()
        );
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'relay';
            }

            #[Step]
            public function first(): void
            {
                $this->ai('do the work');
            }

            #[Step]
            public function second(): void
            {
                $this->ai('carry on');
            }
        };

        $wf->run();

        // the handoff (request 1) CONTINUED first()'s conversation: it carries the work's user turn,
        // the assistant turn, and the handoff question — 3 messages, not a fresh 1.
        Assert::count($worker->requests[1]->messages, 3);
        // and that baton reaches the second step's own ai()
        Assert::true(str_contains($worker->requests[2]->system, 'added subtract(); next, run the tests'));
    }

    #[Test]
    public function handoffFormationIsTracedUnderItsOwnRoleNotTheNextStepsWork(): void
    {
        // The handoff exchange runs at the FIRST ai() of the NEXT step, so its ai span sat inside that
        // step's span under the 'worker' role — reading as the next step doing work it had not begun
        // (Edmond flagged an 'assess' step as doing nothing, its only visible exchange being the PRIOR
        // step's handoff). It is now traced as role 'handoff', distinct from the step's own work.
        $worker = new ScriptedAgent(
            $this->answer('did the work'),   // first()'s own ai()
            $this->answer('the baton'),      // the handoff formation
            $this->answer('ok'),             // second()'s own ai()
        );
        $sink = new ArrayTraceSink();
        $this->relay($this->config(worker: $worker, tracer: new Tracer('r1', $sink)))->run();

        $aiRoles = [];

        foreach ($sink->records as $record) {
            if ($record->event()->type === 'ai') {
                $aiRoles[] = (string) $record->event()->data['role'];
            }
        }

        // first's work, then the handoff formation under its own role, then second's work
        Assert::same($aiRoles, ['worker', 'handoff', 'worker']);
    }

    #[Test]
    public function aFormedHandoffIsSavedToTheStoreKeyedByTheStepThatFormedIt(): void
    {
        // first() works, second()'s ai() triggers the handoff formation (request 1, continuing first()'s
        // conversation). The instant it is formed it is saved to the store, keyed by 'first'.
        $store = new InMemoryStateStore();
        $worker = new ScriptedAgent(
            $this->answer('did the work'),
            $this->answer('added subtract(); next, run the tests'),
            $this->answer('ok'),
        );
        $this->relay($this->config(worker: $worker, store: $store))->run();

        Assert::same($store->loadHandoff('r1'), ['from' => 'first', 'handoff' => 'added subtract(); next, run the tests']);
    }

    #[Test]
    public function aResumeReadsTheSavedHandoffFromTheStoreWithoutReFormingIt(): void
    {
        // A fresh instance for the same run — the conversation that formed the handoff is gone, only the
        // store survived. The handoff saved by the last finished step ('first') is restored at
        // construction and reaches the next step's ai() WITHOUT a second formation call.
        $store = new InMemoryStateStore();
        $store->save('r1', [], ['first']);                            // 'first' already done
        $store->saveHandoff('r1', 'first', 'added subtract(); run the tests next');   // its handoff, persisted

        $worker = new ScriptedAgent($this->answer('ok'));            // ONE outcome: no re-formation allowed
        $this->relay($this->config(worker: $worker, store: $store))->run();

        Assert::count($worker->requests, 1);                                         // second()'s ai() only — no re-form
        Assert::true(str_contains($worker->requests[0]->system, 'added subtract(); run the tests next'));
    }

    #[Test]
    public function aStaleHandoffFromAnEarlierStepIsNotReplayedOnResume(): void
    {
        // The store holds a handoff from 'first', but the run already finished 'second' too. That handoff
        // is stale — its reader ('second') has run — so the resumed run must NOT feed it to a later step.
        $store = new InMemoryStateStore();
        $store->save('r1', [], ['first', 'second']);
        $store->saveHandoff('r1', 'first', 'stale context from the first step');

        $worker = new ScriptedAgent($this->answer('did three'), $this->answer('h3'));
        $wf = new class ($this->config(worker: $worker, store: $store), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'relay3';
            }

            #[Step]
            protected function first(): void
            {
                $this->ai('one');
            }

            #[Step]
            protected function second(): void
            {
                $this->ai('two');
            }

            #[Step]
            protected function third(): void
            {
                $this->ai('three');
            }
        };

        $wf->run();   // only third() runs

        Assert::false(str_contains($worker->requests[0]->system, 'stale context from the first step'));
    }

    #[Test]
    public function aStepWhoseCriticPassesRunsOnce(): void
    {
        $worker = new ScriptedAgent(
            $this->answer('the work'),   // the step's ai()
            $this->answer('OK'),         // the critic (reviewer) approves
        );
        $wf = new class ($this->config(worker: $worker), 'r1') extends WorkflowAbstract {
            public string $work = '';
            public int $runs = 0;

            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['must be good' => 'the work must be good'];
            }

            #[Step(critic: 'must be good')]
            public function make(): void
            {
                $this->runs++;
                $this->work = $this->ai('do it');
                $this->artifact('work', $this->work);   // the critic judges this artifact, not a return value
            }
        };

        $wf->run();

        Assert::same($wf->work, 'the work');
        Assert::same($wf->runs, 1);   // critic happy first time -> no re-run
    }

    #[Test]
    public function aRejectedStepIsGuidedByTheSupervisorAndReruns(): void
    {
        $worker = new ScriptedAgent(
            $this->answer('v1'),           // make() #1
            $this->answer('needs a test'), // critic #1 -> not OK
            $this->answer('v2'),           // make() #2 (re-run with the guidance)
            $this->answer('OK'),           // critic #2 -> approves
        );
        $supervisor = new class () implements SpeakerInterface {
            public ?string $heard = null;

            public function name(): SpeakerRole
            {
                return SpeakerRole::Supervisor;
            }

            public function reply(string $incoming): string
            {
                $this->heard = $incoming;

                return 'add the missing test';   // guidance, not accept/stop
            }
        };
        $env = $this->config(worker: $worker)->set(EnvKey::Ask, $supervisor);
        $wf = new class ($env, 'r1') extends WorkflowAbstract {
            public string $work = '';
            public int $runs = 0;
            public ?string $sawCritique = null;

            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['must be tested' => 'the work must include a test'];
            }

            #[Step(critic: 'must be tested')]
            public function make(): void
            {
                $this->runs++;
                $this->sawCritique = $this->critique();
                $this->work = $this->ai('do it');
                $this->artifact('work', $this->work);   // the critic AND the supervisor judge this artifact
            }
        };

        $wf->run();

        Assert::same($wf->work, 'v2');                          // the re-run's result, which the critic passed
        Assert::same($wf->runs, 2);                             // one re-run
        Assert::same($wf->sawCritique, 'add the missing test'); // the re-run saw the supervisor's guidance
        Assert::true($supervisor->heard !== null);              // the supervisor was consulted on the rejection
        // the supervisor judged the ACTUAL work — the rejected attempt's artifact ('v1'), not an empty result
        Assert::true(str_contains((string) $supervisor->heard, 'v1'));
    }

    /**
     * The control words are whole replies, not prefixes. `str_starts_with($lower, 'stop')` turned this
     * perfectly ordinary piece of advice into a killed run — while the same prompt asks the supervisor
     * for prose guidance. A misread must send the step back for another try, never abort it.
     */
    #[Test]
    public function guidanceThatMerelyBeginsWithStopIsGuidanceAndNotAnAbort(): void
    {
        $worker = new ScriptedAgent(
            $this->answer('v1'),
            $this->answer('needs a test'),   // critic #1 -> not OK
            $this->answer('v2'),
            $this->answer('OK'),             // critic #2 -> approves
        );
        $wf = $this->supervised($worker, 'Stop rerunning the whole suite; run only the failing test.');

        $wf->run();

        Assert::same($wf->work, 'v2');
        Assert::same($wf->runs, 2);
        Assert::same($wf->sawCritique, 'Stop rerunning the whole suite; run only the failing test.');
    }

    /**
     * Silence is not consent. An empty reply used to be read as `accept`, which made the emptiest
     * possible answer — nobody on the channel, or a person pressing Enter — the strongest verdict in the
     * system: it closed out work the critic had just rejected. Below the round cap it now self-corrects
     * on the findings, exactly as if there had been no supervisor at all.
     */
    #[Test]
    public function anEmptyReplyDoesNotAcceptWorkTheCriticRejected(): void
    {
        $worker = new ScriptedAgent(
            $this->answer('v1'),
            $this->answer('needs a test'),   // critic #1 -> not OK
            $this->answer('v2'),
            $this->answer('OK'),             // critic #2 -> approves
        );
        $wf = $this->supervised($worker, '   ');

        $wf->run();

        Assert::same($wf->runs, 2);                       // it re-ran rather than being accepted as-is
        Assert::same($wf->sawCritique, 'needs a test');   // and it re-ran on the critic's own findings
    }

    /**
     * A one-step workflow whose step carries a critic, wired to a supervisor that always gives $reply.
     * The step records its result as the artifact both the critic and the supervisor judge.
     */
    private function supervised(ScriptedAgent $worker, string $reply): CriticStepWorkflow
    {
        $supervisor = new class ($reply) implements SpeakerInterface {
            public function __construct(private readonly string $reply)
            {
            }

            public function name(): SpeakerRole
            {
                return SpeakerRole::Supervisor;
            }

            public function reply(string $incoming): string
            {
                return $this->reply;
            }
        };

        return new CriticStepWorkflow($this->config(worker: $worker)->set(EnvKey::Ask, $supervisor), 'r1');
    }

    /**
     * A step interrupted mid-exchange carries on from where it stopped, instead of starting over.
     *
     * The step-edge snapshot records which steps FINISHED, so a crash inside one replayed that whole
     * step from a cold start: the model was asked again for work it had already done, against a world
     * that had already moved — the files it wrote the first time were still written. Idempotency was
     * neither stated as a contract nor achieved by the machinery.
     *
     * It is achieved by resuming rather than replaying. The exchange is written down at every turn, and
     * a re-entered step continues it: the model sees its own earlier tool results in the history and has
     * no reason to repeat them.
     */
    #[Test]
    public function aStepInterruptedMidExchangeResumesItRatherThanRepeatingIt(): void
    {
        $store = new InMemoryStateStore();
        $use = new ToolUseBlock('t1', 'write', ['path' => 'src/X.php']);

        // The first process: one turn lands (the tool ran), then the model call dies.
        $dying = new ScriptedAgent(
            new AgentResponse([$use], [$use], StopReason::ToolUse, new Usage()),
            new \RuntimeException('the process went away'),
        );
        $registry = new Registry();
        $registry->add($this->echoTool('write'));

        $first = new class ($this->config(worker: $dying, registry: $registry, store: $store), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crashy';
            }

            #[Step]
            protected function implement(): void
            {
                $this->ai('write the file');
            }
        };

        $crashed = false;

        try {
            $first->run();
        } catch (\RuntimeException) {
            $crashed = true;
        }

        Assert::true($crashed);

        // The second process picks the same run up. Nothing finished, so the step runs again — but it
        // must not start cold.
        $resumed = new ScriptedAgent($this->answer('already written, carrying on'));
        $second = new class ($this->config(worker: $resumed, registry: $registry, store: $store), 'r1') extends WorkflowAbstract {
            public string $out = '';

            public function name(): string
            {
                return 'crashy';
            }

            #[Step]
            protected function implement(): void
            {
                $this->out = $this->ai('write the file');
            }
        };

        $second->run();

        Assert::same($second->out, 'already written, carrying on');

        // The model was handed the interrupted conversation: its own tool call and the result it got.
        $sent = $resumed->requests[0]->messages;
        $kinds = [];

        foreach ($sent as $message) {
            foreach ($message->content as $block) {
                $kinds[] = $block::class;
            }
        }

        Assert::true(\in_array(ToolUseBlock::class, $kinds, true));      // what it had already asked for
        Assert::true(\in_array(ToolResultBlock::class, $kinds, true));   // and what came back

        // And once the step finishes, the half-conversation is not left lying around to re-enter.
        Assert::same($store->loadExchange('r1', 'implement'), []);
    }

    /**
     * A critic must not overwrite the exchange of the step it is reviewing.
     *
     * Found on a live run, not by reading: a server was killed mid-step and restarted, and the exchange
     * saved for that step turned out to hold "You are a REVIEWER of a workflow step…". The critic runs
     * INSIDE the step it judges, so `currentStep` still names that step, and its conversation was
     * landing under the same key.
     *
     * Had the process died during a review, the resumed step would have been handed the reviewer's
     * conversation as its own — a worker continuing a critique of itself, which is nonsense arriving in
     * the shape of context. A critic's exchange is not worth keeping anyway: re-reviewing is cheap, and
     * unlike the work, repeating it costs nothing that was already achieved.
     */
    #[Test]
    public function aCriticDoesNotOverwriteTheExchangeOfTheStepItReviews(): void
    {
        $store = new InMemoryStateStore();
        $worker = new ScriptedAgent(
            $this->answer('the work'),   // the step
            $this->answer('OK'),         // the critic
        );

        $wf = new class ($this->config(worker: $worker, store: $store), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'crt';
            }

            protected function criticRules(): array
            {
                return ['gate' => 'the work must be fine'];
            }

            #[Step(critic: 'gate')]
            public function make(): void
            {
                $this->ai('do it');
                $this->artifact('work', text: 'done');
            }

            /** Run only the step, so the exchange is still there to look at afterwards. */
            public function only(): void
            {
                $this->step('make');
            }
        };

        $wf->only();

        // The step completed, so its exchange is cleared — the interesting part is WHAT was cleared, so
        // look at what the store holds for the step while the critic was the last thing to speak.
        $left = $store->loadExchange('r1', 'make');
        $text = '';

        foreach ($left as $message) {
            foreach ($message->content as $block) {
                if ($block instanceof TextBlock) {
                    $text .= $block->text;
                }
            }
        }

        Assert::false(str_contains($text, 'REVIEWER'));
    }

    #[Test]
    public function aFileTheModelWritesBecomesAFileArtifactWithoutBeingRecordedByHand(): void
    {
        // The deliverable — the file the step wrote — is now a visible result on its own. Before, a
        // step could write src/Stats.php and record nothing, leaving the panel unable to say where
        // the result was.
        $write = $this->toolUse('write_file', ['path' => 'src/Stats.php', 'content' => '<?php class Stats {}']);
        $worker = new ScriptedAgent(
            $write,
            $this->answer('wrote the class'),
        );
        $registry = new Registry();
        $registry->add($this->cannedTool('write_file', 'Wrote 21 bytes to src/Stats.php'));

        $sink = new ArrayTraceSink();
        $wf = new class ($this->config(worker: $worker, registry: $registry, tracer: new Tracer('r1', $sink)), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'w';
            }

            #[Step]
            public function make(): void
            {
                $this->ai('write it');
            }
        };

        $wf->run();

        Assert::same($this->fileArtifacts($sink), ['src/Stats.php']);
    }

    #[Test]
    public function writtenFilesAreDedupedByPathAndAFailedWriteLeavesNoArtifact(): void
    {
        // Two writes to one path show it once (last wins, same path either way); a write that ERRORED
        // is not a deliverable and leaves nothing behind.
        $ok1 = new ToolUseBlock('t1', 'write_file', ['path' => 'a.txt', 'content' => 'one']);
        $ok2 = new ToolUseBlock('t2', 'write_file', ['path' => 'a.txt', 'content' => 'two']);
        $bad = new ToolUseBlock('t3', 'write_file', ['path' => 'nope/b.txt', 'content' => 'x']);
        $worker = new ScriptedAgent(
            new AgentResponse([$ok1, $ok2, $bad], [$ok1, $ok2, $bad], StopReason::ToolUse, new Usage()),
            $this->answer('done'),
        );

        // A write_file whose handle() throws for the third path — the executor turns it into an error result.
        $registry = new Registry();
        $registry->add(new class () implements ToolInterface {
            public function name(): string
            {
                return 'write_file';
            }

            public function description(): string
            {
                return 'writes';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function risk(): Risk
            {
                return Risk::Mutating;
            }

            public function handle(array $input): string
            {
                if (($input['path'] ?? '') === 'nope/b.txt') {
                    throw new \Claw\Exceptions\ToolException('cannot write nope/b.txt');
                }

                return 'Wrote';
            }
        });

        $sink = new ArrayTraceSink();
        $wf = new class ($this->config(worker: $worker, registry: $registry, tracer: new Tracer('r1', $sink)), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'w';
            }

            #[Step]
            public function make(): void
            {
                $this->ai('write them');
            }
        };

        $wf->run();

        Assert::same($this->fileArtifacts($sink), ['a.txt']);   // deduped to one; the failed write is absent
    }

    #[Test]
    public function aHandRecordedFileIsNotDoubledByTheAutoArtifact(): void
    {
        // The step records the file it wrote through the artifact tool AND the auto-capture sees the
        // same write — the path must appear once, not twice.
        $write = $this->toolUse('write_file', ['path' => 'src/Calc.php', 'content' => '<?php']);
        $worker = new ScriptedAgent(
            $write,
            $this->answer('done'),
        );
        $registry = new Registry();
        $registry->add($this->cannedTool('write_file', 'Wrote'));

        $sink = new ArrayTraceSink();
        $wf = new class ($this->config(worker: $worker, registry: $registry, tracer: new Tracer('r1', $sink)), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'w';
            }

            #[Step]
            public function make(): void
            {
                $this->ai('write it');
                $this->artifact('the calculator', file: 'src/Calc.php');   // recorded by hand too
            }
        };

        $wf->run();

        Assert::same($this->fileArtifacts($sink), ['src/Calc.php']);   // one file artifact for the path, not two
    }

    #[Test]
    public function aWrittenFileIsAttributedToTheStepThatWroteItNotTheNextOne(): void
    {
        // Handoff formation continues the PREVIOUS step's conversation — which still holds its
        // write_file calls. Recording must not run there, or the first step's file is re-attributed
        // to the second: the file must appear once, under the step that actually wrote it.
        $write = $this->toolUse('write_file', ['path' => 'src/A.php', 'content' => '<?php']);
        $worker = new ScriptedAgent(
            $write,                        // step A: writes the file
            $this->answer('wrote A'),      // step A: finishes
            $this->answer('the handoff'),  // handoff formation (continues A's history) — must record nothing
            $this->answer('reasoned'),     // step B: makes an ai() call but writes nothing
        );
        $registry = new Registry();
        $registry->add($this->cannedTool('write_file', 'Wrote'));

        $sink = new ArrayTraceSink();
        $wf = new class ($this->config(worker: $worker, registry: $registry, tracer: new Tracer('r1', $sink)), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'ab';
            }

            #[Step]
            public function alpha(): void
            {
                $this->ai('write A');
            }

            #[Step]
            public function beta(): void
            {
                $this->ai('carry on');   // its first ai() forms alpha's handoff first
            }
        };

        $wf->run();

        Assert::same($this->fileArtifacts($sink), ['src/A.php']);   // once, not doubled under beta
    }

    #[Test]
    public function filesWrittenAcrossSeveralAiCallsInOneStepAllBecomeArtifacts(): void
    {
        $writeA = $this->toolUse('write_file', ['path' => 'a.php', 'content' => '1']);
        $writeB = $this->toolUse('write_file', ['path' => 'b.php', 'content' => '2']);
        $worker = new ScriptedAgent(
            $writeA,
            $this->answer('a done'),   // first ai() ends
            $writeB,
            $this->answer('b done'),   // second ai() ends
        );
        $registry = new Registry();
        $registry->add($this->cannedTool('write_file', 'Wrote'));

        $sink = new ArrayTraceSink();
        $wf = new class ($this->config(worker: $worker, registry: $registry, tracer: new Tracer('r1', $sink)), 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'w';
            }

            #[Step]
            public function make(): void
            {
                $this->ai('write a');
                $this->ai('write b');   // a fresh exchange; its writes must accumulate onto the first's
            }
        };

        $wf->run();

        Assert::same($this->fileArtifacts($sink), ['a.php', 'b.php']);
    }

    /**
     * The values of every `file` artifact recorded in the run's journal, in order — how the
     * auto-capture's effect is asserted.
     *
     * @return list<string>
     */
    private function fileArtifacts(ArrayTraceSink $sink): array
    {
        $files = [];

        foreach ($sink->records as $record) {
            $event = $record->event();

            if ($event->type === 'artifact' && ($event->data['kind'] ?? '') === 'file') {
                $files[] = (string) $event->data['value'];
            }
        }

        return $files;
    }

    /** A two-step relay workflow whose steps each make one ai() call — for handoff/resume cases. */
    private function relay(Environment $env): WorkflowAbstract
    {
        return new class ($env, 'r1') extends WorkflowAbstract {
            public function name(): string
            {
                return 'relay';
            }

            #[Step]
            protected function first(): void
            {
                $this->ai('do the work');
            }

            #[Step]
            protected function second(): void
            {
                $this->ai('carry on');
            }
        };
    }

    private function config(
        ?AgentInterface $worker = null,
        ?Registry $registry = null,
        ?WorkflowStateStoreInterface $store = null,
        ?Tracer $tracer = null,
        string $systemPrompt = '',
    ): Environment {
        $env = new Environment()
            ->set(EnvKey::Worker, $worker ?? new ScriptedAgent())
            ->set(EnvKey::Registry, $registry ?? new Registry())
            ->set(EnvKey::ModelId, 'm')
            ->set(EnvKey::SystemPrompt, $systemPrompt)
            ->set(EnvKey::Store, $store ?? new InMemoryStateStore());

        if ($tracer !== null) {
            $env->set(EnvKey::Tracer, $tracer);
        }

        return $env;
    }

    /**
     * The names of the steps that opened a span, in order.
     *
     * @return list<string>
     */
    private function stepNames(ArrayTraceSink $sink): array
    {
        $names = [];

        foreach ($sink->records as $record) {
            $event = $record->event();

            if ($event->type === 'step') {
                $names[] = (string) ($event->data['name'] ?? '');
            }
        }

        return $names;
    }

    /** The text of the last user turn the agent was sent — how a prompt is asserted on. */
    private function lastUserText(ScriptedAgent $worker): string
    {
        $last = end($worker->requests);
        $messages = $last === false ? [] : $last->messages;
        $text = '';

        foreach ($messages as $message) {
            if ($message->role !== Role::User) {
                continue;
            }

            foreach ($message->content as $block) {
                if ($block instanceof TextBlock) {
                    $text = $block->text;
                }
            }
        }

        return $text;
    }

    private function answer(string $text): AgentResponse
    {
        return new AgentResponse([new TextBlock($text)], [], StopReason::EndTurn, new Usage(), $text);
    }

    /**
     * A model turn that calls one tool — id'd uniquely so several can share one exchange.
     *
     * @param array<string, mixed> $params
     */
    private function toolUse(string $name, array $params): AgentResponse
    {
        $call = new ToolUseBlock('t' . ++$this->toolUseId, $name, $params);

        return new AgentResponse([$call], [$call], StopReason::ToolUse, new Usage());
    }

    /** Every tool result the agent was ever shown, concatenated — how a tool refusal is asserted on. */
    private function toolResults(ScriptedAgent $worker): string
    {
        $text = '';

        foreach ($worker->requests as $request) {
            foreach ($request->messages as $message) {
                foreach ($message->content as $block) {
                    if ($block instanceof ToolResultBlock) {
                        $text .= $block->content . "\n";
                    }
                }
            }
        }

        return $text;
    }

    /** A tool that echoes its 'x' input — lets a call's result and params be asserted. */
    private function echoTool(string $name): ToolInterface
    {
        return new class ($name) implements ToolInterface {
            public function __construct(private readonly string $toolName)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'a tool';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function risk(): Risk
            {
                return Risk::Safe;
            }

            public function handle(array $input): string
            {
                return 'ran:' . (\is_string($input['x'] ?? null) ? $input['x'] : '');
            }
        };
    }
}
