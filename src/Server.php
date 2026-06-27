<?php

declare(strict_types=1);

namespace Claw;

use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;
use TrueAsync\HttpServer;
use TrueAsync\HttpServerConfig;

/**
 * Read-only JSON API over the project state databases, for the php-claw-ui dashboard.
 *
 * Runs on the TrueAsync HTTP server (true_async_server.so), so every request handler
 * is a coroutine on the event loop. The `trace.seq` autoincrement is the live cursor: a
 * run's stream replays the journal tail past `Last-Event-ID`/`?since=` then keeps the
 * connection open. The server only reads — it opens the same SQLite the CLI writes.
 *
 *   php -d extension=/path/to/true_async_server.so bin/claw serve [--port 8787] [--host 127.0.0.1]
 *
 * Endpoints:
 *   GET /api/health
 *   GET /api/projects
 *   GET /api/projects/{key}/issues
 *   GET /api/projects/{key}/runs/{runId}/stream        (SSE — live trace, keyed by seq)
 *   GET /api/projects/{key}/runs/{runId}/trace?since=<seq>   (poll fallback for the stream)
 *   GET /api/projects/{key}/runs/{runId}/artifacts
 *
 * The stream currently DB-tails the trace table on a short interval; an in-process trace
 * bus (a LiveTraceSink the run publishes to) replaces that poll with a push next.
 */
final class Server
{
    public function __construct(private readonly string $projectsDir)
    {
    }

    public function run(string $host = '127.0.0.1', int $port = 8787): void
    {
        $config = (new HttpServerConfig())
            ->addListener($host, $port)
            ->setReadTimeout(15)
            ->setWriteTimeout(15)
            ->setKeepAliveTimeout(60);

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
        if ($method !== 'GET') {
            $this->json($res, 405, ['error' => 'method not allowed']);

            return;
        }

        try {
            if ($path === '/api/health') {
                $this->json($res, 200, ['ok' => true]);

                return;
            }
            if ($path === '/api/projects') {
                $this->json($res, 200, $this->projects());

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
     * Live trace for a run, as Server-Sent Events. Replays the journal tail past the client's
     * cursor (`Last-Event-ID` header on an EventSource reconnect, or `?since=`), emitting one
     * `trace` event per record with `id: <seq>` — so a dropped connection resumes with no gap.
     *
     * For now it DB-tails on a short interval; that inner poll is what the in-process trace bus
     * (a push from the run's LiveTraceSink) replaces next, leaving the replay-on-connect intact.
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

        try {
            $idleTicks = 0;
            while (!$res->isClosed()) {
                $sent = false;
                foreach ($this->trace($pdo, $runId, $since) as $rec) {
                    if (!$res->sendable()) {
                        break;   // slow client: stop piling on, retry the tail next tick
                    }
                    $res->sseEvent(
                        data: \json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        event: 'trace',
                        id: (string) $rec['seq'],
                    );
                    $since = (int) $rec['seq'];
                    $sent = true;
                }

                if ($sent) {
                    $idleTicks = 0;
                } elseif (++$idleTicks >= 60) {   // ~15s of quiet → heartbeat past proxy idle timeouts
                    $res->sseComment('hb');
                    $idleTicks = 0;
                }

                \Async\delay(250);   // yields the loop; other requests run while this run is quiet
            }
        } catch (\Throwable) {
            // The client vanished mid-write — the connection is gone, nothing to clean up (yet).
        }
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
