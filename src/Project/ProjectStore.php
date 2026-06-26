<?php

declare(strict_types=1);

namespace Claw\Project;

use Claw\Exceptions\ClawException;

/**
 * One opened project's state. A "project" is an EXTERNAL working tree (a folder, possibly a
 * git repository) that lives elsewhere on disk; the application never creates or touches that
 * folder. What it owns is the application-side state for it: one SQLite file = one project,
 * kept inside the app's own home and keyed by the folder's absolute path (the way `.claude`
 * keys its per-project data by path).
 *
 * An instance is a HANDLE to one already-resolved project: it holds the open \PDO and the
 * project's metadata, so a whole command runs against a single connection. The path is
 * resolved and the db opened exactly ONCE — by {@see discover()} or {@see init()} — not on
 * every operation; that same connection ({@see pdo()}) also backs the durable workflow-run
 * state store and the trace store, so a run never reopens its own db.
 */
final class ProjectStore
{
    private function __construct(
        private readonly \PDO $pdo,
        private readonly Project $project,
    ) {
    }

    /**
     * Register an existing project folder: create its state db under $projectsDir and return
     * the project. The folder must already exist — it is the external working tree, not ours
     * to make. Throws if it is already initialized. This is the registry's only write; it
     * returns metadata rather than a handle because `claw -c` just records the project.
     *
     * @throws ClawException
     */
    public static function init(string $projectsDir, string $projectPath): Project
    {
        $abs = realpath($projectPath);
        if ($abs === false || !is_dir($abs)) {
            throw new ClawException("project folder does not exist: {$projectPath}");
        }

        if (!is_dir($projectsDir) && !mkdir($projectsDir, 0o775, true) && !is_dir($projectsDir)) {
            throw new ClawException("cannot create projects directory: {$projectsDir}");
        }

        $id = self::keyFor($abs);
        $dbPath = self::dbPath($projectsDir, $id);
        if (is_file($dbPath)) {
            throw new ClawException("project already initialized: {$abs} ({$dbPath})");
        }

        $name = basename($abs);
        try {
            $pdo = self::open($dbPath);
            self::ensureSchema($pdo);
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
     * Resolve the project that $startDir belongs to by walking up its parent directories to
     * the nearest registered one (the way git finds the repo root from any subdirectory), and
     * open a handle to it. Returns null when no ancestor is a registered project — the caller
     * must treat that as "not inside a project" and never fall back to a default.
     *
     * @throws ClawException on a corrupt/unreadable state db
     */
    public static function discover(string $projectsDir, string $startDir): ?self
    {
        $dir = realpath($startDir);
        if ($dir === false) {
            return null;
        }

        while (true) {
            $dbPath = self::dbPath($projectsDir, self::keyFor($dir));
            if (is_file($dbPath)) {
                return self::openHandle($dbPath);
            }
            $parent = \dirname($dir);
            if ($parent === $dir) {   // reached the filesystem root without a match
                return null;
            }
            $dir = $parent;
        }
    }

    /** This project's metadata (id, name, the external folder path, description). */
    public function project(): Project
    {
        return $this->project;
    }

    /** The single open connection, shared with the run-state store and the tracer. */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Open a new issue in this project and return it. Its id is assigned by the store (the db
     * owns identity), so the caller never fabricates one.
     *
     * @throws ClawException
     */
    public function addIssue(string $title, string $description = ''): Issue
    {
        $title = trim($title);
        if ($title === '') {
            throw new ClawException('issue title must not be empty');
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO issues (title, description, status, created_at)
                 VALUES (:title, :description, :status, :created_at)',
            );
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'status' => IssueStatus::Open->name,
                'created_at' => time(),
            ]);
            $issueId = (string) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            throw new ClawException('ProjectStore: cannot add issue: ' . $e->getMessage(), 0, $e);
        }

        return new Issue($issueId, $this->project->id, $title, $description);
    }

    /** Load one issue (with the ids of the runs spawned for it). @throws ClawException */
    public function loadIssue(string $issueId): Issue
    {
        try {
            $stmt = $this->pdo->prepare('SELECT title, description, status FROM issues WHERE id = :id');
            $stmt->execute(['id' => $issueId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!\is_array($row)) {
                throw new ClawException("issue #{$issueId} not found in project {$this->project->id}");
            }

            $rs = $this->pdo->prepare('SELECT id FROM runs WHERE issue_id = :id ORDER BY id');
            $rs->execute(['id' => $issueId]);
            $runs = array_values(array_map(static fn (mixed $r): string => (string) $r, $rs->fetchAll(\PDO::FETCH_COLUMN)));
        } catch (\PDOException $e) {
            throw new ClawException("ProjectStore: cannot load issue #{$issueId}: " . $e->getMessage(), 0, $e);
        }

        return new Issue(
            $issueId,
            $this->project->id,
            (string) $row['title'],
            (string) $row['description'],
            IssueStatus::fromName((string) $row['status']),
            $runs,
        );
    }

    /** Record a run spawned for an issue and return its store-assigned id. */
    public function recordRun(string $issueId, string $workflow, RunStatus $status = RunStatus::Running): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO runs (issue_id, workflow, status, created_at)
             VALUES (:issue, :workflow, :status, :created_at)',
        );
        $stmt->execute(['issue' => $issueId, 'workflow' => $workflow, 'status' => $status->value, 'created_at' => time()]);

        return (string) $this->pdo->lastInsertId();
    }

    public function setRunStatus(string $runId, RunStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE runs SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status->value, 'id' => $runId]);
    }

    /**
     * Recent runs from the ledger, newest first — for the `claw log` header and for picking one. The
     * `runs` table is this store's own, so its reads live here rather than in the trace reader.
     *
     * @return list<array{id: string, issue: string, workflow: string, status: string}>
     */
    public function recentRuns(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT id, issue_id, workflow, status FROM runs ORDER BY id DESC LIMIT :n');
        $stmt->bindValue('n', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $runs = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $runs[] = [
                'id' => (string) ($row['id'] ?? ''),
                'issue' => (string) ($row['issue_id'] ?? ''),
                'workflow' => (string) ($row['workflow'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
            ];
        }

        return $runs;
    }

    /**
     * The id of an interrupted run (still {@see RunStatus::Running}) for this issue's workflow, newest
     * first, or null — a run only stays Running if the process was killed before it finished or failed,
     * so this is exactly what a re-run should resume.
     */
    public function resumableRun(string $issueId, string $workflow): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM runs WHERE issue_id = :issue AND workflow = :workflow AND status = :status ORDER BY id DESC LIMIT 1',
        );
        $stmt->execute(['issue' => $issueId, 'workflow' => $workflow, 'status' => RunStatus::Running->value]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }

    public function setIssueStatus(string $issueId, IssueStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE issues SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status->name, 'id' => $issueId]);
    }

    /** A filesystem-safe, stable key derived from a folder's absolute path. */
    public static function keyFor(string $absolutePath): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $absolutePath), '-');
    }

    /** Open a registered project's db (schema ensured) and load its metadata into a handle. */
    private static function openHandle(string $dbPath): self
    {
        $pdo = self::open($dbPath);
        self::ensureSchema($pdo);

        $stmt = $pdo->query('SELECT id, name, path, description FROM project LIMIT 1');
        $row = $stmt === false ? false : $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            throw new ClawException("project has no metadata: {$dbPath}");
        }

        return new self($pdo, new Project(
            (string) $row['id'],
            (string) $row['name'],
            (string) $row['path'],
            (string) $row['description'],
        ));
    }

    /** The state-db path for a project id under a projects directory. */
    private static function dbPath(string $projectsDir, string $id): string
    {
        return $projectsDir . '/' . $id . '.db';
    }

    private static function open(string $dbPath): \PDO
    {
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /** The project db's tables, created on demand. Idempotent ({@see addIssue}). */
    private static function ensureSchema(\PDO $pdo): void
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
