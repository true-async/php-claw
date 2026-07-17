<?php

declare(strict_types=1);

namespace Claw;

use Async\Channel;
use Async\Coroutine;
use Async\OperationCanceledException;

use function Async\spawn;

use Claw\Agent\AgentFactory;
use Claw\Exceptions\ClawException;
use Claw\Http\CurlHttpClient;
use Claw\Project\IssueStatus;
use Claw\Project\Project;
use Claw\Project\ProjectStore;
use Claw\Project\ProjectStoreInterface;
use Claw\Run\HttpRunFrontend;
use Claw\Run\IssueRunner;
use Claw\Trace\TraceBus;
use Claw\Trace\TraceReader;
use Claw\Trace\TraceRecordInterface;
use Claw\Workflow\SqliteStateStore;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;
use TrueAsync\HttpServer;
use TrueAsync\HttpServerConfig;
use TrueAsync\HttpServerException;

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

    /**
     * Issue id → the detached run coroutine. Presence guards against a concurrent double-start; the
     * handle itself lets {@see stop()} cancel a run in flight.
     *
     * @var array<string, Coroutine<mixed>>
     */
    private array $active = [];

    /** @var array<string, Channel<string>> issue id → the open gate's answer channel ({@see answer()} feeds it). */
    private array $gates = [];

    /**
     * One pooled store handle per project key, opened once and reused. The handle's \PDO has TrueAsync's
     * connection pool enabled, so the SAME handle is shared by the dashboard's reads and a detached run's
     * writes — the pool hands each coroutine its own connection. There is no read/write split to manage.
     *
     * @var array<string, ProjectStoreInterface>
     */
    private array $stores = [];

    /** @var array<string, TraceReader> trace readers over the read handles, cached so a stream re-uses one. */
    private array $readers = [];

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
    public function handle(HttpRequest $request, HttpResponse $response): void
    {
        $path = \parse_url((string) $request->getUri(), PHP_URL_PATH) ?: '/';
        $method = (string) $request->getMethod();

        // permissive CORS so the local dev UI (vite :5173) can call this directly
        $response->setHeader('Access-Control-Allow-Origin', '*')
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type');

        if ($method === 'OPTIONS') {
            $response->setStatusCode(204)->end();

            return;
        }

        try {
            if ($method === 'POST') {
                if ($path === '/api/projects') {
                    $this->createProject($request, $response);

                    return;
                }

                if (\preg_match('#^/api/projects/([^/]+)/issues$#', $path, $matches)) {
                    $this->createIssue($request, $response, $matches[1]);

                    return;
                }

                if (\preg_match('#^/api/projects/([^/]+)/issues/([^/]+)/start$#', $path, $matches)) {
                    $this->start($response, $matches[1], $matches[2]);

                    return;
                }

                if (\preg_match('#^/api/projects/([^/]+)/issues/([^/]+)/answer$#', $path, $matches)) {
                    $this->answer($request, $response, $matches[1], $matches[2]);

                    return;
                }

                if (\preg_match('#^/api/projects/([^/]+)/issues/([^/]+)/close$#', $path, $matches)) {
                    $this->close($response, $matches[1], $matches[2]);

                    return;
                }

                if (\preg_match('#^/api/projects/([^/]+)/issues/([^/]+)/stop$#', $path, $matches)) {
                    $this->stop($response, $matches[1], $matches[2]);

                    return;
                }

                if (\preg_match('#^/api/projects/([^/]+)/issues/([^/]+)/delete$#', $path, $matches)) {
                    $this->delete($response, $matches[1], $matches[2]);

                    return;
                }
                $response->json(['error' => 'not found', 'path' => $path], 404);

                return;
            }

            if ($method !== 'GET') {
                $response->json(['error' => 'method not allowed'], 405);

                return;
            }

            if ($path === '/api/health') {
                $response->json(['ok' => true]);

                return;
            }

            if ($path === '/api/projects') {
                $response->json($this->projects());

                return;
            }

            if (\preg_match('#^/api/projects/([^/]+)/issues/stream$#', $path, $matches)) {
                $this->issuesStream($response, $matches[1]);

                return;
            }

            if (\preg_match('#^/api/projects/([^/]+)/issues$#', $path, $matches)) {
                $response->json($this->issues($matches[1]));

                return;
            }

            if (\preg_match('#^/api/projects/([^/]+)/runs/([^/]+)/stream$#', $path, $matches)) {
                $this->stream($request, $response, $matches[1], $matches[2]);

                return;
            }

            if (\preg_match('#^/api/projects/([^/]+)/runs/([^/]+)/trace$#', $path, $matches)) {
                $since = (int) $request->getQueryParam('since', 0);
                $response->json($this->reader($matches[1])->tail($matches[2], $since));

                return;
            }

            if (\preg_match('#^/api/projects/([^/]+)/runs/([^/]+)/artifacts$#', $path, $matches)) {
                $response->json($this->reader($matches[1])->artifactRecords($matches[2]));

                return;
            }

            if (\preg_match('#^/api/projects/([^/]+)/artifact-file$#', $path, $matches)) {
                $this->artifactFile($response, $matches[1], (string) $request->getQueryParam('path', ''));

                return;
            }

            $response->json(['error' => 'not found', 'path' => $path], 404);
        } catch (\Exception $e) {
            // A streaming route may have already committed its SSE headers; a JSON 500 over that is
            // impossible, so only answer with one when nothing has been sent yet.
            if (!$response->isHeadersSent()) {
                $response->json(['error' => $e->getMessage()], 500);
            }
        }
    }

    /** @return list<array{key: string, name: string, path: string}> */
    private function projects(): array
    {
        return array_map(
            static fn (Project $project): array => [
                'key' => $project->id,
                'name' => $project->name,
                'path' => $project->path,
            ],
            ProjectStore::all($this->projectsDir),
        );
    }

    /**
     * POST /api/projects — register an existing folder as a project (the dashboard's equivalent of
     * `claw -c <folder>`): it creates the project's state db, it does not create the folder. The folder
     * must already exist on the server's filesystem; an unknown/duplicate path is a 400 with the reason.
     */
    private function createProject(HttpRequest $request, HttpResponse $response): void
    {
        $payload = \json_decode($request->getBody(), true);
        $folder = \is_array($payload) && isset($payload['path']) ? (string) $payload['path'] : '';

        if ($folder === '') {
            $response->json(['error' => 'a project folder path is required'], 400);

            return;
        }

        try {
            $project = ProjectStore::init($this->projectsDir, $folder);
        } catch (ClawException $e) {
            $response->json(['error' => $e->getMessage()], 400);

            return;
        }

        // Drop any cached miss so the new project is readable on the next request.
        unset($this->stores[$project->id], $this->readers[$project->id]);

        $response->json(['key' => $project->id, 'name' => $project->name, 'path' => $project->path], 201);
    }

    /**
     * POST /api/projects/{key}/issues — add an issue to a project (the dashboard's equivalent of
     * `claw -i "<title>"`). Returns the new issue (201); the board's SSE feed then carries it like any
     * other change. An empty title is a 400.
     */
    private function createIssue(HttpRequest $request, HttpResponse $response, string $key): void
    {
        $payload = \json_decode($request->getBody(), true);
        $title = \is_array($payload) && isset($payload['title']) ? (string) $payload['title'] : '';
        $description = \is_array($payload) && isset($payload['description']) ? (string) $payload['description'] : '';

        try {
            $store = $this->store($key);
        } catch (\Exception $e) {
            $response->json(['error' => $e->getMessage()], 404);

            return;
        }

        try {
            $issue = $store->addIssue($title, $description);
        } catch (ClawException $e) {
            $response->json(['error' => $e->getMessage()], 400);

            return;
        }

        $response->json(['id' => (int) $issue->id, 'title' => $issue->title, 'status' => $issue->status->value], 201);
    }

    /**
     * Issues for a project, shaped to the UI's Issue model (status / progress / runs / tokens /
     * artifacts), assembled from the {@see ProjectStore} and the run's {@see TraceReader} — no SQL here.
     *
     * @return list<array<string, mixed>>
     */
    private function issues(string $key): array
    {
        $store = $this->store($key);
        $reader = $this->reader($key);
        $state = new SqliteStateStore($store->pdo());

        $issues = [];

        foreach ($store->allIssues() as $issue) {
            $runs = $store->runsFor($issue->id);
            $latest = $runs === [] ? null : $runs[array_key_last($runs)];

            $done = 0;
            $tokens = ['in' => 0, 'out' => 0, 'cached' => 0, 'normalized' => 0, 'costMicros' => 0];
            $artifacts = [];

            if ($latest !== null) {
                $runId = $latest['id'];
                $done = \count($state->load($runId)['done']);
                $tokens = $reader->tokens($runId);
                $artifacts = $reader->artifactRecords($runId);
            }

            $status = $issue->status->value;
            $issues[] = [
                'id' => (int) $issue->id,
                'title' => $issue->title,
                'desc' => $issue->description,   // the human-written brief — shown in the dashboard drawer
                'status' => $status,
                'done' => $done,
                'live' => $status === IssueStatus::InProgress->value,
                'runs' => array_map(
                    static fn (array $run): array => ['n' => (int) $run['id'], 'status' => $run['status']],
                    $runs,
                ),
                'tokensIn' => $tokens['in'],
                'tokensOut' => $tokens['out'],
                'tokensCached' => $tokens['cached'],         // prompt-cache subset of input (cheaper)
                'tokensNormalized' => $tokens['normalized'], // cost-weighted token equivalent
                'costUsd' => $tokens['costMicros'] / 1_000_000, // real money cost — the figure to react to
                'artifacts' => $artifacts,
                'chat' => [],   // the gate conversation streams as question/answer trace events, not here
            ];
        }

        return $issues;
    }

    /**
     * The one pooled store handle for a project, opened once and cached by key. Used for reads AND
     * writes from any coroutine — TrueAsync's PDO pool gives each its own connection, so there is no
     * need for a fresh handle per run. An unknown project key is a {@see \RuntimeException} (a 404 at
     * the call site).
     */
    private function store(string $key): ProjectStoreInterface
    {
        return $this->stores[$key] ??= ProjectStore::openByKey($this->projectsDir, $key)
            ?? throw new \RuntimeException("unknown project: {$key}");
    }

    /** The trace reader over a project's read handle, cached so a stream does not re-open it per tail. */
    private function reader(string $key): TraceReader
    {
        return $this->readers[$key] ??= new TraceReader($this->store($key)->pdo());
    }

    /**
     * Read a `file` artifact's contents on demand. A file artifact stores only a path relative to the
     * project ({@see \Claw\Workflow\Artifact::file()}); the dashboard fetches the bytes lazily, only when
     * a user expands it. The resolved path is confined to the project folder — a traversal outside it, a
     * missing file, or a non-file is a 4xx, never a read elsewhere on disk.
     */
    private function artifactFile(HttpResponse $response, string $key, string $relative): void
    {
        if ($relative === '') {
            $response->json(['error' => 'a file path is required'], 400);

            return;
        }

        $root = realpath($this->store($key)->project()->path);
        $full = realpath($root . '/' . $relative);

        // realpath resolves `..`, so containment is a clean prefix check on the canonical paths.
        if ($root === false || $full === false || !str_starts_with($full, $root . '/')) {
            $response->json(['error' => 'no such artifact file'], 404);

            return;
        }

        if (!is_file($full)) {
            $response->json(['error' => 'no such artifact file'], 404);

            return;
        }

        $response->json(['path' => $relative, 'body' => (string) file_get_contents($full)]);
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
    private function stream(HttpRequest $request, HttpResponse $response, string $key, string $runId): void
    {
        try {
            $reader = $this->reader($key);
        } catch (\Exception $e) {
            $response->json(['error' => $e->getMessage()], 404);   // pre-stream: a normal JSON error is fine

            return;
        }

        $since = (int) ($request->getHeader('Last-Event-ID') ?? $request->getQueryParam('since', 0));
        $response->sseStart();   // commit text/event-stream headers now, so the browser's onopen fires

        // Subscribe BEFORE the replay so nothing published mid-replay is lost; the seq check de-dupes the
        // overlap. The unsubscribe MUST run on every exit, so the topic does not leak.
        [$channel, $unsubscribe] = $this->bus->subscribe($runId);

        try {
            foreach ($reader->tail($runId, $since) as $row) {   // 1) replay the journal gap
                $this->sseRow($response, $row);
                $since = (int) $row['seq'];
            }

            while (!$response->isClosed()) {   // 2) live: pushed by the run's LiveTraceSink, no poll
                try {
                    // block for the next pushed [record, seq]; the timeout token cancels the recv after
                    // ~10s so we can heartbeat. recv throws OperationCanceledException when the token fires
                    // (Channel::recv @throws). Any other cancellation isn't caught here, so it propagates.
                    [$record, $seq] = $channel->recv(\Async\timeout(10000));
                } catch (OperationCanceledException) {
                    $response->sseComment();   // no event in ~10s: heartbeat, then re-check the connection

                    continue;
                }

                if ($seq <= $since) {
                    continue;   // already sent during the replay/overlap
                }

                if ($seq > $since + 1) {
                    // a dropped event left a gap — heal it from the durable journal, in order
                    foreach ($reader->tail($runId, $since) as $gapRow) {
                        $this->sseRow($response, $gapRow);
                        $since = (int) $gapRow['seq'];
                    }

                    continue;
                }

                if (!$response->sendable()) {
                    continue;   // slow client: skip; a later gap-heal or a reconnect replays it
                }
                $this->sseRow($response, $this->liveRow($record, $seq));
                $since = $seq;
            }
        } catch (HttpServerException) {
            // The client vanished mid-write — the connection is gone. Only the server's own write
            // failures are swallowed here; a real bug in the loop still propagates.
        } finally {
            $unsubscribe();
        }
    }

    /**
     * Emit one trace row as an SSE `trace` event keyed by its seq.
     *
     * @param array<string, mixed> $row
     */
    private function sseRow(HttpResponse $response, array $row): void
    {
        $response->sseEvent(
            data: \json_encode($row, self::JSON),
            event: 'trace',
            id: (string) $row['seq'],
        );
    }

    /**
     * Format a live (record, seq) into the same wire shape {@see TraceReader::tail()} produces from a db
     * row, so a pushed event is indistinguishable from a replayed one. The wire shape belongs here, at the
     * edge — not in the bus or the sink.
     *
     * @return array<string, mixed>
     */
    private function liveRow(TraceRecordInterface $record, int $seq): array
    {
        $event = $record->event();

        return [
            'seq' => $seq,
            'spanId' => $record->id(),
            'parentId' => $record->parentId(),
            'depth' => $record->depth(),
            'phase' => $record->phase(),
            'type' => $event->type,
            'level' => $event->level->value,
            'tsMs' => $record->at(),
            'data' => $event->data,
        ];
    }

    /**
     * Live board, as Server-Sent Events: an `issue` event per issue whose snapshot changed. This is the
     * low-frequency Kanban feed (a card moving column, a token tick, a gate opening), so unlike the run
     * stream it polls the issue snapshot on a slow tick and emits diffs — re-deriving a handful of issues
     * every couple of seconds is cheap, and the hot per-record path is the run stream, not this. On
     * connect (and on reconnect) every issue is emitted once, since the seen-set starts empty; the client
     * keeps an id→issue map and applies each event.
     */
    private function issuesStream(HttpResponse $response, string $key): void
    {
        try {
            $this->store($key);   // resolve/validate the project before committing the stream
        } catch (\Exception $e) {
            $response->json(['error' => $e->getMessage()], 404);

            return;
        }

        $response->sseStart();

        $sentSnapshots = [];   // issue id → the json it was last sent as
        $idleTicks = 0;

        try {
            while (!$response->isClosed()) {
                $changed = false;
                $present = [];

                foreach ($this->issues($key) as $issue) {
                    $id = (string) $issue['id'];
                    $present[$id] = true;
                    $snapshot = \json_encode($issue, self::JSON);

                    if (($sentSnapshots[$id] ?? null) === $snapshot) {
                        continue;
                    }

                    if (!$response->sendable()) {
                        continue;   // slow client: leave it unseen so the next tick retries
                    }
                    $sentSnapshots[$id] = $snapshot;
                    $response->sseEvent(data: $snapshot, event: 'issue');
                    $changed = true;
                }

                // an id we sent before that is gone now = a deleted issue → tell the client to drop it
                foreach ($sentSnapshots as $id => $_) {
                    if (isset($present[$id]) || !$response->sendable()) {
                        continue;
                    }

                    unset($sentSnapshots[$id]);
                    $response->sseEvent(data: \json_encode(['id' => (int) $id], self::JSON), event: 'issue-removed');
                    $changed = true;
                }

                if ($changed) {
                    $idleTicks = 0;
                } elseif (++$idleTicks >= 5) {   // ~10s of a still board → heartbeat past proxy idle timeouts
                    $response->sseComment();   // empty SSE comment = the canonical keepalive
                    $idleTicks = 0;
                }

                \Async\delay(2000);
            }
        } catch (HttpServerException) {
            // The client vanished mid-write — the connection is gone. A real bug still propagates.
        }
    }

    /**
     * POST .../issues/{id}/start — launch the issue's solver as a detached coroutine and return at once.
     * The dashboard watches progress on the run stream; the run records its own ledger row, trace and
     * final status. At most one active run per issue (a concurrent start is rejected 409), and the run's
     * gate parks on a per-issue answer channel that {@see answer()} feeds.
     */
    private function start(HttpResponse $response, string $key, string $issueId): void
    {
        try {
            $store = $this->store($key);
            $issue = $store->loadIssue($issueId);
        } catch (\Exception $e) {
            $response->json(['error' => $e->getMessage()], 404);

            return;
        }

        if (isset($this->active[$issue->id])) {
            $response->json(['error' => 'a run for this issue is already active'], 409);

            return;
        }

        $config = Config::load($this->root . '/.env');
        $agent = AgentFactory::make($config, new CurlHttpClient());

        if ($agent === null) {
            $response->json(['error' => "agent '{$config->agent}' is not wired"], 500);

            return;
        }

        /** @var Channel<string> $answers unbuffered: a gate's send() waits for the parked run's recv() */
        $answers = new Channel();
        $this->gates[$issue->id] = $answers;

        $frontend = new HttpRunFrontend($store, $issue->id, $answers, $this->bus);

        // Spawn detached so the run survives this handler returning; the dashboard watches the run stream.
        // The run records its own final status, so there is nothing to catch — only the active/gate cleanup.
        // The handle is kept in $active so stop() can cancel the run.
        $this->active[$issue->id] = spawn(function () use ($store, $config, $agent, $issue, $frontend): void {
            try {
                new IssueRunner($this->projectsDir, $store, $config, $agent, $frontend)->run($issue);
            } finally {
                unset($this->active[$issue->id], $this->gates[$issue->id]);
            }
        });

        $response->json(['ok' => true], 202);
    }

    /**
     * POST .../issues/{id}/stop — cancel an in-flight run. Cancellation unwinds the run coroutine
     * cooperatively (the IssueRunner propagates it); we then drop the issue back to Open so its card
     * leaves the in-progress column and can be started again. A 409 if no run is active.
     */
    private function stop(HttpResponse $response, string $key, string $issueId): void
    {
        $coroutine = $this->active[$issueId] ?? null;

        if ($coroutine === null) {
            $response->json(['error' => 'no run is active for this issue'], 409);

            return;
        }

        try {
            $store = $this->store($key);
        } catch (\Exception $e) {
            $response->json(['error' => $e->getMessage()], 404);

            return;
        }

        $coroutine->cancel();   // request cancellation; the run unwinds and the spawn's finally cleans up
        $store->setIssueStatus($issueId, IssueStatus::Open);
        $response->json(['ok' => true], 202);
    }

    /**
     * POST .../issues/{id}/delete — soft-delete an issue: cancel any in-flight run, then mark it Deleted.
     * The row (and its runs/trace) stays in the db, but the board hides it; the SSE feed drops the card.
     */
    private function delete(HttpResponse $response, string $key, string $issueId): void
    {
        try {
            $store = $this->store($key);
            $store->loadIssue($issueId);   // 404 if the issue does not exist
        } catch (\Exception $e) {
            $response->json(['error' => $e->getMessage()], 404);

            return;
        }

        $active = $this->active[$issueId] ?? null;
        $active?->cancel();   // stop an in-flight run before hiding the issue
        $store->setIssueStatus($issueId, IssueStatus::Deleted);
        $response->json(['ok' => true], 202);
    }

    /**
     * POST .../issues/{id}/close — move an issue to the Closed (archive) column. A plain status write;
     * the board's polling SSE feed carries the change to every client on its next tick.
     */
    private function close(HttpResponse $response, string $key, string $issueId): void
    {
        try {
            $store = $this->store($key);
            $store->loadIssue($issueId);   // 404 if the issue does not exist
        } catch (\Exception $e) {
            $response->json(['error' => $e->getMessage()], 404);

            return;
        }

        $store->setIssueStatus($issueId, IssueStatus::Closed);
        $response->json(['ok' => true], 202);
    }

    /**
     * POST .../issues/{id}/answer — deliver the human's reply to the run parked at its gate. Valid only
     * while the issue is WaitingHuman (a gate is actually open); otherwise there is nothing to answer and
     * the unbuffered send would hang, so we reject with 409.
     */
    private function answer(HttpRequest $request, HttpResponse $response, string $key, string $issueId): void
    {
        $channel = $this->gates[$issueId] ?? null;

        if ($channel === null) {
            $response->json(['error' => 'no run is waiting for an answer on this issue'], 409);

            return;
        }

        try {
            $issue = $this->store($key)->loadIssue($issueId);
        } catch (\Exception $e) {
            $response->json(['error' => $e->getMessage()], 404);

            return;
        }

        if ($issue->status !== IssueStatus::WaitingHuman) {
            $response->json(['error' => 'the run is not waiting for an answer right now'], 409);

            return;
        }

        $payload = \json_decode($request->getBody(), true);
        $text = \is_array($payload) && isset($payload['text']) ? (string) $payload['text'] : '';

        $channel->send($text);   // wake the parked run; unbuffered, so this returns once the run takes it
        $response->json(['ok' => true], 202);
    }

}
