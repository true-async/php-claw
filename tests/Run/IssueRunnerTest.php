<?php

declare(strict_types=1);

namespace Tests\Run;

use Claw\Config;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
use Claw\Project\RunStatus;
use Claw\Run\IssueRunner;
use Claw\Trace\TraceReader;
use Claw\Workflow\WorkflowStore;
use Testo\Assert;
use Testo\Test;
use Tests\Support\RecordingRunFrontend;
use Tests\Support\ScriptedAgent;

/**
 * End-to-end of the run engine, headless — no real model, no HTTP server. A solver workflow is placed
 * on disk so {@see IssueRunner} REUSES it (skipping AI generation), and the solver does real work
 * (records an artifact) WITHOUT any ai() call — so the {@see ScriptedAgent} is never asked. That keeps
 * the test deterministic while exercising the true pipeline: env wiring, the run ledger, the issue
 * lifecycle, trace persistence, and resume.
 */
final class IssueRunnerTest
{
    #[Test]
    public function reusedSolverRunsTheIssueToDone(): void
    {
        $projectsDir = self::tempDir();
        $projectFolder = self::tempDir();

        try {
            $store = self::registerProject($projectsDir, $projectFolder);
            $issue = $store->addIssue('Add a greeting', 'Print hello');
            self::placeSolver($projectsDir, $store->project()->id, self::solverName($issue->id));

            $agent = new ScriptedAgent();   // no outcomes: a single send() would throw, proving AI is untouched
            $frontend = new RecordingRunFrontend();
            $runner = new IssueRunner($projectsDir, $store, self::config($projectsDir), $agent, $frontend);

            $exit = $runner->run($issue);

            Assert::same($exit, 0);
            Assert::same($store->loadIssue($issue->id)->status, IssueStatus::Done);
            Assert::same($agent->requests, []);   // the happy path never reaches the model

            $runs = $store->recentRuns();
            Assert::same(\count($runs), 1);
            Assert::same($runs[0]['status'], RunStatus::Done->value);

            // The solver's artifact landed in the durable trace under this run.
            $artifacts = new TraceReader($store->pdo())->artifactRecords($runs[0]['id']);
            Assert::same(\count($artifacts), 1);
            Assert::same($artifacts[0]['name'], 'result');
            Assert::true(str_contains($artifacts[0]['body'], 'applied the fix'));

            Assert::true($frontend->reported('Reusing solver'));
            Assert::true($frontend->reported('finished'));
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($projectFolder);
        }
    }

    #[Test]
    public function resumesAnInterruptedRunReusingItsRunId(): void
    {
        $projectsDir = self::tempDir();
        $projectFolder = self::tempDir();

        try {
            $store = self::registerProject($projectsDir, $projectFolder);
            $issue = $store->addIssue('Add a greeting', 'Print hello');
            $solverName = self::solverName($issue->id);
            self::placeSolver($projectsDir, $store->project()->id, $solverName);

            // Simulate a process killed mid-run: a 'running' row left in the ledger for this solver.
            $interruptedRunId = $store->recordRun($issue->id, $solverName, RunStatus::Running);

            $frontend = new RecordingRunFrontend();
            $runner = new IssueRunner($projectsDir, $store, self::config($projectsDir), new ScriptedAgent(), $frontend);

            $exit = $runner->run($issue);

            Assert::same($exit, 0);
            Assert::true($frontend->reported("Resuming run #{$interruptedRunId}"));

            // The interrupted run was reused, not duplicated, and is now Done.
            $runs = $store->recentRuns();
            Assert::same(\count($runs), 1);
            Assert::same($runs[0]['id'], $interruptedRunId);
            Assert::same($runs[0]['status'], RunStatus::Done->value);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($projectFolder);
        }
    }

    private static function registerProject(string $projectsDir, string $projectFolder): ProjectStore
    {
        ProjectStore::init($projectsDir, $projectFolder);

        return ProjectStore::discover($projectsDir, $projectFolder)
            ?? throw new \RuntimeException('the project just registered was not discoverable');
    }

    /** The solver class name {@see IssueRunner} derives from an issue id — mirrored here. */
    private static function solverName(string $issueId): string
    {
        return 'Issue' . preg_replace('/[^A-Za-z0-9]/', '', $issueId) . 'Solver';
    }

    /** Write a trivial, AI-free solver to the same Common folder the runner reuses from. */
    private static function placeSolver(string $projectsDir, string $projectId, string $solverName): void
    {
        $workflows = new WorkflowStore($projectsDir . '/' . $projectId . '-workflows', $projectId);
        $workflows->write($solverName, self::solverCode($solverName), true);
    }

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
                public function name(): string
                {
                    return 'test-solver';
                }

                #[Step]
                public function implement(): void
                {
                    \$this->artifact('result', text: 'applied the fix');
                }
            }

            PHP;
    }

    private static function config(string $projectsDir): Config
    {
        $envFile = $projectsDir . '/test.env';
        file_put_contents($envFile, "CLAW_AGENT=claude\nANTHROPIC_API_KEY=k\nCLAW_MODEL=test-model\n");

        return Config::load($envFile);
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-run-' . uniqid('', true);
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
