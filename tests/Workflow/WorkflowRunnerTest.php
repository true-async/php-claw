<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Agent\ToolResultBlock;
use Claw\Exec\ExecutorInterface;
use Claw\Tool\ToolCall;
use Claw\Workflow\WorkflowRunner;
use Claw\Workflow\WorkflowStore;
use Testo\Assert;
use Testo\Test;

final class WorkflowRunnerTest
{
    #[Test]
    public function writesLoadsAndRunsAWorkflowThroughTheExecutor(): void
    {
        $dir = sys_get_temp_dir() . '/claw-wf-' . uniqid('', true);

        try {
            $store = new WorkflowStore($dir, 'test');   // session segment "Stest"

            // A workflow that reaches the outside world only through $ctx->call.
            $code = <<<'PHP'
                <?php
                namespace ClawWorkflow\Session\Stest;

                use Claw\Workflow\WorkflowContext;
                use Claw\Workflow\WorkflowInterface;

                final class Greet implements WorkflowInterface
                {
                    public function name(): string { return 'Greet'; }
                    public function description(): string { return 'demo'; }
                    public function inputSchema(): array { return ['type' => 'object']; }

                    public function run(array $input, WorkflowContext $ctx): array
                    {
                        $who = $ctx->call('echo', ['text' => $input['name'] ?? '?']);

                        return ['greeting' => 'Hello ' . $who];
                    }
                }
                PHP;

            $store->write('Greet', $code, false);

            // A fake executor echoes the call's "text" back, standing in for a tool.
            $executor = new class () implements ExecutorInterface {
                public function call(ToolCall $call): ToolResultBlock
                {
                    return new ToolResultBlock($call->id, (string) ($call->input['text'] ?? ''), false);
                }
            };

            $result = (new WorkflowRunner($store, $executor))->run('Greet', ['name' => 'World']);

            Assert::same($result['greeting'] ?? null, 'Hello World');
        } finally {
            @unlink($dir . '/Session/Stest/Greet.php');
            @rmdir($dir . '/Session/Stest');
            @rmdir($dir . '/Session');
            @rmdir($dir);
        }
    }
}
