<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Workflow\Step;
use Claw\Workflow\WorkflowAbstract;

/**
 * A test workflow on the new model: state in a field, steps as #[Step] methods, plus proxies that
 * expose the base's protected helpers (ai/tool/param/step/log/issue/project) so each can be driven
 * directly without a bespoke run() body per case.
 */
final class ProbeWorkflow extends WorkflowAbstract
{
    /** State: each step appends to it — snapshotted after every step, restored on resume. */
    public string $trail = '';

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

    /** @param list<string> $tools */
    public function callAi(string $prompt, array $tools = [], ?string $agent = null): string
    {
        return $this->ai($prompt, $tools, $agent);
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

    public function callIssue(): ?Issue
    {
        return $this->issue();
    }

    public function callProject(): ?Project
    {
        return $this->project();
    }
}
