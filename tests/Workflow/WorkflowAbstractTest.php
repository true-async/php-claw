<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Agent\AgentInterface;
use Claw\Agent\AgentResponse;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\Usage;
use Claw\Exceptions\WorkflowException;
use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Tool\Registry;
use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;
use Claw\Trace\ArrayTraceSink;
use Claw\Trace\Tracer;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;
use Claw\Workflow\InMemoryStateStore;
use Claw\Workflow\Step;
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
    public function toolThrowsWhenTheCallErrors(): void
    {
        $wf = new ProbeWorkflow($this->config(), 'r1');   // empty registry

        $threw = false;
        try {
            $wf->callTool('nope', []);   // unknown tool -> error result -> WorkflowException
        } catch (WorkflowException) {
            $threw = true;
        }

        Assert::true($threw);
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

    private function config(
        ?AgentInterface $worker = null,
        ?Registry $registry = null,
        ?WorkflowStateStore $store = null,
        ?Tracer $tracer = null,
        string $systemPrompt = '',
    ): Environment {
        $env = (new Environment())
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
