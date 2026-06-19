<?php

declare(strict_types=1);

namespace Claw\Project;

use Claw\Exceptions\ClawException;

/**
 * The application's registry of projects, kept inside the app's own home folder.
 *
 * A "project" is an EXTERNAL working tree (a folder, possibly a git repository) that
 * lives somewhere else on disk; this store never creates or touches that folder. What
 * it owns is the application-side state for it: one SQLite file = one project, the
 * same convention {@see \Claw\Store\SessionStore} uses for conversations — one writer,
 * no lock contention.
 *
 * The database is keyed by the project folder's absolute path, so the same folder
 * always maps back to the same file (the way `.claude` keys its per-project data by
 * path). That state db is the project's home for its metadata now, and for its issues
 * and durable workflow-run snapshots later.
 */
final class ProjectStore
{
    public function __construct(private readonly string $projectsDir)
    {
    }

    /**
     * Initialize the application's state database for an existing project folder. The
     * folder must already exist — it is the external working tree, not ours to make.
     * Returns the new Project; throws if it is already initialized.
     *
     * @throws ClawException
     */
    public function init(string $projectPath): Project
    {
        $abs = realpath($projectPath);
        if ($abs === false || !is_dir($abs)) {
            throw new ClawException("project folder does not exist: {$projectPath}");
        }

        if (!is_dir($this->projectsDir) && !mkdir($this->projectsDir, 0o775, true) && !is_dir($this->projectsDir)) {
            throw new ClawException("cannot create projects directory: {$this->projectsDir}");
        }

        $id = self::keyFor($abs);
        $dbPath = $this->dbPath($id);
        if (is_file($dbPath)) {
            throw new ClawException("project already initialized: {$abs} ({$dbPath})");
        }

        $name = basename($abs);
        try {
            $pdo = $this->open($dbPath);
            $this->ensureSchema($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO project (id, name, path, description, created_at)
                 VALUES (:id, :name, :path, :description, :created_at)',
            );
            $stmt->execute([
                'id' => $id,
                'name' => $name,
                'path' => $abs,
                'description' => '',
                'created_at' => time(),
            ]);
        } catch (\PDOException $e) {
            // Don't leave a half-written db behind if the schema/insert failed.
            @unlink($dbPath);

            throw new ClawException("ProjectStore: cannot create {$dbPath}: " . $e->getMessage(), 0, $e);
        }

        return new Project($id, $name, $abs);
    }

    /**
     * Open a new issue in an already-initialized project and return it. The issue
     * lives in that project's own state db; its id is assigned by the store (the db
     * owns identity), so the caller never fabricates one.
     *
     * @throws ClawException
     */
    public function addIssue(string $projectPath, string $title, string $description = ''): Issue
    {
        $title = trim($title);
        if ($title === '') {
            throw new ClawException('issue title must not be empty');
        }

        $abs = realpath($projectPath);
        if ($abs === false || !is_dir($abs)) {
            throw new ClawException("project folder does not exist: {$projectPath}");
        }

        $projectId = self::keyFor($abs);
        $dbPath = $this->dbPath($projectId);
        if (!is_file($dbPath)) {
            throw new ClawException("project not initialized: {$abs} (run: claw -c)");
        }

        try {
            $pdo = $this->open($dbPath);
            $this->ensureSchema($pdo);   // older project dbs may predate the issues table
            $stmt = $pdo->prepare(
                'INSERT INTO issues (title, description, status, created_at)
                 VALUES (:title, :description, :status, :created_at)',
            );
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'status' => IssueStatus::Open->name,
                'created_at' => time(),
            ]);
            $issueId = (string) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            throw new ClawException("ProjectStore: cannot add issue to {$dbPath}: " . $e->getMessage(), 0, $e);
        }

        return new Issue($issueId, $projectId, $title, $description);
    }

    /** Load a project's metadata from its state db. @throws ClawException */
    public function load(string $projectPath): Project
    {
        [$pdo, $id] = $this->connect($projectPath);
        $stmt = $pdo->prepare('SELECT id, name, path, description FROM project LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            throw new ClawException("project has no metadata: {$id}");
        }

        return new Project((string) $row['id'], (string) $row['name'], (string) $row['path'], (string) $row['description']);
    }

    /** Load one issue (with the ids of the runs spawned for it). @throws ClawException */
    public function loadIssue(string $projectPath, string $issueId): Issue
    {
        [$pdo, $projectId] = $this->connect($projectPath);
        $stmt = $pdo->prepare('SELECT title, description, status FROM issues WHERE id = :id');
        $stmt->execute(['id' => $issueId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            throw new ClawException("issue #{$issueId} not found in project {$projectId}");
        }

        $rs = $pdo->prepare('SELECT id FROM runs WHERE issue_id = :id ORDER BY id');
        $rs->execute(['id' => $issueId]);
        $runs = array_values(array_map(static fn (mixed $r): string => (string) $r, $rs->fetchAll(\PDO::FETCH_COLUMN)));

        return new Issue(
            (string) $issueId,
            $projectId,
            (string) $row['title'],
            (string) $row['description'],
            IssueStatus::fromName((string) $row['status']),
            $runs,
        );
    }

    /** Record a run spawned for an issue and return its store-assigned id. @throws ClawException */
    public function recordRun(string $projectPath, string $issueId, string $workflow, string $status = 'running'): string
    {
        [$pdo] = $this->connect($projectPath);
        $stmt = $pdo->prepare(
            'INSERT INTO runs (issue_id, workflow, status, created_at)
             VALUES (:issue, :workflow, :status, :created_at)',
        );
        $stmt->execute(['issue' => $issueId, 'workflow' => $workflow, 'status' => $status, 'created_at' => time()]);

        return (string) $pdo->lastInsertId();
    }

    /** @throws ClawException */
    public function setRunStatus(string $projectPath, string $runId, string $status): void
    {
        [$pdo] = $this->connect($projectPath);
        $stmt = $pdo->prepare('UPDATE runs SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $runId]);
    }

    /** @throws ClawException */
    public function setIssueStatus(string $projectPath, string $issueId, IssueStatus $status): void
    {
        [$pdo] = $this->connect($projectPath);
        $stmt = $pdo->prepare('UPDATE issues SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status->name, 'id' => $issueId]);
    }

    /** The state-db path for a project id, whether or not it exists yet. */
    public function dbPath(string $id): string
    {
        return $this->projectsDir . '/' . $id . '.db';
    }

    /** A filesystem-safe, stable key derived from a folder's absolute path. */
    public static function keyFor(string $absolutePath): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $absolutePath), '-');
    }

    /**
     * Open an already-initialized project's db and return it with the project id.
     *
     * @return array{0: \PDO, 1: string}
     *
     * @throws ClawException
     */
    private function connect(string $projectPath): array
    {
        $abs = realpath($projectPath);
        if ($abs === false || !is_dir($abs)) {
            throw new ClawException("project folder does not exist: {$projectPath}");
        }

        $id = self::keyFor($abs);
        $dbPath = $this->dbPath($id);
        if (!is_file($dbPath)) {
            throw new ClawException("project not initialized: {$abs} (run: claw -c)");
        }

        $pdo = $this->open($dbPath);
        $this->ensureSchema($pdo);

        return [$pdo, $id];
    }

    private function open(string $dbPath): \PDO
    {
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /** The project db's tables, created on demand. Idempotent ({@see addIssue}). */
    private function ensureSchema(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS project (
                id          TEXT PRIMARY KEY,
                name        TEXT NOT NULL,
                path        TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                created_at  INTEGER NOT NULL
            )",
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS issues (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                title       TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                status      TEXT NOT NULL,
                created_at  INTEGER NOT NULL
            )",
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS runs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                issue_id    INTEGER NOT NULL,
                workflow    TEXT NOT NULL,
                status      TEXT NOT NULL,
                created_at  INTEGER NOT NULL
            )',
        );
    }
}
