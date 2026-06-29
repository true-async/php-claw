<?php

declare(strict_types=1);

namespace Tests\Project;

use Claw\Exceptions\ClawException;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
use Claw\Project\RunStatus;
use Testo\Assert;
use Testo\Test;

final class ProjectStoreTest
{
    #[Test]
    public function initCreatesAStateDbKeyedByTheProjectPath(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $project = ProjectStore::init($projectsDir, $folder);

            Assert::same($project->path, realpath($folder));
            Assert::same($project->name, basename($folder));
            Assert::same($project->id, ProjectStore::keyFor((string) realpath($folder)));
            Assert::true(is_file($projectsDir . '/' . $project->id . '.db'));
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function initRejectsADoubleInit(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            ProjectStore::init($projectsDir, $folder);

            $threw = false;

            try {
                ProjectStore::init($projectsDir, $folder);
            } catch (ClawException $e) {
                $threw = str_contains($e->getMessage(), 'already initialized');
            }

            Assert::true($threw);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function initRejectsAMissingFolder(): void
    {
        $projectsDir = self::tempDir();

        try {
            $threw = false;

            try {
                ProjectStore::init($projectsDir, $projectsDir . '/does-not-exist');
            } catch (ClawException $e) {
                $threw = str_contains($e->getMessage(), 'does not exist');
            }

            Assert::true($threw);
        } finally {
            self::rmrf($projectsDir);
        }
    }

    #[Test]
    public function discoverWalksUpToTheNearestProjectRoot(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $project = ProjectStore::init($projectsDir, $folder);

            $sub = $folder . '/a/b/c';
            mkdir($sub, 0o775, true);

            $store = ProjectStore::discover($projectsDir, $sub);   // started deep inside the tree

            if (!$store instanceof ProjectStore) {
                throw new \RuntimeException('discover() returned null for a subdirectory of a project');
            }

            Assert::same($store->project()->id, $project->id);
            Assert::same($store->project()->path, realpath($folder));
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function discoverReturnsNullOutsideAnyProject(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();   // never initialized

        try {
            Assert::null(ProjectStore::discover($projectsDir, $folder));
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function addIssueOpensAnIssueInTheProjectDb(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $store = self::openProject($projectsDir, $folder);

            $issue = $store->addIssue('  Fix the login bug  ', 'steps to repro');

            Assert::same($issue->title, 'Fix the login bug');   // trimmed
            Assert::same($issue->project, $store->project()->id);
            Assert::same($issue->status, IssueStatus::Open);
            Assert::same($issue->id, '1');   // store-assigned id

            $pdo = new \PDO('sqlite:' . $projectsDir . '/' . $store->project()->id . '.db');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $stmt = $pdo->query('SELECT title, description, status FROM issues WHERE id = 1');

            if ($stmt === false) {
                throw new \RuntimeException('issues query failed');
            }
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            Assert::same($row['title'], 'Fix the login bug');
            Assert::same($row['description'], 'steps to repro');
            Assert::same($row['status'], 'Open');
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function addIssueRejectsAnEmptyTitle(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $store = self::openProject($projectsDir, $folder);

            $threw = false;

            try {
                $store->addIssue('   ');
            } catch (ClawException $e) {
                $threw = str_contains($e->getMessage(), 'title must not be empty');
            }

            Assert::true($threw);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function resumableRunFindsOnlyAnInterruptedRun(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $store = self::openProject($projectsDir, $folder);
            $runId = $store->recordRun('1', 'Issue1Solver');   // status 'running'

            Assert::same($store->resumableRun('1', 'Issue1Solver'), $runId);
            Assert::null($store->resumableRun('1', 'OtherSolver'));   // different workflow

            $store->setRunStatus($runId, RunStatus::Done);
            Assert::null($store->resumableRun('1', 'Issue1Solver'));   // finished -> not resumable
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function recentRunsListsTheLedgerNewestFirst(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $store = self::openProject($projectsDir, $folder);
            $store->recordRun('7', 'Issue7Solver');
            $second = $store->recordRun('7', 'Issue7Solver');
            $store->setRunStatus($second, RunStatus::Failed);

            $runs = $store->recentRuns();

            Assert::count($runs, 2);
            Assert::same($runs[0]['id'], $second);        // newest first
            Assert::same($runs[0]['status'], 'failed');
            Assert::same($runs[1]['issue'], '7');
            Assert::same($runs[1]['workflow'], 'Issue7Solver');
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function onePooledHandleIsSafeAcrossConcurrentCoroutines(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $store = self::openProject($projectsDir, $folder);

            // One shared handle, sixteen coroutines writing at once. TrueAsync's PDO pool hands each
            // coroutine its own connection, so the INSERT + lastInsertId() inside addIssue()/recordRun()
            // must stay correct under interleaving — this is the guarantee that retired the per-run
            // `storeFor()` handle. The delay forces a yield between the two inserts to stress it.
            $coros = [];

            for ($i = 0; $i < 16; $i++) {
                $coros[] = \Async\spawn(static function () use ($store, $i): array {
                    $issue = $store->addIssue("issue {$i}", "body {$i}");
                    \Async\delay(1);
                    $runId = $store->recordRun($issue->id, "Solver{$i}");

                    return ['title' => "issue {$i}", 'issueId' => $issue->id, 'runId' => $runId];
                });
            }

            $results = array_map(static fn ($c): array => \Async\await($c), $coros);

            $issueIds = array_map(static fn (array $r): string => $r['issueId'], $results);
            $runIds = array_map(static fn (array $r): string => $r['runId'], $results);

            // distinct ids: lastInsertId() never returned another coroutine's row
            Assert::same(count(array_unique($issueIds)), 16);
            Assert::same(count(array_unique($runIds)), 16);

            // each id resolves back to exactly that coroutine's own title (no cross-wiring)
            foreach ($results as $r) {
                Assert::same($store->loadIssue($r['issueId'])->title, $r['title']);
            }

            Assert::count($store->allIssues(), 16);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    /** Register a project and return an open handle to it (init + discover). */
    private static function openProject(string $projectsDir, string $folder): ProjectStore
    {
        ProjectStore::init($projectsDir, $folder);
        $store = ProjectStore::discover($projectsDir, $folder);

        if (!$store instanceof ProjectStore) {
            throw new \RuntimeException('discover() returned null for a just-initialized project');
        }

        return $store;
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-project-' . uniqid('', true);
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
