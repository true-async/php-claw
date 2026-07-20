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

            // The solver's artifact landed in the durable trace under this run. The listing carries
            // metadata only — the board holds it for every issue, so bodies are fetched per artifact.
            $reader = new TraceReader($store->pdo());
            $artifacts = $reader->artifactRecords($runs[0]['id']);
            Assert::same(\count($artifacts), 1);
            Assert::same($artifacts[0]['name'], 'result');
            Assert::false(\array_key_exists('body', $artifacts[0]));
            Assert::true($artifacts[0]['size'] > 0);

            $body = $reader->artifactBody($runs[0]['id'], $artifacts[0]['n']);
            Assert::true($body !== null && str_contains($body, 'applied the fix'));
            Assert::null($reader->artifactBody($runs[0]['id'], 99));   // no such artifact

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

    #[Test]
    public function aFailedRunNeverLeavesTheIssueClaimingToBeInProgress(): void
    {
        // A run sets the issue InProgress at the start. When it fails, something has to take that
        // back — otherwise the board shows work that is not happening and nothing later moves it.
        //
        // The path that broke: the reset sat behind an early return that fires when no strategy is in
        // force. A SECOND failure hits exactly that (the first already marked the strategy failed), so
        // the ticket stuck at InProgress with no live run and no way out. Seen on a real board.
        $projectsDir = self::tempDir();
        $projectFolder = self::tempDir();

        try {
            $store = self::registerProject($projectsDir, $projectFolder);

            // A solver class is loaded ONCE per process, and its name comes from the issue id — so an
            // id another test already used would silently reuse THAT test's class instead of this
            // one's. Burn a few ids so this test gets a class name of its own.
            for ($i = 0; $i < 5; $i++) {
                $store->addIssue("filler {$i}");
            }
            $issue = $store->addIssue('a task that will not work');

            // A solver that throws on its only step: the run fails for a real reason, not a missing file.
            $workflows = new WorkflowStore($projectsDir . '/' . $store->project()->id . '-workflows', $store->project()->id);
            $solver = self::solverName($issue->id);
            $workflows->write($solver, self::throwingSolverCode($solver), true);

            $runner = new IssueRunner($projectsDir, $store, self::config($projectsDir), new ScriptedAgent(), new RecordingRunFrontend());

            // Twice: the second is the case that used to strand the ticket, because by then the
            // strategy has already been marked failed and there is nothing left to fail.
            Assert::same($runner->run($issue), 1);
            Assert::same($store->loadIssue($issue->id)->status, IssueStatus::Open);   // handed back, not stuck

            Assert::same($runner->run($store->loadIssue($issue->id)), 1);
            Assert::same($store->loadIssue($issue->id)->status, IssueStatus::Open);

            // and both runs are recorded as failed, so the ledger tells the same story as the board
            foreach ($store->recentRuns() as $run) {
                Assert::same($run['status'], RunStatus::Failed->value);
            }
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($projectFolder);
        }
    }

    #[Test]
    public function aBackendFailureIsNotTreatedAsBrokenCodeAndSoIsNotRewritten(): void
    {
        // Repair answers one question — is this workflow's code broken? A refusal from the model
        // backend is not that, and rewriting the class over it spends the supervisor on a defect that
        // is not there. Measured live: a 400 on a malformed history had the supervisor invent a
        // replacement for a workflow whose source it could not even read.
        $projectsDir = self::tempDir();
        $projectFolder = self::tempDir();

        try {
            $store = self::registerProject($projectsDir, $projectFolder);

            // See the note in the test above: a solver class name is per-process, so burn ids to get
            // one this test owns.
            for ($i = 0; $i < 12; $i++) {
                $store->addIssue("filler {$i}");
            }
            $issue = $store->addIssue('a task the model backend will refuse');

            $workflows = new WorkflowStore($projectsDir . '/' . $store->project()->id . '-workflows', $store->project()->id);
            $solver = self::solverName($issue->id);
            $workflows->write($solver, self::backendFailingSolverCode($solver), true);

            $frontend = new RecordingRunFrontend();
            $runner = new IssueRunner($projectsDir, $store, self::config($projectsDir), new ScriptedAgent(), $frontend);

            Assert::same($runner->run($issue), 1);

            // No repair was attempted: the supervisor writes its rewrite as <solver>R1, and that file
            // must not exist. This is the assertion the whole test is for.
            Assert::false(is_file($workflows->path($solver . 'R1', true)));

            // And the run failed honestly rather than being reported as unrepairable.
            Assert::true($frontend->reported('failed'));
            Assert::false($frontend->reported('repairing'));
            Assert::same($store->loadIssue($issue->id)->status, IssueStatus::Open);   // handed back, not stuck
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($projectFolder);
        }
    }

    /** A solver whose step fails the way a refusing model backend does — not the way broken code does. */
    private static function backendFailingSolverCode(string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace ClawWorkflow\\Common;

            use Claw\\Exceptions\\BadRequestException;
            use Claw\\Workflow\\Step;
            use Claw\\Workflow\\WorkflowAbstract;

            final class {$class} extends WorkflowAbstract
            {
                public function name(): string
                {
                    return 'backend-failing-solver';
                }

                #[Step]
                public function implement(): void
                {
                    throw new BadRequestException(
                        "An assistant message with 'tool_calls' must be followed by tool messages",
                    );
                }
            }

            PHP;
    }

    private static function throwingSolverCode(string $class): string
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
                    return 'throwing-solver';
                }

                #[Step]
                public function implement(): void
                {
                    throw new \\RuntimeException('the step could not do the work');
                }
            }

            PHP;
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
