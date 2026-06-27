<?php

declare(strict_types=1);

namespace Claw;

use Async\Channel;
use Async\Scope;
use Claw\Agent\SpeakerInterface;
use Claw\Cli\Cli;
use Claw\Cli\IssueRunner;
use Claw\Http\CurlHttpClient;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
use Claw\Trace\LiveTraceSink;
use Claw\Trace\TraceBus;
use Claw\Trace\Tracer;
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
    /** Long-lived scope owning every in-flight run, so a run outlives the request that started it. */
    private Scope $scope;

    /** Live trace pub/sub: a run's LiveTraceSink publishes here, SSE streams subscribe (push, no poll). */
    private TraceBus $bus;

    /** @var array<string, true> issue ids with an active run — guards against a concurrent double-start. */
    private array $active = [];

    /** @var array<string, Channel<string>> issue id → the open gate's answer channel ({@see answer()} feeds it). */
    private array $gates = [];

    /** @param string $root the install root: anchors the app home so a run can load its {@see \Claw\Config}. */
    public function __construct(
        private readonly string $projectsDir,
        private readonly string $root,
    ) {
    }

    public function run(string $host = '127.0.0.1', int $port = 8787): void
    {
        $config = (new HttpServerConfig())
            ->addListener($host, $port)
            ->setReadTimeout(15)
            ->setWriteTimeout(15)
            ->setKeepAliveTimeout(60);

        $this->scope = new Scope();   // owns the run coroutines spawned by POST .../start
        $this->bus = new TraceBus();   // live trace push from those runs to the SSE streams

        $server = new HttpServer($config);
        $server->addHttpHandler($this->handle(...));

        \fwrite(STDOUT, "claw dashboard API → http://{$host}:{$port}\n");
        \fwrite(STDOUT, "  projects: {$this->projectsDir}\n");

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
                if (\preg_match('#^/api/projects/([^/]+)/issues/([^/]+)/start$#', $path, $m)) {
                    $this->start($res, $m[1], $m[2]);

                    return;
                }
                if (\preg_match('#^/api/projects/([^/]+)/issues/([^/]+)/answer$#', $path, $m)) {
                    $this->answer($req, $res, $m[1], $m[2]);

                    return;
                }
                $this->json($res, 404, ['error' => 'not found', 'path' => $path]);

                return;
            }
            if ($method !== 'GET') {
                $this->json($res, 405, ['error' => 'method not allowed']);

                return;
            }

            if ($path === '/api/health') {
                $this->json($res, 200, ['ok' => true]);

                return;
            }
            if ($path === '/api/projects') {
                $this->json($res, 200, $this->projects());

                return;
            }
            if (\preg_match('#^/api/projects/([^/]+)/issues/stream$#', $path, $m)) {
                $this->issuesStream($res, $m[1]);

                return;
            }
            if (\preg_match('#^/api/projects/([^/]+)/issues$#', $path, $m)) {
                $this->json($res, 200, $this->issues($m[1]));

                return;
            }
            if (\preg_match('#^/api/projects/([^/]+)/runs/([^/]+)/stream$#', $path, $m)) {
                $this->stream($req, $res, $m[1], $m[2]);

                return;
            }
            if (\preg_match('#^/api/projects/([^/]+)/runs/([^/]+)/trace$#', $path, $m)) {
                $since = (int) $req->getQueryParam('since', 0);
                $this->json($res, 200, $this->trace($this->pdo($m[1]), $m[2], $since));

                return;
            }
            if (\preg_match('#^/api/projects/([^/]+)/runs/([^/]+)/artifacts$#', $path, $m)) {
                $this->json($res, 200, $this->artifacts($this->pdo($m[1]), $m[2]));

                return;
            }

            $this->json($res, 404, ['error' => 'not found', 'path' => $path]);
        } catch (\Throwable $e) {
            $this->json($res, 500, ['error' => $e->getMessage()]);
        }
    }

    /** @return list<array{key:string,name:string,path:string}> */
    private function projects(): array
    {
        $out = [];
        foreach (\glob($this->projectsDir . '/*.db') ?: [] as $file) {
            try {
                $stmt = $this->open($file)->prepare('SELECT name, path FROM project LIMIT 1');
                $stmt->execute();
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable) {
                continue; // not a project db
            }
            if (\is_array($row)) {
                $out[] = ['key' => \basename($file, '.db'), 'name' => (string) $row['name'], 'path' => (string) $row['path']];
            }
        }

        return $out;
    }

    /**
     * Issues for a project, shaped to the UI's Issue model (status / progress / runs /
     * tokens / artifacts), so the dashboard's HttpClient maps them with no translation.
     *
     * @return list<array<string,mixed>>
     */
    private function issues(string $key): array
    {
        $pdo = $this->pdo($key);
        $stmt = $pdo->prepare('SELECT id, title, status FROM issues ORDER BY id');
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $issues = [];
        foreach ($rows as $r) {
            $runStmt = $pdo->prepare('SELECT id, workflow, status FROM runs WHERE issue_id = ? ORDER BY id');
            $runStmt->execute([(string) $r['id']]);
            $runRows = $runStmt->fetchAll(\PDO::FETCH_ASSOC);
            $latest = $runRows ? $runRows[\array_key_last($runRows)] : null;

            $done = 0;
            $tin = 0;
            $tout = 0;
            $artifacts = [];
            if ($latest !== null) {
                $rid = (string) $latest['id'];
                $done = $this->doneCount($pdo, $rid);
                [$tin, $tout] = $this->tokens($pdo, $rid);
                $artifacts = $this->artifacts($pdo, $rid);
            }

            $status = $this->uiStatus((string) $r['status']);
            $issues[] = [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'status' => $status,
                'done' => $done,
                'live' => $status === 'inprogress',
                'runs' => \array_map(
                    static fn (array $x): array => ['n' => (int) $x['id'], 'status' => (string) $x['status']],
                    $runRows,
                ),
                'tokensIn' => $tin,
                'tokensOut' => $tout,
                'artifacts' => $artifacts,
                'chat' => [], // ask-channel inbox is a write path — needs the SSE/answer work
            ];
        }

        return $issues;
    }

    /** Completed-step count from the durable snapshot. */
    private function doneCount(\PDO $pdo, string $runId): int
    {
        try {
            $stmt = $pdo->prepare('SELECT done FROM workflow_state WHERE run_id = ?');
            $stmt->execute([$runId]);
            $json = $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0; // table not created on this db yet
        }
        if (!\is_string($json)) {
            return 0;
        }
        $done = \json_decode($json, true);

        return \is_array($done) ? \count($done) : 0;
    }

    /** @return array{0:int,1:int} input/output tokens summed over the run's replies. */
    private function tokens(\PDO $pdo, string $runId): array
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(json_extract(data, '$.usage.in')), 0)  AS tin,
                    COALESCE(SUM(json_extract(data, '$.usage.out')), 0) AS tout
             FROM trace WHERE run_id = ? AND type = 'reply'",
        );
        $stmt->execute([$runId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['tin' => 0, 'tout' => 0];

        return [(int) $row['tin'], (int) $row['tout']];
    }

    /** @return list<array<string,mixed>> */
    private function artifacts(\PDO $pdo, string $runId): array
    {
        $stmt = $pdo->prepare("SELECT data FROM trace WHERE run_id = ? AND type = 'artifact' ORDER BY seq");
        $stmt->execute([$runId]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $json) {
            $d = \json_decode((string) $json, true);
            if (!\is_array($d)) {
                continue;
            }
            $out[] = [
                'name' => (string) ($d['label'] ?? ''),
                'kind' => (string) ($d['kind'] ?? 'file'),
                'meta' => '',
                'body' => (string) ($d['value'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Trace spans for a run past a seq cursor — the polling primitive that stands in
     * for SSE. `seq` is a global monotonic autoincrement, so `seq > since` is a clean tail.
     *
     * @return list<array<string,mixed>>
     */
    private function trace(\PDO $pdo, string $runId, int $since): array
    {
        $stmt = $pdo->prepare(
            'SELECT seq, span_id, parent_id, depth, phase, type, level, data
             FROM trace WHERE run_id = ? AND seq > ? ORDER BY seq',
        );
        $stmt->execute([$runId, $since]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'seq' => (int) $r['seq'],
                'spanId' => (int) $r['span_id'],
                'parentId' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'depth' => (int) $r['depth'],
                'phase' => (string) $r['phase'],
                'type' => (string) $r['type'],
                'level' => (int) $r['level'],
                'data' => \json_decode((string) $r['data'], true),
            ];
        }

        return $out;
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
        } catch (\Throwable $e) {
            $this->json($res, 404, ['error' => $e->getMessage()]);   // pre-stream: a normal JSON error is fine

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
                } catch (\Throwable) {
                    $res->sseComment('hb');   // the timeout cancelled recv (the channel is never closed) — heartbeat

                    continue;
                }

                $seq = (int) $row['seq'];
                if ($seq <= $since) {
                    continue;   // already sent during the replay/overlap
                }
                if ($seq > $since + 1) {
                    // a dropped event left a gap — heal it from the durable journal, in order
                    foreach ($this->trace($pdo, $runId, $since) as $gap) {
                        $this->sseRow($res, $gap);
                        $since = (int) $gap['seq'];
                    }

                    continue;
                }
                if (!$res->sendable()) {
                    continue;   // slow client: skip; a later gap-heal or a reconnect replays it
                }
                $this->sseRow($res, $row);
                $since = $seq;
            }
        } catch (\Throwable) {
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
            data: \json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
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
        } catch (\Throwable $e) {
            $this->json($res, 404, ['error' => $e->getMessage()]);

            return;
        }

        $res->sseStart();

        $seen = [];   // issue id → the json it was last sent as
        $idleTicks = 0;
        try {
            while (!$res->isClosed()) {
                $changed = false;
                foreach ($this->issues($key) as $issue) {
                    $id = (string) $issue['id'];
                    $json = \json_encode($issue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    if (($seen[$id] ?? null) === $json) {
                        continue;
                    }
                    if (!$res->sendable()) {
                        continue;   // slow client: leave it unseen so the next tick retries
                    }
                    $seen[$id] = $json;
                    $res->sseEvent(data: $json, event: 'issue');
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
        } catch (\Throwable) {
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
        } catch (\Throwable $e) {
            $this->json($res, 404, ['error' => $e->getMessage()]);

            return;
        }

        if (isset($this->active[$issue->id])) {
            $this->json($res, 409, ['error' => 'a run for this issue is already active']);

            return;
        }

        $config = Config::load($this->root . '/.env');
        $agent = Cli::makeAgent($config, new CurlHttpClient());
        if ($agent === null) {
            $this->json($res, 500, ['error' => "agent '{$config->agent}' is not wired"]);

            return;
        }

        $this->active[$issue->id] = true;
        /** @var Channel<string> $answers unbuffered: a gate's send() waits for the parked run's recv() */
        $answers = new Channel();
        $this->gates[$issue->id] = $answers;

        // Detached into the server scope so the run survives this handler returning. No live trace sink:
        // the dashboard reads the journal the TraceStore persists. The gate records through the run's
        // tracer, so the human tier is built as a factory once the runner has made that tracer.
        $this->scope->spawn(function () use ($store, $issue, $config, $agent, $answers): void {
            try {
                new IssueRunner(
                    $this->projectsDir,
                    $store,
                    $config,
                    $agent,
                    fn (Tracer $tracer): SpeakerInterface => new HttpGateSpeaker($tracer, $store, $issue->id, $answers),
                    static fn (string $solverPath, string $solverCode): bool => true,   // auto-run the generated solver (pre-approval gate is later)
                    static function (string $message, bool $isError): void {
                    },           // progress lives in the trace journal, not a console
                    new LiveTraceSink($this->bus, $store->pdo()),                         // push each persisted record to the SSE streams
                )->run($issue);
            } catch (\Throwable) {
                // The run records its own Failed status; nothing else to do here.
            } finally {
                unset($this->active[$issue->id], $this->gates[$issue->id]);
            }
        });

        $this->json($res, 202, ['ok' => true]);
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
            $this->json($res, 409, ['error' => 'no run is waiting for an answer on this issue']);

            return;
        }

        try {
            $stmt = $this->pdo($key)->prepare('SELECT status FROM issues WHERE id = ?');
            $stmt->execute([$issueId]);
            $status = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->json($res, 404, ['error' => $e->getMessage()]);

            return;
        }
        if ($status !== IssueStatus::WaitingHuman->name) {
            $this->json($res, 409, ['error' => 'the run is not waiting for an answer right now']);

            return;
        }

        $data = \json_decode($req->getBody(), true);
        $text = \is_array($data) && isset($data['text']) ? (string) $data['text'] : '';

        $channel->send($text);   // wake the parked run; unbuffered, so this returns once the run takes it
        $this->json($res, 202, ['ok' => true]);
    }

    /** Open a WRITABLE project handle by key (a run mutates state), reusing the registry's {@see ProjectStore::discover()}. */
    private function storeFor(string $key): ProjectStore
    {
        $stmt = $this->pdo($key)->query('SELECT path FROM project LIMIT 1');
        $path = $stmt === false ? false : $stmt->fetchColumn();
        if (!\is_string($path)) {
            throw new \RuntimeException("unknown project: {$key}");
        }

        $store = ProjectStore::discover($this->projectsDir, $path);
        if ($store === null) {
            throw new \RuntimeException("cannot open project: {$key}");
        }

        return $store;
    }

    private function uiStatus(string $name): string
    {
        return match ($name) {
            'Open' => 'open',
            'InProgress' => 'inprogress',
            'WaitingHuman' => 'waiting',
            'Done' => 'done',
            'Closed' => 'closed',
            default => 'open',
        };
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

    private function json(HttpResponse $res, int $status, mixed $data): void
    {
        $res->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setBody(\json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->end();
    }
}
