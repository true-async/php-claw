<?php

declare(strict_types=1);

namespace Tests\Run;

use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
use Claw\Project\RunStatus;
use Claw\Run\OrphanVerdict;
use Claw\Trace\Tracer;
use Claw\Trace\TraceReader;
use Claw\Trace\TraceStore;
use Testo\Assert;
use Testo\Test;

/**
 * What happens to a run the ledger still calls Running when the process running it is gone.
 *
 * Written because it was not: the behaviour shipped described and unverified, on the strength of an
 * assertion that the code was right. The three answers are not interchangeable and each is wrong in
 * its own way — settle a waiting run and a person's reply has nowhere to land; resume a finished one
 * and it works for nothing; settle an interrupted one and everything it had already done is paid for
 * twice.
 */
final class OrphanVerdictTest
{
    /** A run at its gate is left alone: the ticket saying a person is expected is TRUE. */
    #[Test]
    public function aRunAtItsGateIsLeftWaiting(): void
    {
        $this->withProject(function (ProjectStore $store, \PDO $pdo): void {
            $issue = $store->addIssue('a task that asked something');
            $runId = $store->recordRun($issue->id, 'Solver');
            $store->setIssueStatus($issue->id, IssueStatus::WaitingHuman);

            $tracer = new Tracer($runId, new TraceStore($pdo));
            $tracer->question('which config key controls the timeout?');

            $verdict = OrphanVerdict::forRun($store, new TraceReader($pdo), $runId, $issue->id);
            Assert::same($verdict, OrphanVerdict::Waiting);
        });
    }

    /**
     * The same run, once the question has been answered, is no longer waiting for anyone — so it is
     * work to be picked back up, not a gate to be preserved.
     */
    #[Test]
    public function anAnsweredGateIsWorkToResumeAgain(): void
    {
        $this->withProject(function (ProjectStore $store, \PDO $pdo): void {
            $issue = $store->addIssue('a task that asked and was told');
            $runId = $store->recordRun($issue->id, 'Solver');

            $tracer = new Tracer($runId, new TraceStore($pdo));
            $questionId = $tracer->question('which config key?');
            $tracer->answer($questionId, 'CLAW_TURN_SECONDS');

            $verdict = OrphanVerdict::forRun($store, new TraceReader($pdo), $runId, $issue->id);
            Assert::same($verdict, OrphanVerdict::Resume);
        });
    }

    /**
     * A run interrupted mid-work is resumed, not written off. Everything it needs is durable — which
     * steps finished, where the step it died in had got to — and a restart is not a reason to throw
     * that away and pay for it a second time.
     */
    #[Test]
    public function aRunInterruptedMidWorkIsResumed(): void
    {
        $this->withProject(function (ProjectStore $store, \PDO $pdo): void {
            $issue = $store->addIssue('a task that was mid-flight');
            $runId = $store->recordRun($issue->id, 'Solver');
            $store->setIssueStatus($issue->id, IssueStatus::InProgress);

            // It ran and traced, but never asked anyone anything.
            $tracer = new Tracer($runId, new TraceStore($pdo));
            $span = $tracer->enterStep('implement');
            $tracer->exit($span);

            $verdict = OrphanVerdict::forRun($store, new TraceReader($pdo), $runId, $issue->id);
            Assert::same($verdict, OrphanVerdict::Resume);
        });
    }

    /** A Running row on a Done ticket is stale bookkeeping — settle it, and do not run anything. */
    #[Test]
    public function aRunningRowOnAFinishedTicketIsJustStale(): void
    {
        $this->withProject(function (ProjectStore $store, \PDO $pdo): void {
            $issue = $store->addIssue('a task that finished');
            $runId = $store->recordRun($issue->id, 'Solver');
            $store->setIssueStatus($issue->id, IssueStatus::Done);

            $verdict = OrphanVerdict::forRun($store, new TraceReader($pdo), $runId, $issue->id);
            Assert::same($verdict, OrphanVerdict::Settle);
        });
    }

    /**
     * The ledger names exactly the runs this judges, and stops naming one once it is settled — the two
     * halves have to agree or the startup pass would either miss orphans or keep finding the same ones.
     */
    #[Test]
    public function theLedgerAndTheVerdictAgreeAboutWhatIsStillRunning(): void
    {
        $this->withProject(function (ProjectStore $store, \PDO $pdo): void {
            $issue = $store->addIssue('a task');
            $runId = $store->recordRun($issue->id, 'Solver');

            Assert::count($store->runningRuns(), 1);
            Assert::same($store->runningRuns()[0]['id'], $runId);

            $store->setRunStatus($runId, RunStatus::Failed);
            Assert::same($store->runningRuns(), []);
        });
    }

    private function withProject(callable $body): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            ProjectStore::init($projectsDir, $folder);
            $store = ProjectStore::discover($projectsDir, $folder)
                ?? throw new \RuntimeException('the project just registered was not discoverable');

            $body($store, $store->pdo());
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-orphan-' . uniqid('', true);
        mkdir($dir, 0o775, true);

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rmrf($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
