<?php

declare(strict_types=1);

namespace Tests\Project;

use Claw\Exceptions\ClawException;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
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
            $project = (new ProjectStore($projectsDir))->init($folder);

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
        $store = new ProjectStore($projectsDir);

        try {
            $store->init($folder);

            $threw = false;
            try {
                $store->init($folder);
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
                (new ProjectStore($projectsDir))->init($projectsDir . '/does-not-exist');
            } catch (ClawException $e) {
                $threw = str_contains($e->getMessage(), 'does not exist');
            }

            Assert::true($threw);
        } finally {
            self::rmrf($projectsDir);
        }
    }

    #[Test]
    public function addIssueOpensAnIssueInTheProjectDb(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();
        $store = new ProjectStore($projectsDir);

        try {
            $project = $store->init($folder);

            $issue = $store->addIssue($folder, '  Fix the login bug  ', 'steps to repro');

            Assert::same($issue->title, 'Fix the login bug');   // trimmed
            Assert::same($issue->project, $project->id);
            Assert::same($issue->status, IssueStatus::Open);
            Assert::same($issue->id, '1');   // store-assigned id

            $pdo = new \PDO('sqlite:' . $projectsDir . '/' . $project->id . '.db');
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
    public function addIssueRejectsAnUninitializedProject(): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            $threw = false;
            try {
                (new ProjectStore($projectsDir))->addIssue($folder, 'orphan issue');
            } catch (ClawException $e) {
                $threw = str_contains($e->getMessage(), 'not initialized');
            }

            Assert::true($threw);
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
        $store = new ProjectStore($projectsDir);

        try {
            $store->init($folder);

            $threw = false;
            try {
                $store->addIssue($folder, '   ');
            } catch (ClawException $e) {
                $threw = str_contains($e->getMessage(), 'title must not be empty');
            }

            Assert::true($threw);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
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
