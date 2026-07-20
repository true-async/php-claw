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
    public function aSubIssueRecordsItsParentAndDerivesItsDepth(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $store = self::openProject($projectsDir, $folder);

            $root = $store->addIssue('big thing');
            $child = $store->addIssue('part one', '', $root->id);
            $grandchild = $store->addIssue('part one, bit a', '', $child->id);

            Assert::same($root->parent, null);
            Assert::same($root->depth, 0);
            Assert::same($child->parent, $root->id);
            Assert::same($child->depth, 1);
            Assert::same($grandchild->depth, 2);   // derived from the parent, not supplied by the caller

            // and it round-trips through the db, not just the returned object
            Assert::same($store->loadIssue($grandchild->id)->depth, 2);
            Assert::same($store->loadIssue($grandchild->id)->parent, $child->id);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function childIssuesReturnsOnlyTheDirectChildren(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $store = self::openProject($projectsDir, $folder);

            $root = $store->addIssue('root');
            $first = $store->addIssue('first', '', $root->id);
            $second = $store->addIssue('second', '', $root->id);
            $store->addIssue('nested', '', $first->id);   // a grandchild, not a child
            $store->addIssue('unrelated');

            $children = $store->childIssues($root->id);

            Assert::count($children, 2);
            Assert::same($children[0]->id, $first->id);
            Assert::same($children[1]->id, $second->id);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function aParentIsSettledOnlyOnceEveryChildHasLanded(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $store = self::openProject($projectsDir, $folder);

            $root = $store->addIssue('root');
            $first = $store->addIssue('first', '', $root->id);
            $second = $store->addIssue('second', '', $root->id);

            // One child done, one still open: the parent must stay open.
            $store->setIssueStatus($first->id, IssueStatus::Done);
            $store->settleAncestors($first->id);
            Assert::same($store->loadIssue($root->id)->status, IssueStatus::Open);

            // The last child lands, so the parent closes with it.
            $store->setIssueStatus($second->id, IssueStatus::Done);
            $store->settleAncestors($second->id);
            Assert::same($store->loadIssue($root->id)->status, IssueStatus::Done);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function settlingWalksUpTheWholeChainButNeverReopensAHumanDecision(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $store = self::openProject($projectsDir, $folder);

            $root = $store->addIssue('root');
            $middle = $store->addIssue('middle', '', $root->id);
            $leaf = $store->addIssue('leaf', '', $middle->id);

            $store->setIssueStatus($leaf->id, IssueStatus::Done);
            $store->settleAncestors($leaf->id);

            Assert::same($store->loadIssue($middle->id)->status, IssueStatus::Done);   // one level up
            Assert::same($store->loadIssue($root->id)->status, IssueStatus::Done);     // and the next

            // A parent a human already Closed is left alone: a finishing child must not reopen that call.
            $closed = $store->addIssue('closed parent');
            $under = $store->addIssue('under it', '', $closed->id);
            $store->setIssueStatus($closed->id, IssueStatus::Closed);
            $store->setIssueStatus($under->id, IssueStatus::Done);
            $store->settleAncestors($under->id);

            Assert::same($store->loadIssue($closed->id)->status, IssueStatus::Closed);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    #[Test]
    public function openingADbFromBeforeTheTreeColumnsMigratesItInPlace(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $store = self::openProject($projectsDir, $folder);
            $key = $store->project()->id;

            // Rebuild `issues` in the pre-tree shape, with a row in it — exactly what an already
            // registered project looks like on upgrade. CREATE TABLE IF NOT EXISTS would skip such a
            // table forever, so without the ALTER every read of parent_id/depth would fail.
            $pdo = $store->pdo();
            $pdo->exec('DROP TABLE issues');
            $pdo->exec(
                "CREATE TABLE issues (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    title       TEXT NOT NULL,
                    description TEXT NOT NULL DEFAULT '',
                    status      TEXT NOT NULL,
                    created_at  INTEGER NOT NULL
                )",
            );
            $pdo->exec(
                "INSERT INTO issues (title, description, status, created_at)
                 VALUES ('legacy', 'from before the tree', 'Open', 1)",
            );

            $reopened = ProjectStore::openByKey($projectsDir, $key);
            Assert::true($reopened !== null);

            $legacy = $reopened->loadIssue('1');
            Assert::same($legacy->title, 'legacy');
            Assert::same($legacy->parent, null);   // an existing issue reads as a root
            Assert::same($legacy->depth, 0);

            // and the migrated table takes new sub-issues
            Assert::same($reopened->addIssue('new child', '', '1')->depth, 1);
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
