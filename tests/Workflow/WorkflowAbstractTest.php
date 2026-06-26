<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Agent\AgentInterface;
use Claw\Agent\AgentResponse;
use Claw\Agent\Budget;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\ToolUseBlock;
use Claw\Agent\Usage;
use Claw\Exceptions\WorkflowException;
use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Tool\FinishTool;
use Claw\Tool\Registry;
use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;
use Claw\Trace\ArrayTraceSink;
use Claw\Trace\Tracer;
use Claw\Workflow\BudgetPolicy;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;
use Claw\Workflow\InMemoryStateStore;
use Claw\Workflow\Step;
use Claw\Workflow\Tool;
use Claw\Workflow\WorkflowAbstract;
use Claw\Workflow\WorkflowStateStore;
use Testo\Assert;
use Testo\Test;
use Tests\Support\ProbeWorkflow;
use Tests\Support\ScriptedAgent;

final class WorkflowAbstractTest
{
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
    public function aCriticIsAnOrdinaryAiThatGetsEveryToolToVerifyWith(): void
    {
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
                return $this->ai('do it', []);   // the step itself takes no tools
            }
        };

        $wf->run();

        // request 0 = the step's own ai() ([] -> no tools); request 1 = the critic, which must see all.
        Assert::count($worker->requests[1]->tools, 2);
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
            public function make(): string
            {
                return 'the work';
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
    public function theDoneToolFinishesTheRunAndSkipsRemainingSteps(): void
    {
        // The model calls `done` in the first step; the workflow finishes and the second step never runs.
        $doneCall = new ToolUseBlock('d1', 'done', ['summary' => 'subtract added, lint clean']);
        $worker = new ScriptedAgent(new AgentResponse([$doneCall], [$doneCall], StopReason::ToolUse, new Usage()));
        $registry = new Registry();
        $registry->add(new FinishTool());
        $wf = new class ($this->config(worker: $worker, registry: $registry), 'r1') extends WorkflowAbstract {
            public bool $secondRan = false;

            public function name(): string
            {
                return 'fin';
            }

            #[Step]
            public function first(): void
            {
                $this->ai('do the whole task');   // the model decides it is done and calls the tool
            }

            #[Step]
            public function second(): void
            {
                $this->secondRan = true;
            }
        };

        $wf->run();

        Assert::false($wf->secondRan);   // `done` in step 1 skipped step 2
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
            public function make(): string
            {
                $this->runs++;
                $this->work = $this->ai('do it');

                return $this->work;
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
            public function make(): string
            {
                $this->runs++;
                $this->sawCritique = $this->critique();
                $this->work = $this->ai('do it');

                return $this->work;
            }
        };

        $wf->run();

        Assert::same($wf->work, 'v2');                          // the re-run's result, which the critic passed
        Assert::same($wf->runs, 2);                             // one re-run
        Assert::same($wf->sawCritique, 'add the missing test'); // the re-run saw the supervisor's guidance
        Assert::true($supervisor->heard !== null);              // the supervisor was consulted on the rejection
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
        ?WorkflowStateStore $store = null,
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

    private function answer(string $text): AgentResponse
    {
        return new AgentResponse([new TextBlock($text)], [], StopReason::EndTurn, new Usage(), $text);
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
