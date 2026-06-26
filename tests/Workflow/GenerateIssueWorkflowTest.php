<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Agent\AgentResponse;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\Usage;
use Claw\Project\Issue;
use Claw\Project\Project;
use Claw\Tool\DefineWorkflowTool;
use Claw\Tool\ListFilesTool;
use Claw\Tool\ReadFileTool;
use Claw\Tool\Registry;
use Claw\Tool\Workspace;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;
use Claw\Workflow\GenerateIssueWorkflow;
use Claw\Workflow\InMemoryStateStore;
use Claw\Workflow\WorkflowStore;
use Claw\Workflow\WorkflowValidator;
use Testo\Assert;
use Testo\Test;
use Tests\Support\ScriptedAgent;

final class GenerateIssueWorkflowTest
{
    #[Test]
    public function generatesAndSavesASolverWorkflowForTheIssue(): void
    {
        $dir = self::tempDir();

        try {
            $store = new WorkflowStore($dir . '/workflows', 'p1');
            $registry = new Registry();
            $workspace = new Workspace($dir);
            $registry->add(new ReadFileTool($workspace));
            $registry->add(new ListFilesTool($workspace));
            $registry->add(new DefineWorkflowTool($store, new WorkflowValidator()));

            // Each step calls ai(); between steps the engine forms a handoff (continuing the step's
            // conversation), so those replies are interleaved: plan, [handoff], difficulty, [handoff],
            // code, [handoff], OK.
            $agent = new ScriptedAgent(
                self::answer('Plan: read the file, then summarize it.'),
                self::answer('handoff after understand'),
                self::answer('simple — a localized, mechanical change.'),
                self::answer('handoff after assess'),
                self::answer(self::solverCode('Issue7Solver')),
                self::answer('handoff after draft'),
                self::answer('OK'),
            );

            $env = new Environment()
                ->set(EnvKey::Worker, $agent)
                ->set(EnvKey::Registry, $registry)
                ->set(EnvKey::ModelId, 'm')
                ->set(EnvKey::SystemPrompt, '')
                ->set(EnvKey::Store, new InMemoryStateStore());

            $issue = new Issue('7', 'p1', 'Summarize the README');
            $project = new Project('p1', 'Demo');

            new GenerateIssueWorkflow($env, 'gen', [
                'solverName' => 'Issue7Solver',
                'solverNamespace' => 'ClawWorkflow\\Common',
                'solverTools' => ['read_file', 'write_file', 'list_files', 'bash'],
            ], $issue, $project)->run();

            $saved = $store->path('Issue7Solver', true);
            Assert::true(is_file($saved));
            Assert::true(str_contains((string) file_get_contents($saved), 'class Issue7Solver'));
        } finally {
            self::rmrf($dir);
        }
    }

    /** A valid solver class the WorkflowValidator accepts. */
    private static function solverCode(string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace ClawWorkflow\\Common;

            use Claw\\Workflow\\Step;
            use Claw\\Workflow\\WorkflowAbstract;

            final class {$class} extends WorkflowAbstract
            {
                private string \$summary = '';

                public function name(): string
                {
                    return 'solve';
                }

                #[Step]
                protected function summarize(): void
                {
                    \$this->summary = \$this->ai('Summarize the project.', ['read_file']);
                }
            }
            PHP;
    }

    private static function answer(string $text): AgentResponse
    {
        return new AgentResponse([new TextBlock($text)], [], StopReason::EndTurn, new Usage(), $text);
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-genwf-' . uniqid('', true);
        mkdir($dir, 0o775, true);

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rmrf($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
