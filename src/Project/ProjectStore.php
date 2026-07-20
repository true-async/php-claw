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
 * An instance is a HANDLE to one already-resolved project: it holds an open \PDO and the
 * project's metadata. The \PDO has TrueAsync's connection pool enabled ({@see open()}), so the
 * one handle is safe to share across concurrent coroutines — the dashboard's reads and a detached
 * run's writes — without a connection per caller. The path is resolved and the db opened exactly
 * ONCE — by {@see discover()} or {@see init()} — not on every operation; that same handle
 * ({@see pdo()}) also backs the durable workflow-run state store and the trace store.
 */
final class ProjectStore implements ProjectStoreInterface
{
    /**
     * How deep decomposition may nest: depth 0 is the original ticket, so this allows a task, its
     * parts, and their parts. Enforced HERE rather than in the tool that a model calls, because
     * {@see addIssue()} is the only door ALL callers share — the CLI and the dashboard open issues
     * through it too, and a cap only one caller honours is not a cap.
     */
    public const int MAX_DEPTH = 2;

    /** How many sub-issues one issue may be split into. A split into more parts than this is a plan, not a task. */
    public const int MAX_CHILDREN = 8;

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
    public static function init(string $projectsDir, string $projectPath, string $description = ''): Project
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
                'description' => $description,
                'created_at' => time(),
            ]);
        } catch (\PDOException $e) {
            // Don't leave a half-written db behind if the schema/insert failed.
            @unlink($dbPath);

            throw new ClawException("ProjectStore: cannot create {$dbPath}: " . $e->getMessage(), 0, $e);
        }

        return new Project($id, $name, $abs, $description);
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

    /** Open a registered project by its key (the db filename), or null if there is no such db. */
    public static function openByKey(string $projectsDir, string $key): ?self
    {
        $dbPath = self::dbPath($projectsDir, basename($key));

        return is_file($dbPath) ? self::openHandle($dbPath) : null;
    }

    /**
     * Every registered project's metadata — for the dashboard's project list. A db that is not a valid
     * project (no metadata row) is skipped rather than failing the whole listing.
     *
     * @return list<Project>
     */
    public static function all(string $projectsDir): array
    {
        $projects = [];

        foreach (glob($projectsDir . '/*.db') ?: [] as $dbPath) {
            try {
                $projects[] = self::openHandle($dbPath)->project;
            } catch (\Exception) {
                continue;
            }
        }

        return $projects;
    }

    /** This project's metadata (id, name, the external folder path, description). */
    public function project(): Project
    {
        return $this->project;
    }

    /**
     * Rewrite the project's description — its brief, authored as Markdown and rendered for reading.
     * Kept as raw source rather than rendered HTML: the store holds what the author wrote, and how it
     * is displayed is the reader's business.
     *
     * @throws ClawException
     */
    public function setDescription(string $description): void
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE project SET description = :description WHERE id = :id');
            $stmt->execute(['description' => $description, 'id' => $this->project->id]);
        } catch (\PDOException $e) {
            throw new ClawException('ProjectStore: cannot update the description: ' . $e->getMessage(), 0, $e);
        }

        $this->project->description = $description;   // the open handle must not serve a stale brief
    }

    /**
     * Every issue in the project, oldest first — for the dashboard board. Runs are read separately
     * ({@see runsFor()}), so this stays a single cheap query.
     *
     * @return list<Issue>
     */
    public function allIssues(): array
    {
        // soft-deleted issues stay in the db (history + runs) but are hidden from the board
        $stmt = $this->pdo->query(
            "SELECT id, title, description, status, parent_id, depth FROM issues WHERE status != 'Deleted' ORDER BY id",
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * The issues decomposed directly out of this one, oldest first — one level, not the whole subtree.
     * Soft-deleted children are left out: a deleted sub-issue must not hold its parent open forever.
     *
     * @return list<Issue>
     */
    public function childIssues(string $issueId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, title, description, status, parent_id, depth
             FROM issues WHERE parent_id = :parent AND status != 'Deleted' ORDER BY id",
        );
        $stmt->execute(['parent' => $issueId]);

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /**
     * Settle the ancestors of an issue that just landed: a parent is finished only when EVERY child is,
     * so this walks up and settles each ancestor whose children have all landed, stopping at the first
     * that still has open work. Decomposition is the only thing that creates parents, so for a root
     * issue (the common case) this returns after one cheap lookup.
     *
     * A parent settles to Done only if at least one child was actually DONE; children that were all
     * merely Closed settle it to Closed. Reporting a parent as Done when every part of it was abandoned
     * would claim work that nobody did.
     *
     * A parent that is itself Closed or Deleted is left alone — a human already ruled on it, and a
     * finishing child must not reopen that decision.
     */
    public function settleAncestors(string $issueId): void
    {
        $parentId = $this->parentOf($issueId);
        $seen = [];   // parent_id is not constrained to be acyclic, and a cycle here would hang the run

        while ($parentId !== null && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $parent = $this->loadIssue($parentId);

            if ($parent->status === IssueStatus::Closed || $parent->status === IssueStatus::Deleted) {
                return;
            }

            $anyDone = false;

            foreach ($this->childIssues($parentId) as $child) {
                if ($child->status !== IssueStatus::Done && $child->status !== IssueStatus::Closed) {
                    return;   // a sibling is still in flight — the parent stays open
                }
                $anyDone = $anyDone || $child->status === IssueStatus::Done;
            }

            $this->setIssueStatus($parentId, $anyDone ? IssueStatus::Done : IssueStatus::Closed);
            $parentId = $this->parentOf($parentId);
        }
    }

    /**
     * Reopen the ancestors of an issue that has come back to life. The counterpart to
     * {@see settleAncestors()}: without it, reopening the last sub-issue leaves its parent reported
     * finished while live work sits under it. Walks up while each ancestor is settled, and stops at
     * one a person Closed or Deleted — the same decision this must not overturn.
     */
    public function reopenAncestors(string $issueId): void
    {
        $parentId = $this->parentOf($issueId);
        $seen = [];

        while ($parentId !== null && !isset($seen[$parentId])) {
            $seen[$parentId] = true;

            if ($this->loadIssue($parentId)->status !== IssueStatus::Done) {
                return;   // already open, or a human ruling we leave alone
            }

            $this->setIssueStatus($parentId, IssueStatus::Open);
            $parentId = $this->parentOf($parentId);
        }
    }

    /**
     * Record the ProjectManager's verdict for an issue: how it is to be solved, why, and whether a
     * person must sign off before it runs. Appended, never overwritten — {@see strategyAttempts()}
     * is what a retry reads to escalate instead of repeating itself.
     */
    public function setStrategy(string $issueId, Strategy $strategy, string $reason, bool $needsHuman): void
    {
        // A strategy that already failed cannot be chosen again, and neither can a cheaper one: the same
        // approach fails the same way, so a retry is only worth spending on if it does MORE than what
        // broke. This rule was documented in three places and checked in none — which made it a
        // suggestion to the model rather than a bound, the exact failure this system keeps hitting.
        foreach ($this->strategyAttempts($issueId) as $attempt) {
            if ($attempt['outcome'] === StrategyOutcome::Failed && $strategy->rank() <= $attempt['strategy']->rank()) {
                throw new ClawException(sprintf(
                    "strategy '%s' does not escalate past '%s', which already failed here (%s) — the next "
                    . 'attempt must do more than the one that broke, not the same thing again',
                    $strategy->value,
                    $attempt['strategy']->value,
                    $attempt['outcomeReason'],
                ));
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO issue_strategy (issue_id, strategy, reason, needs_human, outcome, created_at)
             VALUES (:issue, :strategy, :reason, :needs_human, :outcome, :created_at)',
        );
        $stmt->execute([
            'issue' => $issueId,
            'strategy' => $strategy->value,
            'reason' => $reason,
            'needs_human' => $needsHuman ? 1 : 0,
            'outcome' => StrategyOutcome::Pending->value,
            'created_at' => time(),
        ]);
    }

    /**
     * The verdict currently in force for an issue, or null if it was never triaged.
     *
     * @return ?array{strategy: Strategy, reason: string, needsHuman: bool, outcome: StrategyOutcome, outcomeReason: string}
     */
    public function currentStrategy(string $issueId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT strategy, reason, needs_human, outcome, outcome_reason FROM issue_strategy
             WHERE issue_id = :issue ORDER BY id DESC LIMIT 1',
        );
        $stmt->execute(['issue' => $issueId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? $this->hydrateStrategy($row) : null;
    }

    /**
     * Every verdict passed on an issue, oldest first — what was tried and how it ended. A re-triage
     * reads this so it escalates rather than choosing a strategy that has already failed.
     *
     * @return list<array{strategy: Strategy, reason: string, needsHuman: bool, outcome: StrategyOutcome, outcomeReason: string}>
     */
    public function strategyAttempts(string $issueId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT strategy, reason, needs_human, outcome, outcome_reason FROM issue_strategy
             WHERE issue_id = :issue ORDER BY id',
        );
        $stmt->execute(['issue' => $issueId]);

        return array_values(array_map($this->hydrateStrategy(...), $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /**
     * Mark the verdict in force for an issue as failed. The attempt stays in the ledger so the next
     * verdict can see it and escalate past it. Returns false when there was no verdict in force to
     * fail — a second report in a row, or an issue that was never triaged.
     *
     * The failure reason gets its OWN column: it must not overwrite the reason the strategy was
     * CHOSEN for, because the next escalation is decided from both — what we expected to work, and
     * what actually broke — and one column can only hold the second.
     *
     * The issue reopens so it is triaged again rather than sitting silently InProgress. One a person
     * already Closed or Deleted is left where it is: a late failure report must not resurrect a
     * ticket someone ruled on.
     */
    public function failStrategy(string $issueId, string $reason): bool
    {
        // Only the row still in force may be failed, and only once. Without the outcome predicate a
        // repeated report would rewrite the first failure, erasing an attempt from the very ledger the
        // next escalation is chosen from.
        $stmt = $this->pdo->prepare(
            'UPDATE issue_strategy SET outcome = :failed, outcome_reason = :reason, settled_at = :now
             WHERE id = (
                 SELECT id FROM issue_strategy
                 WHERE issue_id = :issue AND outcome = :pending ORDER BY id DESC LIMIT 1
             )',
        );
        $stmt->execute([
            'failed' => StrategyOutcome::Failed->value,
            'reason' => $reason,
            'now' => time(),
            'issue' => $issueId,
            'pending' => StrategyOutcome::Pending->value,
        ]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $status = $this->loadIssue($issueId)->status;

        if ($status !== IssueStatus::Closed && $status !== IssueStatus::Deleted) {
            $this->setIssueStatus($issueId, IssueStatus::Open);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{strategy: Strategy, reason: string, needsHuman: bool, outcome: StrategyOutcome, outcomeReason: string}
     */
    private function hydrateStrategy(array $row): array
    {
        return [
            'strategy' => Strategy::stored((string) $row['strategy']),
            'reason' => (string) $row['reason'],
            'needsHuman' => (bool) $row['needs_human'],
            'outcome' => StrategyOutcome::stored((string) $row['outcome']),
            'outcomeReason' => (string) ($row['outcome_reason'] ?? ''),
        ];
    }

    /** The id of the issue this one was decomposed out of, or null for a root issue. */
    private function parentOf(string $issueId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT parent_id FROM issues WHERE id = :id');
        $stmt->execute(['id' => $issueId]);
        $parent = $stmt->fetchColumn();

        return ($parent === false || $parent === null) ? null : (string) $parent;
    }

    /**
     * Build an Issue from a row of the issues table. Runs are NOT read here — {@see allIssues()} and
     * {@see childIssues()} stay single cheap queries; {@see loadIssue()} adds them for the one issue
     * that needs them.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Issue
    {
        return new Issue(
            (string) $row['id'],
            $this->project->id,
            (string) $row['title'],
            (string) $row['description'],
            IssueStatus::fromName((string) $row['status']),
            [],
            ($row['parent_id'] ?? null) === null ? null : (string) $row['parent_id'],
            (int) ($row['depth'] ?? 0),
        );
    }

    /**
     * The runs spawned for an issue, oldest first, with their status — for the dashboard's run list.
     *
     * @return list<array{id: string, workflow: string, status: string}>
     */
    public function runsFor(string $issueId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, workflow, status FROM runs WHERE issue_id = ? ORDER BY id');
        $stmt->execute([$issueId]);

        return array_values(array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'workflow' => (string) $row['workflow'],
                'status' => (string) $row['status'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        ));
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
    public function addIssue(string $title, string $description = '', ?string $parent = null): Issue
    {
        $title = trim($title);

        if ($title === '') {
            throw new ClawException('issue title must not be empty');
        }

        if ($parent === null) {
            return $this->insertIssue($title, $description, null, 0);
        }

        // The caps are checked and the row written inside ONE transaction. Checking first and inserting
        // after would not bound anything: the \PDO is pooled across coroutines, so two concurrent
        // decompositions would both read "7 children", both pass, and both insert. BEGIN IMMEDIATE takes
        // the write lock up front, which serialises the pair.
        $this->pdo->beginTransaction();

        try {
            // Depth is DERIVED from the parent, never supplied: a caller cannot understate how deep a
            // decomposition has gone and so cannot talk its way past the cap. Loading the parent also
            // rejects a sub-issue hung off an id that does not exist.
            $depth = $this->loadIssue($parent)->depth + 1;

            if ($depth > self::MAX_DEPTH) {
                throw new ClawException(sprintf(
                    'issue #%s is already at decomposition depth %d and the limit is %d — do not split it '
                    . 'further; choose a strategy that DOES the work (direct, library or generate)',
                    $parent,
                    $depth - 1,
                    self::MAX_DEPTH,
                ));
            }

            $children = \count($this->childIssues($parent));

            if ($children >= self::MAX_CHILDREN) {
                throw new ClawException(sprintf(
                    'issue #%s already has %d sub-issues and the limit is %d — fold the remaining work into '
                    . 'the existing sub-issues rather than opening more',
                    $parent,
                    $children,
                    self::MAX_CHILDREN,
                ));
            }

            $issue = $this->insertIssue($title, $description, $parent, $depth);
            $this->pdo->commit();

            return $issue;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();   // a refused decomposition must leave nothing half-created

            throw $e;
        }
    }

    /** Write one issue row and return the issue the store assigned an id to. @throws ClawException */
    private function insertIssue(string $title, string $description, ?string $parent, int $depth): Issue
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO issues (title, description, status, parent_id, depth, created_at)
                 VALUES (:title, :description, :status, :parent, :depth, :created_at)',
            );
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'status' => IssueStatus::Open->name,
                'parent' => $parent,
                'depth' => $depth,
                'created_at' => time(),
            ]);
            $issueId = (string) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            throw new ClawException('ProjectStore: cannot add issue: ' . $e->getMessage(), 0, $e);
        }

        return new Issue($issueId, $this->project->id, $title, $description, IssueStatus::Open, [], $parent, $depth);
    }

    /** Load one issue (with the ids of the runs spawned for it). @throws ClawException */
    public function loadIssue(string $issueId): Issue
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, title, description, status, parent_id, depth FROM issues WHERE id = :id',
            );
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
            ($row['parent_id'] ?? null) === null ? null : (string) $row['parent_id'],
            (int) ($row['depth'] ?? 0),
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
        // TrueAsync's built-in PDO pool hands each coroutine its own connection on demand, so ONE shared
        // handle is safe to use from concurrent runs and dashboard reads at once — no manual per-run
        // connection. The pool keeps a coroutine's connection pinned across awaits, so a bare
        // INSERT + lastInsertId() stays correct (verified).
        //
        // Everything that must apply to EVERY pooled connection goes in the construction options, since
        // the pool reuses them per connection: ATTR_TIMEOUT is the busy timeout (a post-open `PRAGMA
        // busy_timeout` would reach only the one connection that ran it). WAL is the exception — it is
        // persisted in the db header, so a single PRAGMA sets it for every connection and every restart.
        //
        // ATTR_POOL_* are TrueAsync's PDO additions; PHPStan reads the core \PDO stub (the ide-helper's
        // \PDO redeclaration is deliberately not scanned — see phpstan.dist.neon), so each is ignored.
        $pdo = new \PDO('sqlite:' . $dbPath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 4,   // busy timeout (s) — ride out a concurrent writer rather than fail
            \PDO::ATTR_POOL_ENABLED => true,   // @phpstan-ignore classConstant.notFound
            \PDO::ATTR_POOL_MIN => 1,          // @phpstan-ignore classConstant.notFound
            \PDO::ATTR_POOL_MAX => 8,          // @phpstan-ignore classConstant.notFound
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');

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
                parent_id   INTEGER,
                depth       INTEGER NOT NULL DEFAULT 0,
                created_at  INTEGER NOT NULL
            )",
        );
        self::addMissingColumns($pdo, 'issues', [
            'parent_id' => 'INTEGER',
            'depth' => 'INTEGER NOT NULL DEFAULT 0',
        ]);
        // Every ProjectManager verdict for an issue, newest last — a LEDGER, not a single column, so a
        // retry can see which strategies were already tried and why they failed. Overwriting one field
        // would lose exactly the history an escalation decision is made from.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS issue_strategy (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                issue_id       INTEGER NOT NULL,
                strategy       TEXT NOT NULL,
                reason         TEXT NOT NULL DEFAULT '',
                needs_human    INTEGER NOT NULL DEFAULT 0,
                outcome        TEXT NOT NULL DEFAULT 'pending',
                outcome_reason TEXT NOT NULL DEFAULT '',
                settled_at     INTEGER,
                created_at     INTEGER NOT NULL
            )",
        );
        self::addMissingColumns($pdo, 'issue_strategy', [
            'outcome_reason' => "TEXT NOT NULL DEFAULT ''",
            'settled_at' => 'INTEGER',
        ]);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS runs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                issue_id    INTEGER NOT NULL,
                workflow    TEXT NOT NULL,
                status      TEXT NOT NULL,
                created_at  INTEGER NOT NULL
            )',
        );

        // Every access these tables get is a lookup by one of these keys — a parent's children, an
        // issue's verdicts, an issue's runs — so without them each is a full scan, and settleAncestors
        // scans once per level it walks.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_issues_parent ON issues (parent_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_issue_strategy_issue ON issue_strategy (issue_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_issue ON runs (issue_id)');
    }

    /**
     * Add columns a newer version introduced to a table that already exists. `CREATE TABLE IF NOT
     * EXISTS` is a no-op on an existing db, so a db created before a column existed would never gain
     * it and every read of that column would fail — this is what keeps an already-registered project
     * usable across an upgrade. SQLite has no `ADD COLUMN IF NOT EXISTS`, so the current columns are
     * read first and only the missing ones are added.
     *
     * @param array<string, string> $columns name => column definition
     */
    private static function addMissingColumns(\PDO $pdo, string $table, array $columns): void
    {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        $rows = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $existing = array_map(static fn (array $row): string => (string) $row['name'], $rows);

        foreach ($columns as $name => $definition) {
            if (!\in_array($name, $existing, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
            }
        }
    }
}
