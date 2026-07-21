<?php

declare(strict_types=1);

namespace Tests\Run;

use Claw\Agent\AgentResponse;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\Usage;
use Claw\Config;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
use Claw\Project\RunStatus;
use Claw\Project\Strategy;
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

    /**
     * A run that stopped ON PURPOSE is not broken code, and must not be "repaired".
     *
     * The repair boundary excluded only backend failures, so every deliberate halt — the budget running
     * out, the supervisor answering `stop`, a step exhausting its review rounds with nobody to escalate
     * to — arrived looking exactly like a crash. The supervisor was then sent to rewrite the class it had
     * itself just stopped, and the cheapest way to satisfy "fix the cause of: run stopped at step
     * 'verify' by the supervisor" is to delete the critic that stopped it. A budget stop was worse: the
     * repair's own model call hit the same spent budget, so the ticket came back reading "the solver
     * crashed and could not be repaired: run stopped: budget spent".
     */
    #[Test]
    public function aDeliberateStopIsNotTreatedAsBrokenCodeAndSoIsNotRewritten(): void
    {
        $projectsDir = self::tempDir();
        $projectFolder = self::tempDir();

        try {
            $store = self::registerProject($projectsDir, $projectFolder);

            // See the note above: a solver class name is per-process, so burn ids to get one this owns.
            for ($i = 0; $i < 20; $i++) {
                $store->addIssue("filler {$i}");
            }
            $issue = $store->addIssue('a task whose run is stopped on purpose');

            $workflows = new WorkflowStore($projectsDir . '/' . $store->project()->id . '-workflows', $store->project()->id);
            $solver = self::solverName($issue->id);
            $workflows->write($solver, self::stoppingSolverCode($solver), true);

            $frontend = new RecordingRunFrontend();
            $runner = new IssueRunner($projectsDir, $store, self::config($projectsDir), new ScriptedAgent(), $frontend);

            Assert::same($runner->run($issue), 1);

            // The supervisor writes its rewrite as <solver>R1. It must not exist.
            Assert::false(is_file($workflows->path($solver . 'R1', true)));
            Assert::false($frontend->reported('repairing'));
            Assert::same($store->loadIssue($issue->id)->status, IssueStatus::Open);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($projectFolder);
        }
    }

    #[Test]
    public function theDirectPathNeedsAVerdictItCannotGiveItself(): void
    {
        // The worker used to end its own run by calling `done`, and every false completion this project
        // paid for came through that lever. It is gone: the worker works, and a separate pass decides —
        // against the project — whether the ticket is solved. Here that pass says it is not, so nothing
        // is closed and the ticket goes back to the ProjectManager.
        $projectsDir = self::tempDir();
        $projectFolder = self::tempDir();

        try {
            $store = self::registerProject($projectsDir, $projectFolder);
            $issue = $store->addIssue('a ticket the worker will claim to have solved');
            $store->setStrategy($issue->id, Strategy::Direct, 'small', false);

            // Two exchanges: the worker announces victory, then the judge finds nothing to back it up.
            $agent = new ScriptedAgent(
                self::says('Done — I made the change and it all looks right.'),
                self::says('The file the ticket names is unchanged and no test was run.'),
            );
            $frontend = new RecordingRunFrontend();
            $runner = new IssueRunner($projectsDir, $store, self::config($projectsDir), $agent, $frontend);

            Assert::same($runner->run($issue), 1);

            // A worker's own say-so closes nothing.
            Assert::same($store->loadIssue($issue->id)->status, IssueStatus::Open);
            Assert::same($store->recentRuns()[0]['status'], RunStatus::Failed->value);

            // And the reason travelling back is the judge's, not a generic failure.
            $attempts = $store->strategyAttempts($issue->id);
            Assert::true(str_contains($attempts[0]['outcomeReason'], 'no test was run'));
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($projectFolder);
        }
    }

    #[Test]
    public function theDirectPathClosesTheTicketWhenTheVerdictIsSolved(): void
    {
        $projectsDir = self::tempDir();
        $projectFolder = self::tempDir();

        try {
            $store = self::registerProject($projectsDir, $projectFolder);
            $issue = $store->addIssue('a ticket that really does get solved');
            $store->setStrategy($issue->id, Strategy::Direct, 'small', false);

            // The verdict is the FIRST LINE and nothing else; the detail belongs on the lines after it.
            $agent = new ScriptedAgent(
                self::says('Changed src/Thing.php and ran the tests.'),
                self::says("SOLVED\nI read src/Thing.php and ran the suite; it is green."),
            );
            $runner = new IssueRunner($projectsDir, $store, self::config($projectsDir), $agent, new RecordingRunFrontend());

            Assert::same($runner->run($issue), 0);
            Assert::same($store->loadIssue($issue->id)->status, IssueStatus::Done);
            Assert::same($store->recentRuns()[0]['status'], RunStatus::Done->value);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($projectFolder);
        }
    }

    /**
     * The verdict a PREFIX test read as success. `str_starts_with(strtoupper($answer), 'SOLVED')` closed
     * the ticket on this sentence, which says in its own words that the work is not done — the false-Done
     * family reappearing not through a tool or a gate, but through the way an answer was parsed.
     */
    #[Test]
    public function aVerdictThatMerelyOpensWithSolvedDoesNotCloseTheTicket(): void
    {
        $projectsDir = self::tempDir();
        $projectFolder = self::tempDir();

        try {
            $store = self::registerProject($projectsDir, $projectFolder);
            $issue = $store->addIssue('a ticket that is only half solved');
            $store->setStrategy($issue->id, Strategy::Direct, 'small', false);

            $agent = new ScriptedAgent(
                self::says('Changed src/Thing.php.'),
                self::says('Solved for the happy path, but the null case still fails.'),
                self::says('no verdict recorded'),   // the re-triage that follows the failure
            );
            $runner = new IssueRunner($projectsDir, $store, self::config($projectsDir), $agent, new RecordingRunFrontend());

            Assert::same($runner->run($issue), 1);
            Assert::same($store->loadIssue($issue->id)->status, IssueStatus::Open);

            // The judge's own words travel back, so the next attempt knows what was missing.
            $attempts = $store->strategyAttempts($issue->id);
            Assert::true(str_contains($attempts[0]['outcomeReason'], 'the null case still fails'));
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($projectFolder);
        }
    }

    /**
     * The other half of the same defect. The prompt used to print the token in backticks, so a judge that
     * copied the formatting failed the prefix test — and its PASSING verdict was handed to the
     * ProjectManager as the reason the run had failed, escalating a finished ticket to a costlier strategy.
     */
    #[Test]
    public function aDecoratedSolvedIsStillSolved(): void
    {
        $projectsDir = self::tempDir();
        $projectFolder = self::tempDir();

        try {
            $store = self::registerProject($projectsDir, $projectFolder);
            $issue = $store->addIssue('a ticket whose judge likes backticks');
            $store->setStrategy($issue->id, Strategy::Direct, 'small', false);

            $agent = new ScriptedAgent(
                self::says('Changed src/Thing.php.'),
                self::says("`SOLVED`\nI ran the suite and read the output."),
            );
            $runner = new IssueRunner($projectsDir, $store, self::config($projectsDir), $agent, new RecordingRunFrontend());

            Assert::same($runner->run($issue), 0);
            Assert::same($store->loadIssue($issue->id)->status, IssueStatus::Done);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($projectFolder);
        }
    }

    /** A model turn that is plain text and calls nothing. */
    private static function says(string $text): AgentResponse
    {
        return new AgentResponse([new TextBlock($text)], [], StopReason::EndTurn, new Usage(1, 1), $text);
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

    /** A solver whose step halts the run deliberately — the shape a budget or supervisor stop takes. */
    private static function stoppingSolverCode(string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace ClawWorkflow\\Common;

            use Claw\\Exceptions\\WorkflowException;
            use Claw\\Workflow\\Step;
            use Claw\\Workflow\\WorkflowAbstract;

            final class {$class} extends WorkflowAbstract
            {
                public function name(): string
                {
                    return 'stopping-solver';
                }

                #[Step]
                public function implement(): void
                {
                    throw WorkflowException::stopped('run stopped: budget spent');
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
