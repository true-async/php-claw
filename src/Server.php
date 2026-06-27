<?php

declare(strict_types=1);

namespace Claw;

use Async\AsyncCancellation;
use Async\Channel;

use function Async\spawn;

use Claw\Agent\AgentFactory;
use Claw\Cli\IssueRunner;
use Claw\Http\CurlHttpClient;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
use Claw\Run\HttpRunFrontend;
use Claw\Trace\TraceBus;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;
use TrueAsync\HttpServer;
use TrueAsync\HttpServerConfig;

/**
 * JSON + SSE API over the project state databases, for the php-claw-ui dashboard.
 *
 * Runs on the TrueAsync HTTP server (true_async_server.so), so every request handler is a coroutine on
 * the event loop. Reads open the same SQLite the CLI writes; POST .../start runs an issue's solver as a
 * detached coroutine ({@see IssueRunner}) and POST .../answer feeds its human gate. A run pushes each
 * trace record to an in-process {@see TraceBus}, so the run stream is live with no polling; the durable
 * `trace.seq` autoincrement is the resume cursor (`Last-Event-ID`/`?since=`).
 *
 *   php -d extension=/path/to/true_async_server.so bin/claw serve [--port 8787] [--host 127.0.0.1]
 *
 * Endpoints:
 *   GET  /api/health
 *   GET  /api/projects
 *   GET  /api/projects/{key}/issues
 *   GET  /api/projects/{key}/issues/stream                    (SSE — board: an `issue` event per change)
 *   GET  /api/projects/{key}/runs/{runId}/stream             (SSE — live trace, keyed by seq)
 *   GET  /api/projects/{key}/runs/{runId}/trace?since=<seq>   (poll fallback for the run stream)
 *   GET  /api/projects/{key}/runs/{runId}/artifacts
 *   POST /api/projects/{key}/issues/{id}/start               (launch the solver, 202)
 *   POST /api/projects/{key}/issues/{id}/answer              (reply to the run's open gate)
 */
final class Server
{
    /** Flags for the SSE data payloads (the JSON the dashboard reads). */
    private const int JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Live trace pub/sub: a run's LiveTraceSink publishes here, SSE streams subscribe (push, no poll). */
    private TraceBus $bus;

    /** @var array<string, true> issue ids with an active run — guards against a concurrent double-start. */
    private array $active = [];

    /** @var array<string, Channel<string>> issue id → the open gate's answer channel ({@see answer()} feeds it). */
    private array $gates = [];

    /** @param string $root the install root: anchors the app home so a run can load its {@see Config}. */
    public function __construct(
        private readonly string $projectsDir,
        private readonly string $root,
    ) {
    }

    public function run(string $host = '127.0.0.1', int $port = 8787): void
    {
        $config = new HttpServerConfig()
            ->addListener($host, $port)
            ->setReadTimeout(15)
            ->setWriteTimeout(15)
            ->setKeepAliveTimeout(60);

        $this->bus = new TraceBus();   // live trace push from the spawned runs to the SSE streams

        $server = new HttpServer($config);
        $server->addHttpHandler($this->handle(...));

        echo "claw dashboard API → http://{$host}:{$port}\n";
        echo "  projects: {$this->projectsDir}\n";

        $server->start();
    }

    /** Route one request. Both args come straight from the server extension's handler. */
    public function handle(HttpRequest $req, HttpResponse $res): void
    {
        $path = \parse_url((string) $req->getUri(), PHP_URL_PATH) ?: '/';
        $method = (string) $req->getMethod();

        // permissive CORS so the local dev UI (vite :5173) can call this directly
        $res->setHeader('Access-Control-Allow-Origin', '*')
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type');

        if ($method === 'OPTIONS') {
            $res->setStatusCode(204)->end();

            return;
        }

        try {
            if ($method === 'POST') {
                if (\preg_match('#^/api/projects/([^/]+)/issues/([^/]+)/start$#', $path, $matches)) {
                    $this->start($res, $matches[1], $matches[2]);

                    return;
                }
                if (\preg_match('#^/api/projects/([^/]+)/issues/([^/]+)/answer$#', $path, $matches)) {
                    $this->answer($req, $res, $matches[1], $matches[2]);

                    return;
                }
                $res->json(['error' => 'not found', 'path' => $path], 404);

                return;
            }
            if ($method !== 'GET') {
                $res->json(['error' => 'method not allowed'], 405);

                return;
            }

            if ($path === '/api/health') {
                $res->json(['ok' => true]);

                return;
            }
            if ($path === '/api/projects') {
                $res->json($this->projects());

                return;
            }
            if (\preg_match('#^/api/projects/([^/]+)/issues/stream$#', $path, $matches)) {
                $this->issuesStream($res, $matches[1]);

                return;
            }
            if (\preg_match('#^/api/projects/([^/]+)/issues$#', $path, $matches)) {
                $res->json($this->issues($matches[1]));

                return;
            }
            if (\preg_match('#^/api/projects/([^/]+)/runs/([^/]+)/stream$#', $path, $matches)) {
                $this->stream($req, $res, $matches[1], $matches[2]);

                return;
            }
            if (\preg_match('#^/api/projects/([^/]+)/runs/([^/]+)/trace$#', $path, $matches)) {
                $since = (int) $req->getQueryParam('since', 0);
                $res->json($this->trace($this->pdo($matches[1]), $matches[2], $since));

                return;
            }
            if (\preg_match('#^/api/projects/([^/]+)/runs/([^/]+)/artifacts$#', $path, $matches)) {
                $res->json($this->artifacts($this->pdo($matches[1]), $matches[2]));

                return;
            }

            $res->json(['error' => 'not found', 'path' => $path], 404);
        } catch (\Exception $e) {
            $res->json(['error' => $e->getMessage()], 500);
        }
    }

    /** @return list<array{key:string,name:string,path:string}> */
    private function projects(): array
    {
        $projects = [];
        foreach (\glob($this->projectsDir . '/*.db') ?: [] as $file) {
            try {
                $statement = $this->open($file)->prepare('SELECT name, path FROM project LIMIT 1');
                $statement->execute();
                $row = $statement->fetch(\PDO::FETCH_ASSOC);
            } catch (\Exception) {
                continue;   // not a project db
            }
            if (\is_array($row)) {
                $projects[] = [
                    'key' => \basename($file, '.db'),
                    'name' => (string) $row['name'],
                    'path' => (string) $row['path'],
                ];
            }
        }

        return $projects;
    }

    /**
     * Issues for a project, shaped to the UI's Issue model (status / progress / runs / tokens /
     * artifacts), so the dashboard's HttpClient maps them with no translation.
     *
     * @return list<array<string,mixed>>
     */
    private function issues(string $key): array
    {
        $pdo = $this->pdo($key);
        $statement = $pdo->prepare('SELECT id, title, status FROM issues ORDER BY id');
        $statement->execute();
        $issueRows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $issues = [];
        foreach ($issueRows as $issueRow) {
            $runsStatement = $pdo->prepare('SELECT id, workflow, status FROM runs WHERE issue_id = ? ORDER BY id');
            $runsStatement->execute([(string) $issueRow['id']]);
            $runRows = $runsStatement->fetchAll(\PDO::FETCH_ASSOC);
            $latestRun = $runRows ? $runRows[\array_key_last($runRows)] : null;

            $doneCount = 0;
            $tokensIn = 0;
            $tokensOut = 0;
            $artifacts = [];
            if ($latestRun !== null) {
                $runId = (string) $latestRun['id'];
                $doneCount = $this->doneCount($pdo, $runId);
                [$tokensIn, $tokensOut] = $this->tokens($pdo, $runId);
                $artifacts = $this->artifacts($pdo, $runId);
            }

            $status = IssueStatus::fromName((string) $issueRow['status'])->value;
            $issues[] = [
                'id' => (int) $issueRow['id'],
                'title' => (string) $issueRow['title'],
                'status' => $status,
                'done' => $doneCount,
                'live' => $status === IssueStatus::InProgress->value,
                'runs' => \array_map(
                    static fn (array $run): array => ['n' => (int) $run['id'], 'status' => (string) $run['status']],
                    $runRows,
                ),
                'tokensIn' => $tokensIn,
                'tokensOut' => $tokensOut,
                'artifacts' => $artifacts,
                'chat' => [],   // ask-channel inbox is a write path — needs the SSE/answer work
            ];
        }

        return $issues;
    }

    /** Completed-step count from the durable snapshot. */
    private function doneCount(\PDO $pdo, string $runId): int
    {
        try {
            $statement = $pdo->prepare('SELECT done FROM workflow_state WHERE run_id = ?');
            $statement->execute([$runId]);
            $doneJson = $statement->fetchColumn();
        } catch (\Exception) {
            return 0;   // table not created on this db yet
        }
        if (!\is_string($doneJson)) {
            return 0;
        }
        $doneSteps = \json_decode($doneJson, true);

        return \is_array($doneSteps) ? \count($doneSteps) : 0;
    }

    /** @return array{0:int,1:int} input/output tokens summed over the run's replies. */
    private function tokens(\PDO $pdo, string $runId): array
    {
        $statement = $pdo->prepare(
            "SELECT COALESCE(SUM(json_extract(data, '$.usage.in')), 0)  AS tokens_in,
                    COALESCE(SUM(json_extract(data, '$.usage.out')), 0) AS tokens_out
             FROM trace WHERE run_id = ? AND type = 'reply'",
        );
        $statement->execute([$runId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC) ?: ['tokens_in' => 0, 'tokens_out' => 0];

        return [(int) $row['tokens_in'], (int) $row['tokens_out']];
    }

    /** @return list<array<string,mixed>> */
    private function artifacts(\PDO $pdo, string $runId): array
    {
        $statement = $pdo->prepare("SELECT data FROM trace WHERE run_id = ? AND type = 'artifact' ORDER BY seq");
        $statement->execute([$runId]);

        $artifacts = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $artifactJson) {
            $artifact = \json_decode((string) $artifactJson, true);
            if (!\is_array($artifact)) {
                continue;
            }
            $artifacts[] = [
                'name' => (string) ($artifact['label'] ?? ''),
                'kind' => (string) ($artifact['kind'] ?? 'file'),
                'meta' => '',
                'body' => (string) ($artifact['value'] ?? ''),
            ];
        }

        return $artifacts;
    }

    /**
     * Trace rows for a run past a seq cursor — the replay query, shared by the run stream and the
     * `?since=` poll fallback. `seq` is a global monotonic autoincrement, so `seq > since` is a clean tail.
     *
     * @return list<array<string,mixed>>
     */
    private function trace(\PDO $pdo, string $runId, int $since): array
    {
        $statement = $pdo->prepare(
            'SELECT seq, span_id, parent_id, depth, phase, type, level, data
             FROM trace WHERE run_id = ? AND seq > ? ORDER BY seq',
        );
        $statement->execute([$runId, $since]);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'seq' => (int) $row['seq'],
                'spanId' => (int) $row['span_id'],
                'parentId' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'depth' => (int) $row['depth'],
                'phase' => (string) $row['phase'],
                'type' => (string) $row['type'],
                'level' => (int) $row['level'],
                'data' => \json_decode((string) $row['data'], true),
            ];
        }

        return $rows;
    }

    /**
     * Live trace for a run, as Server-Sent Events. Replays the journal tail past the client's cursor
     * (`Last-Event-ID` header on an EventSource reconnect, or `?since=`), then subscribes to the trace
     * bus and is PUSHED each new record — no polling while connected. Every event carries `id: <seq>`,
     * so a dropped connection resumes with no gap.
     *
     * Only runs executing in this server publish to the bus; a stream over any other run replays the
     * journal then idles (heartbeating). recv blocks until an event or a ~10s heartbeat tick, which also
     * re-checks for client disconnect. A dropped live event shows up as a seq gap and is healed from the
     * db on the spot.
     */
    private function stream(HttpRequest $req, HttpResponse $res, string $key, string $runId): void
    {
        try {
            $pdo = $this->pdo($key);
        } catch (\Exception $e) {
            $res->json(['error' => $e->getMessage()], 404);   // pre-stream: a normal JSON error is fine

            return;
        }

        $since = (int) ($req->getHeader('Last-Event-ID') ?? $req->getQueryParam('since', 0));
        $res->sseStart();   // commit text/event-stream headers now, so the browser's onopen fires

        // Subscribe BEFORE the replay so nothing published mid-replay is lost; the seq check de-dupes the
        // overlap. The unsubscribe MUST run on every exit, so the topic does not leak.
        [$channel, $unsubscribe] = $this->bus->subscribe($runId);
        try {
            foreach ($this->trace($pdo, $runId, $since) as $row) {   // 1) replay the journal gap
                $this->sseRow($res, $row);
                $since = (int) $row['seq'];
            }

            while (!$res->isClosed()) {   // 2) live: pushed by the run's LiveTraceSink, no poll
                try {
                    $row = $channel->recv(\Async\timeout(10000));
                } catch (AsyncCancellation) {
                    $res->sseComment('hb');   // heartbeat timeout: ping, then re-check the connection

                    continue;
                } catch (\Cancellation $cancellation) {
                    throw $cancellation;   // a real coroutine cancellation must propagate, never be swallowed
                }

                $seq = (int) $row['seq'];
                if ($seq <= $since) {
                    continue;   // already sent during the replay/overlap
                }
                if ($seq > $since + 1) {
                    // a dropped event left a gap — heal it from the durable journal, in order
                    foreach ($this->trace($pdo, $runId, $since) as $gapRow) {
                        $this->sseRow($res, $gapRow);
                        $since = (int) $gapRow['seq'];
                    }

                    continue;
                }
                if (!$res->sendable()) {
                    continue;   // slow client: skip; a later gap-heal or a reconnect replays it
                }
                $this->sseRow($res, $row);
                $since = $seq;
            }
        } catch (\Exception) {
            // The client vanished mid-write — the connection is gone.
        } finally {
            $unsubscribe();
        }
    }

    /**
     * Emit one trace row as an SSE `trace` event keyed by its seq.
     *
     * @param array<string, mixed> $row
     */
    private function sseRow(HttpResponse $res, array $row): void
    {
        $res->sseEvent(
            data: \json_encode($row, self::JSON),
            event: 'trace',
            id: (string) $row['seq'],
        );
    }

    /**
     * Live board, as Server-Sent Events: an `issue` event per issue whose snapshot changed. This is the
     * low-frequency Kanban feed (a card moving column, a token tick, a gate opening), so unlike the run
     * stream it polls the issue snapshot on a slow tick and emits diffs — re-deriving a handful of issues
     * every couple of seconds is cheap, and the hot per-record path is the run stream, not this. On
     * connect (and on reconnect) every issue is emitted once, since the seen-set starts empty; the client
     * keeps an id→issue map and applies each event.
     */
    private function issuesStream(HttpResponse $res, string $key): void
    {
        try {
            $this->pdo($key);   // resolve/validate the project before committing the stream
        } catch (\Exception $e) {
            $res->json(['error' => $e->getMessage()], 404);

            return;
        }

        $res->sseStart();

        $sentSnapshots = [];   // issue id → the json it was last sent as
        $idleTicks = 0;
        try {
            while (!$res->isClosed()) {
                $changed = false;
                foreach ($this->issues($key) as $issue) {
                    $id = (string) $issue['id'];
                    $snapshot = \json_encode($issue, self::JSON);
                    if (($sentSnapshots[$id] ?? null) === $snapshot) {
                        continue;
                    }
                    if (!$res->sendable()) {
                        continue;   // slow client: leave it unseen so the next tick retries
                    }
                    $sentSnapshots[$id] = $snapshot;
                    $res->sseEvent(data: $snapshot, event: 'issue');
                    $changed = true;
                }

                if ($changed) {
                    $idleTicks = 0;
                } elseif (++$idleTicks >= 5) {   // ~10s of a still board → heartbeat past proxy idle timeouts
                    $res->sseComment('hb');
                    $idleTicks = 0;
                }

                \Async\delay(2000);
            }
        } catch (\Exception) {
            // The client vanished mid-write — the connection is gone.
        }
    }

    /**
     * POST .../issues/{id}/start — launch the issue's solver as a detached coroutine and return at once.
     * The dashboard watches progress on the run stream; the run records its own ledger row, trace and
     * final status. At most one active run per issue (a concurrent start is rejected 409), and the run's
     * gate parks on a per-issue answer channel that {@see answer()} feeds.
     */
    private function start(HttpResponse $res, string $key, string $issueId): void
    {
        try {
            $store = $this->storeFor($key);
            $issue = $store->loadIssue($issueId);
        } catch (\Exception $e) {
            $res->json(['error' => $e->getMessage()], 404);

            return;
        }

        if (isset($this->active[$issue->id])) {
            $res->json(['error' => 'a run for this issue is already active'], 409);

            return;
        }

        $config = Config::load($this->root . '/.env');
        $agent = AgentFactory::make($config, new CurlHttpClient());
        if ($agent === null) {
            $res->json(['error' => "agent '{$config->agent}' is not wired"], 500);

            return;
        }

        $this->active[$issue->id] = true;
        /** @var Channel<string> $answers unbuffered: a gate's send() waits for the parked run's recv() */
        $answers = new Channel();
        $this->gates[$issue->id] = $answers;

        $frontend = new HttpRunFrontend($store, $issue->id, $answers, $this->bus, $store->pdo());

        // Spawn detached so the run survives this handler returning; the dashboard watches the run stream.
        // The run records its own final status, so there is nothing to catch — only the active/gate cleanup.
        spawn(function () use ($store, $config, $agent, $issue, $frontend): void {
            try {
                new IssueRunner($this->projectsDir, $store, $config, $agent, $frontend)->run($issue);
            } finally {
                unset($this->active[$issue->id], $this->gates[$issue->id]);
            }
        });

        $res->json(['ok' => true], 202);
    }

    /**
     * POST .../issues/{id}/answer — deliver the human's reply to the run parked at its gate. Valid only
     * while the issue is WaitingHuman (a gate is actually open); otherwise there is nothing to answer and
     * the unbuffered send would hang, so we reject with 409.
     */
    private function answer(HttpRequest $req, HttpResponse $res, string $key, string $issueId): void
    {
        $channel = $this->gates[$issueId] ?? null;
        if ($channel === null) {
            $res->json(['error' => 'no run is waiting for an answer on this issue'], 409);

            return;
        }

        try {
            $statement = $this->pdo($key)->prepare('SELECT status FROM issues WHERE id = ?');
            $statement->execute([$issueId]);
            $status = $statement->fetchColumn();
        } catch (\Exception $e) {
            $res->json(['error' => $e->getMessage()], 404);

            return;
        }
        if ($status !== IssueStatus::WaitingHuman->name) {
            $res->json(['error' => 'the run is not waiting for an answer right now'], 409);

            return;
        }

        $payload = \json_decode($req->getBody(), true);
        $text = \is_array($payload) && isset($payload['text']) ? (string) $payload['text'] : '';

        $channel->send($text);   // wake the parked run; unbuffered, so this returns once the run takes it
        $res->json(['ok' => true], 202);
    }

    /**
     * Open a WRITABLE project handle by key (a run mutates state), reusing
     * {@see ProjectStore::discover()}.
     */
    private function storeFor(string $key): ProjectStore
    {
        $statement = $this->pdo($key)->query('SELECT path FROM project LIMIT 1');
        $path = $statement === false ? false : $statement->fetchColumn();
        if (!\is_string($path)) {
            throw new \RuntimeException("unknown project: {$key}");
        }

        $store = ProjectStore::discover($this->projectsDir, $path);
        if ($store === null) {
            throw new \RuntimeException("cannot open project: {$key}");
        }

        return $store;
    }

    private function pdo(string $key): \PDO
    {
        $file = $this->projectsDir . '/' . \basename($key) . '.db';
        if (!\is_file($file)) {
            throw new \RuntimeException("unknown project: {$key}");
        }

        return $this->open($file);
    }

    /** A read connection. The dashboard only SELECTs; busy_timeout rides out a concurrent writer. */
    private function open(string $file): \PDO
    {
        $pdo = new \PDO('sqlite:' . $file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout=4000');

        return $pdo;
    }
}
