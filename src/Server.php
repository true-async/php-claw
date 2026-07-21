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
use Claw\Project\Issue;
use Claw\Project\IssueStatus;
use Claw\Project\Project;
use Claw\Project\ProjectStore;
use Claw\Project\ProjectStoreInterface;
use Claw\Project\RunStatus;
use Claw\Run\HttpRunFrontend;
use Claw\Run\IssueRunner;
use Claw\Run\Triage;
use Claw\Trace\TraceBus;
use Claw\Trace\Tracer;
use Claw\Trace\TraceReader;
use Claw\Trace\TraceRecordInterface;
use Claw\Trace\TraceStore;
use Claw\Trace\TraceWire;
use Claw\Workflow\SqliteStateStore;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;
use TrueAsync\HttpServer;
use TrueAsync\HttpServerConfig;
use TrueAsync\HttpServerException;
use TrueAsync\WebSocket;
use TrueAsync\WebSocketUpgrade;

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
 *   GET  /api/projects/{key}/runs/{runId}/artifacts/{n}       (one artifact's body, fetched on open)
 *   POST /api/projects/{key}/issues/{id}/start               (launch the solver, 202)
 *   POST /api/projects/{key}/issues/{id}/answer              (reply to the run's open gate)
 *   POST /api/projects/{key}/description                     (rewrite the project's Markdown brief)
 */
final class Server
{
    /** Flags for the SSE data payloads (the JSON the dashboard reads). */
    private const int JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Live trace pub/sub: a run's LiveTraceSink publishes here, SSE streams subscribe (push, no poll). */
    private TraceBus $bus;

    /** The running server, held so a detached run can publish trace into its cross-worker room. */
    private ?HttpServer $httpServer = null;

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
        $this->httpServer = $server;
        $server->enableRooms();                        // cross-worker rooms: one WS tracks every project
        $server->addHttpHandler($this->handle(...));
        $server->addWebSocketHandler($this->handleWs(...));

        spawn($this->broadcastBoards(...));   // fan issue changes out to each project's room

        echo "claw dashboard API → http://{$host}:{$port}\n";
        echo "  projects: {$this->projectsDir}\n";

        $this->adoptOrphanedRuns();

        $server->start();
    }

    /**
     * At startup, settle every run the ledger still calls Running.
     *
     * There is no live process yet, so every one of them is a leftover from a server that stopped while
     * it was working — most consequentially one parked at its human gate. That gate is two things: a
     * question in the journal, which is durable, and an `Async\Channel` the run coroutine sleeps on,
     * which is not. The channel dies with the process; the ledger row and the ticket do not. What was
     * left behind was a ticket reading WaitingHuman whose `POST .../answer` returned 409 "no run is
     * waiting" for the rest of time — a wait nobody was serving and no screen could show.
     *
     * What happens to each depends on what it was doing:
     *
     *  - AT ITS GATE — left exactly as it is. The ticket saying a person is expected is true; the only
     *    thing missing is a process, and answering brings one back (see {@see answer()}).
     *  - MID-WORK — picked back up. The snapshot says which steps finished, the persisted exchange says
     *    where the step it died in had got to, and the ledger row still reads Running, which is what
     *    {@see IssueRunner} treats as "resume this". Nothing is lost by a restart, so nothing should be
     *    paid for twice.
     *  - UNREVIVABLE — only then failed, with the ticket handed back to a person.
     */
    private function adoptOrphanedRuns(): void
    {
        foreach (ProjectStore::all($this->projectsDir) as $project) {
            try {
                $store = $this->store($project->id);
                $reader = new TraceReader($store->pdo());
            } catch (\Exception) {
                continue;   // an unreadable project must not stop the server from coming up
            }

            foreach ($store->runningRuns() as $run) {
                // A run that was WAITING is left exactly as it is. Its question is in the journal, the
                // ticket still says a person is expected, and that is all true — the only thing missing
                // was a process, and answering now brings one back (see answer()). Settling it here would
                // throw away a question somebody may be about to reply to.
                if ($reader->openGate($run['id']) !== null) {
                    echo "  waiting: run #{$run['id']} is at its gate; answering it will resume the run\n";

                    continue;
                }

                // A run interrupted mid-work is PICKED BACK UP, not written off. Everything needed for
                // that is already durable: the snapshot says which steps finished, the exchange says
                // where the step it died in had got to, and the ledger row is still Running — which is
                // exactly what IssueRunner treats as "resume this, do not start a new one".
                //
                // It used to be marked failed and handed back to a person, which was honest but wasteful:
                // the work was all there, and a restart is not a reason to throw it away and pay to do it
                // again. Failing is now only for a run we cannot revive.
                $issue = $store->loadIssue($run['issue']);

                if ($issue->status === IssueStatus::Done) {
                    $store->setRunStatus($run['id'], RunStatus::Failed);

                    continue;   // the ticket is settled; the row is just stale
                }

                if ($this->launch($project->id, $issue)) {
                    echo "  resumed: run #{$run['id']} on issue #{$issue->id}\n";

                    continue;
                }

                // No usable agent — nothing can be resumed now, so say so rather than leaving a row
                // claiming to be running while nothing is.
                $store->setRunStatus($run['id'], RunStatus::Failed);
                $store->setIssueStatus($issue->id, IssueStatus::Open);
                echo "  adopted: run #{$run['id']} cannot be resumed (no agent configured)\n";
            }
        }
    }

    /**
     * Answer a gate whose run is no longer running: record the reply, then start the run again.
     *
     * The reply is recorded FIRST and the run started second, because the order is what makes it work —
     * the resumed run reads the journal on its way into the gate, so the answer has to be there before
     * it looks. Reversed, it would ask again and the reply would land on a question nobody is at.
     */
    private function deliverToADeadGate(
        HttpResponse $response,
        ProjectStoreInterface $store,
        string $key,
        Issue $issue,
        string $text,
    ): void {
        $reader = new TraceReader($store->pdo());
        $runId = null;
        $gate = null;

        foreach ($store->runningRuns() as $run) {
            if ($run['issue'] === $issue->id && ($found = $reader->openGate($run['id'])) !== null) {
                $runId = $run['id'];
                $gate = $found;

                break;
            }
        }

        if ($runId === null || $gate === null) {
            $response->json(['error' => 'no run is waiting for an answer on this issue'], 409);

            return;
        }

        new Tracer($runId, new TraceStore($store->pdo()))->answer($gate['id'], $text);
        $this->start($response, $key, $issue->id);
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

                if (\preg_match('#^/api/projects/([^/]+)/description$#', $path, $matches)) {
                    $this->setProjectDescription($request, $response, $matches[1]);

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

            if (\preg_match('#^/api/projects/([^/]+)/runs/([^/]+)/artifacts/(\d+)$#', $path, $matches)) {
                $this->artifactBody($response, $matches[1], $matches[2], (int) $matches[3]);

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
            // description is the project's brief, sent as the raw MARKDOWN that was authored — the
            // dashboard renders it. Sending pre-rendered HTML would put the escaping decision on the
            // server and leave the client no safe way to re-render it.
            static fn (Project $project): array => [
                'key' => $project->id,
                'name' => $project->name,
                'path' => $project->path,
                'description' => $project->description,
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
        $description = \is_array($payload) && isset($payload['description']) ? (string) $payload['description'] : '';

        if ($folder === '') {
            $response->json(['error' => 'a project folder path is required'], 400);

            return;
        }

        try {
            $project = ProjectStore::init($this->projectsDir, $folder, $description);
        } catch (ClawException $e) {
            $response->json(['error' => $e->getMessage()], 400);

            return;
        }

        // Drop any cached miss so the new project is readable on the next request.
        unset($this->stores[$project->id], $this->readers[$project->id]);

        $response->json([
            'key' => $project->id,
            'name' => $project->name,
            'path' => $project->path,
            'description' => $project->description,
        ], 201);
    }

    /**
     * POST /api/projects/{key}/description — rewrite a project's brief. The body carries the Markdown
     * SOURCE; the dashboard renders it, so nothing here escapes or transforms it.
     */
    private function setProjectDescription(HttpRequest $request, HttpResponse $response, string $key): void
    {
        $payload = \json_decode($request->getBody(), true);

        if (!\is_array($payload) || !\array_key_exists('description', $payload)) {
            $response->json(['error' => 'a description is required'], 400);

            return;
        }

        try {
            $store = $this->store($key);
            $store->setDescription((string) $payload['description']);
        } catch (\Exception $e) {
            $response->json(['error' => $e->getMessage()], 404);

            return;
        }

        $response->json(['key' => $key, 'description' => $store->project()->description]);
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

        // The ticket exists now; answer at once. The ProjectManager's analysis runs BEHIND this
        // response, and its verdict reaches the board as a state change on the issue stream — so the
        // create button never waits on a model, and a model that is down cannot cost the user the
        // ticket they just typed.
        $this->triage($key, $issue);

        $response->json(['id' => (int) $issue->id, 'title' => $issue->title, 'status' => $issue->status->value], 201);
    }

    /**
     * Hand a freshly opened ticket to the ProjectManager, detached. Failure is swallowed by
     * {@see Triage::analyse()} itself — an untriaged issue is a usable issue — so nothing here needs
     * to recover; this only keeps the work off the request.
     */
    private function triage(string $key, Issue $issue): void
    {
        try {
            $config = Config::load($this->root . '/.env');
            $agent = AgentFactory::make($config, new CurlHttpClient());
        } catch (ClawException) {
            return;   // no usable agent config: the ticket stands, it simply carries no verdict yet
        }

        if ($agent === null) {
            return;
        }

        $store = $this->store($key);
        spawn(static function () use ($store, $config, $agent, $issue): void {
            new Triage($store, $config, $agent)->analyse($issue);
        });
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

            // The ProjectManager's verdict, or null while the ticket is still being analysed. This is
            // the state change the board watches arrive after a ticket is opened: creation is instant
            // and leaves this empty, and triage fills it in a moment later.
            $strategy = $store->currentStrategy($issue->id);

            $status = $issue->status->value;
            $issues[] = [
                'id' => (int) $issue->id,
                'title' => $issue->title,
                'desc' => $issue->description,   // the human-written brief — shown in the dashboard drawer
                'status' => $status,
                'type' => $issue->type?->value,   // null until triage has typed it, like the strategy below
                'strategy' => $strategy === null ? null : $strategy['strategy']->value,
                'strategyReason' => $strategy['reason'] ?? '',
                'needsHuman' => $strategy['needsHuman'] ?? false,
                'parent' => $issue->parent === null ? null : (int) $issue->parent,
                'depth' => $issue->depth,
                'done' => $done,
                'live' => $status === IssueStatus::InProgress->value,
                // Numbered WITHIN the issue, not by the global runs.id — an issue's first run showing
                // as "run #47" says nothing to the person reading the card. The trace still keys off
                // the real id; this is the label.
                'runs' => array_map(
                    static fn (int $i, array $run): array => ['n' => $i + 1, 'id' => (int) $run['id'], 'status' => $run['status']],
                    array_keys($runs),
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
    /**
     * One artifact's body, by its index in the run. The listing carries metadata only, so this is
     * what a viewer calls when someone actually opens an artifact — a run's generated source and
     * captured command output can be large, and none of it is worth holding for the ones nobody
     * looks at.
     */
    private function artifactBody(HttpResponse $response, string $key, string $runId, int $index): void
    {
        $body = $this->reader($key)->artifactBody($runId, $index);

        if ($body === null) {
            $response->json(['error' => 'no such artifact'], 404);

            return;
        }

        $response->json(['n' => $index, 'body' => $body]);
    }

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
     * Format a live (record, seq) into the wire shape a replayed row has, so a pushed event is
     * indistinguishable from one {@see TraceReader::tail()} reads back. Shared with the WebSocket room
     * publish through {@see TraceWire}.
     *
     * @return array<string, mixed>
     */
    private function liveRow(TraceRecordInterface $record, int $seq): array
    {
        return TraceWire::row($record, $seq);
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

    /** A (topic, json) publisher into the server's cross-worker rooms, or null before start(). */
    private function roomPublisher(): ?\Closure
    {
        $server = $this->httpServer;

        if ($server === null) {
            return null;
        }

        return static function (string $topic, string $payload) use ($server): void {
            $server->publish($topic, $payload);
        };
    }

    /**
     * The single WebSocket endpoint (/api/ws): one connection subscribes to project rooms and receives
     * every live event over it. Control frames are JSON — {"t":"sub","topic":...,"since":N} and
     * {"t":"unsub","topic":...}. Live rows arrive on the room from a run's LiveTraceSink; the mutating
     * commands (start/stop/answer/...) stay REST, so this carries live subscription only.
     */
    public function handleWs(WebSocket $ws, HttpRequest $request, WebSocketUpgrade $upgrade): void
    {
        $path = \parse_url((string) $request->getUri(), PHP_URL_PATH) ?: '/';

        if ($path !== '/api/ws') {
            $upgrade->reject(404, 'unknown websocket endpoint');

            return;
        }

        foreach ($ws as $message) {
            if ($message === null) {
                continue;
            }

            $cmd = \json_decode((string) $message->data, true);

            if (!\is_array($cmd)) {
                continue;
            }

            $topic = (string) ($cmd['topic'] ?? '');

            if ($topic === '') {
                continue;
            }

            try {
                match ($cmd['t'] ?? '') {
                    'sub' => $this->wsSubscribe($ws, $topic, (int) ($cmd['since'] ?? 0)),
                    'unsub' => $ws->unsubscribe($topic),
                    default => null,
                };
            } catch (\Exception) {
                // a malformed topic or a gone project must not tear the connection down
            }
        }
    }

    /**
     * Subscribe to a room, then — for a run trace topic — replay the journal past `since` to this socket.
     * Subscribing BEFORE the replay means nothing published mid-replay is lost; the client de-dupes the
     * overlap by seq, exactly as the SSE run stream does.
     */
    private function wsSubscribe(WebSocket $ws, string $topic, int $since): void
    {
        $ws->subscribe($topic);

        if (!\preg_match('#^project/([^/]+)/run/([^/]+)/trace$#', $topic, $m)) {
            return;
        }

        foreach ($this->reader($m[1])->tail($m[2], $since) as $row) {
            $ws->send(\json_encode([
                'topic' => $topic,
                'kind' => 'trace',
                'seq' => $row['seq'],
                'data' => $row,
            ], self::JSON));
        }
    }

    /**
     * Fan issue changes out to each project's room (project/{key}/issues) so a WS dashboard follows every
     * board over one connection. Runs for the server's lifetime: re-derive each project's issues on a slow
     * tick and publish only what changed — the same diff the issues SSE stream does, but once per project
     * instead of once per connection, and whether or not anyone is watching (a publish with no subscriber
     * is a cheap no-op). New projects are picked up on the next tick.
     */
    private function broadcastBoards(): void
    {
        $sent = [];   // key → (issue id → the json it was last published as)

        while ($this->httpServer !== null) {
            foreach ($this->projects() as $project) {
                try {
                    $this->broadcastBoard($project['key'], $sent);
                } catch (\Exception) {
                    // one project that fails to load must not stop the others or end the loop
                }
            }

            \Async\delay(2000);
        }
    }

    /**
     * Diff one project's issues against what was last published and emit the changes to its room.
     *
     * @param array<string, array<string, string>> $sent key → (issue id → last-published json), by reference
     */
    private function broadcastBoard(string $key, array &$sent): void
    {
        $present = [];

        foreach ($this->issues($key) as $issue) {
            $id = (string) $issue['id'];
            $present[$id] = true;
            $snapshot = \json_encode($issue, self::JSON);

            if (($sent[$key][$id] ?? null) === $snapshot) {
                continue;
            }

            $sent[$key][$id] = $snapshot;
            $this->publishBoard($key, 'issue', $issue);
        }

        foreach ($sent[$key] ?? [] as $id => $_) {
            if (isset($present[$id])) {
                continue;
            }

            unset($sent[$key][$id]);
            $this->publishBoard($key, 'issue-removed', ['id' => (int) $id]);
        }
    }

    /**
     * Publish one board event (issue / issue-removed) to a project's room.
     *
     * @param array<string, mixed> $data
     */
    private function publishBoard(string $key, string $kind, array $data): void
    {
        $this->httpServer?->publish("project/{$key}/issues", \json_encode([
            'topic' => "project/{$key}/issues",
            'kind' => $kind,
            'data' => $data,
        ], self::JSON));
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
            $issue = $this->store($key)->loadIssue($issueId);
        } catch (\Exception $e) {
            $response->json(['error' => $e->getMessage()], 404);

            return;
        }

        if (isset($this->active[$issue->id])) {
            $response->json(['error' => 'a run for this issue is already active'], 409);

            return;
        }

        if (!$this->launch($key, $issue)) {
            $response->json(['error' => 'the configured agent is not wired'], 500);

            return;
        }

        $response->json(['ok' => true], 202);
    }

    /**
     * Put a run on the event loop, detached. Returns false only when there is no usable agent.
     *
     * Separate from {@see start()} because it has two callers with nothing else in common: an HTTP
     * request, which needs a status code, and {@see adoptOrphanedRuns()} at startup, which has nobody to
     * answer. The spawning itself is the same either way, and a second copy of it would drift.
     */
    private function launch(string $key, Issue $issue): bool
    {
        $store = $this->store($key);
        $config = Config::load($this->root . '/.env');
        $agent = AgentFactory::make($config, new CurlHttpClient());

        if ($agent === null) {
            return false;
        }

        /** @var Channel<string> $answers unbuffered: a gate's send() waits for the parked run's recv() */
        $answers = new Channel();
        $this->gates[$issue->id] = $answers;

        $frontend = new HttpRunFrontend($store, $issue->id, $answers, $this->bus, $this->roomPublisher(), $key);

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

        return true;
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
        try {
            $store = $this->store($key);
            $issue = $store->loadIssue($issueId);
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
        $channel = $this->gates[$issueId] ?? null;

        if ($channel !== null) {
            $channel->send($text);   // wake the parked run; unbuffered, so this returns once the run takes it
            $response->json(['ok' => true], 202);

            return;
        }

        // No live gate: the run that asked is gone — the server was restarted, or it was killed. The
        // QUESTION is not gone, because that half was written down. So the answer is written down against
        // it and the run is started again; the gate reads the journal before it asks, finds this reply
        // waiting, and carries on from there rather than asking the same thing a second time.
        //
        // This used to be a flat 409 forever, which made a restart mean the ticket could never be
        // answered by anyone, while still claiming to be waiting for someone.
        $this->deliverToADeadGate($response, $store, $key, $issue, $text);
    }

}
