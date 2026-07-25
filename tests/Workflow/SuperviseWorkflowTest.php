<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Agent\AgentResponse;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\Usage;
use Claw\Tool\DefineWorkflowTool;
use Claw\Tool\Registry;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;
use Claw\Workflow\InMemoryStateStore;
use Claw\Workflow\SuperviseWorkflow;
use Claw\Workflow\WorkflowStore;
use Claw\Workflow\WorkflowValidator;
use Testo\Assert;
use Testo\Test;
use Tests\Support\ScriptedAgent;

final class SuperviseWorkflowTest
{
    #[Test]
    public function repairsAndSavesAFixedSolverUnderTheNewName(): void
    {
        $dir = self::tempDir();

        try {
            $store = new WorkflowStore($dir . '/workflows', 'p1');
            $registry = new Registry();
            $registry->add(new DefineWorkflowTool($store, new WorkflowValidator()));

            // The declarative flow: repair's exchange writes the corrected source, then the base extracts
            // the `code` param that hands it to save — two turns, both the corrected class.
            $agent = new ScriptedAgent(
                self::answer(self::solverCode('Issue7SolverR1')),   // repair: the corrected source
                self::answer(self::solverCode('Issue7SolverR1')),   // code param -> save
            );

            $env = new Environment()
                ->set(EnvKey::Worker, $agent)
                ->set(EnvKey::Registry, $registry)
                ->set(EnvKey::ModelId, 'm')
                ->set(EnvKey::SystemPrompt, '')
                ->set(EnvKey::Store, new InMemoryStateStore());

            new SuperviseWorkflow($env, 'fix1', [
                'brokenName' => 'Issue7Solver',
                'brokenCode' => "<?php\nfinal class Issue7Solver { /* throws */ }",
                'error' => 'TypeError: Return value must be of type string, null returned',
                'fixedName' => 'Issue7SolverR1',
                'fixedNamespace' => 'ClawWorkflow\\Common',
            ])->run();

            $saved = $store->path('Issue7SolverR1', true);
            Assert::true(is_file($saved));
            Assert::true(str_contains((string) file_get_contents($saved), 'class Issue7SolverR1'));
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

            use Claw\\Workflow\\AiStep;
            use Claw\\Workflow\\StepAI;
            use Claw\\Workflow\\WorkflowAbstract;

            final class {$class} extends WorkflowAbstract
            {
                public function name(): string
                {
                    return 'solve';
                }

                #[StepAI]
                protected function summarize(): AiStep
                {
                    return new AiStep('Summarize the project.', ['read_file']);
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
        $dir = sys_get_temp_dir() . '/claw-supwf-' . uniqid('', true);
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
