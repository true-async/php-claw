<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Workflow\AiStep;
use Claw\Workflow\Step;
use Claw\Workflow\StepAI;
use Claw\Workflow\WorkflowAbstract;

/**
 * A test workflow on the new model: state in a field, steps as #[Step] methods, plus proxies that
 * expose the base's protected helpers (tool/param/step/log/issue/project) so each can be driven
 * directly without a bespoke run() body per case.
 *
 * A model exchange is reached the only way the declarative model allows — a #[StepAI] the base runs.
 * {@see runAi()} sets its declaration from the test and drives it, so a probe can assert on the palette,
 * the routed model, the system prompt or the budget stop that one exchange produces.
 */
final class ProbeWorkflow extends WorkflowAbstract
{
    /** State: each step appends to it — snapshotted after every step, restored on resume. */
    public string $trail = '';

    /** The prompt the probe's #[StepAI] declares — set by {@see runAi()} before it runs. */
    public string $aiPrompt = '';

    /** @var ?list<string> the tool palette the probe declares: null = all, [] = none, a list = only those */
    public ?array $aiTools = null;

    /** The agent role the probe routes to, or null for the run's default. */
    public ?string $aiAgent = null;

    public function name(): string
    {
        return 'probe';
    }

    #[Step]
    public function alpha(): void
    {
        $this->trail .= 'a';
    }

    #[Step]
    public function beta(): void
    {
        $this->trail .= 'b';
    }

    /**
     * The one declared exchange the base runs for {@see runAi()}. Pure, as a #[StepAI] must be: it only
     * reads the fields the test set and returns the declaration.
     */
    #[StepAI]
    protected function probe(): AiStep
    {
        return new AiStep($this->aiPrompt, $this->aiTools, $this->aiAgent);
    }

    /**
     * Drive the probe exchange through the base — the declarative equivalent of the old imperative
     * callAi(). It runs the {@see probe()} step so the test can inspect the request it produced (palette,
     * model, system prompt) or the exception a spent budget raises before the exchange begins.
     *
     * @param ?list<string> $tools null = all tools, [] = none, a list = only those
     */
    public function runAi(string $prompt, ?array $tools = null, ?string $agent = null): void
    {
        $this->aiPrompt = $prompt;
        $this->aiTools = $tools;
        $this->aiAgent = $agent;
        $this->step('probe');
    }

    /**
     * The default run() drives only the trail steps — the probe exchange is reached explicitly through
     * {@see runAi()}, never as part of a full run (it needs the test to declare its prompt first).
     */
    public function run(): void
    {
        $this->step('alpha');
        $this->step('beta');
    }

    /** @param array<string, mixed> $params */
    public function callTool(string $name, array $params): string
    {
        return $this->tool($name, $params);
    }

    public function callParam(string $name): mixed
    {
        return $this->param($name);
    }

    public function callStep(string $name): void
    {
        $this->step($name);
    }

    public function callLog(string $action, string $message = ''): void
    {
        $this->log($action, $message);
    }

    public function callAsk(string $question): string
    {
        return $this->ask($question);
    }

    public function callIssue(): ?Issue
    {
        return $this->issue();
    }

    public function callProject(): ?Project
    {
        return $this->project();
    }
}
