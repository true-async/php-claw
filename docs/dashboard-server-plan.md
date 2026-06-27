# Dashboard server: endpoints, DB, and the run engine

Plan for finishing the `claw serve` HTTP API that backs **php-claw-ui**. Covers three
things, in order: (1) the endpoint surface, (2) what the API needs from the DB, and
(3) how a `start` runs an issue as a coroutine on the server's own event loop, with live
updates pushed over an in-process trace bus.

Status today: `src/Server.php` is a **read-only, polling** JSON API. SSE and the two
write paths (`start`, `answer`) do not exist yet. The TrueAsync server now ships SSE
(`HttpResponse::sseStart/sseEvent/sseComment/sseRetry`), so the live stream is unblocked.

**Architecture in one line:** the `Tracer` already fans out to several `TraceSinkInterface`s
(durable `TraceStore`, `ConsoleTraceSink`); we add one more — a `LiveTraceSink` that publishes
each record to an **in-process trace bus**. SSE handlers *subscribe* to the bus (push, no
poll). The DB stays the durable journal for replay/catch-up. Runs execute as coroutines via
`Async\spawn` on the server loop (not a thread pool), so publisher and subscribers share one
loop and the bus is a plain `Async\Channel`.

---

## 0. The contract gap (decide this first)

The UI (`php-claw-ui/src/data/client.ts`) is written **single-project**:

```
GET  /api/project
GET  /api/issues
GET  /api/runs/{id}/stream         (SSE — trace spans + status, keyed by trace.seq)
     ↳ fallback: GET /api/runs/{id}/trace?since=seq
POST /api/issues/{id}/start
POST /api/issues/{id}/answer
```

The server (`src/Server.php`) is **multi-project**, keyed by the project's db filename:

```
GET /api/projects
GET /api/projects/{key}/issues
GET /api/projects/{key}/runs/{runId}/trace?since=<seq>
GET /api/projects/{key}/runs/{runId}/artifacts
```

**DECIDED: multi-project (B).** The server keeps serving every project under `projectsDir`
(`glob(*.db)`); routes keep the `{key}` segment. The UI's `HttpSseClient` gains a project
selector and threads `{key}` into every call — a prototype-client change, not a server
compromise. One server, many quest boards.

Everything downstream keeps `{key}`: `/api/projects/{key}/issues`,
`/api/projects/{key}/issues/{id}/start|answer`,
`/api/projects/{key}/runs/{id}/stream|trace|artifacts`. `run_id` is unique within a project
db, so `(key, runId)` is the global handle; the trace bus topics and the gate channels key on it.

---

## 1. Endpoint surface (target)

| Method | Path | Kind | Purpose |
|---|---|---|---|
| GET  | `/api/health` | read | liveness `{ok:true}` |
| GET  | `/api/projects` | read | every project `[{key,name,path}]` (exists) |
| GET  | `/api/projects/{key}/issues` | read | full `Issue[]` snapshot (board state) (exists) |
| GET  | `/api/projects/{key}/issues/stream` | **SSE** | board-level live updates: an `issue` event per changed issue |
| GET  | `/api/projects/{key}/runs/{id}/stream` | **SSE** | run-level live updates: `trace` events keyed by `seq` |
| GET  | `/api/projects/{key}/runs/{id}/trace?since=<seq>` | read | polling fallback for the run stream (exists) |
| GET  | `/api/projects/{key}/runs/{id}/artifacts` | read | run artifacts (exists) |
| POST | `/api/projects/{key}/issues/{id}/start` | **write** | enqueue the issue for execution |
| POST | `/api/projects/{key}/issues/{id}/answer` | **write** | resolve a pending human gate / reply to the ask-channel |

Two split streams, on purpose:

- **Board stream** (`/api/issues/stream`) — low frequency. Carries whole-`Issue`
  snapshots (status, `done`, tokens, gate) so the Kanban stays live without polling.
  The UI's `subscribe(onChange: (issues) => void)` maps here. *Note:* the UI today expects
  full `Issue[]`; the stream emits per-issue events, so the real `HttpSseClient.subscribe`
  reassembles the array from `getIssues()` + patches. Small UI-client change, not a redesign.
- **Run stream** (`/api/runs/{id}/stream`) — high frequency. The trace waterfall for the
  one focused/expanded run. Opened on demand, not for every card.

CORS stays permissive (vite dev on `:5173`). `OPTIONS` → 204 (already handled).

### SSE mechanics: replay from DB, then subscribe to the bus

Each handler is a coroutine on the server loop. It **catches up from the DB up to the live
edge, then attaches to the in-process bus** — so there is no polling and no gap across the
join. The `seq` autoincrement is the cursor that stitches the two halves together.

```php
$res->sseStart();                                   // commits text/event-stream headers, unblocks onopen
$since = (int) ($req->getHeader('Last-Event-ID') ?? $req->getQueryParam('since', 0));

$sub = $bus->subscribe($runId);                     // attach FIRST, so nothing published now is lost
foreach ($this->traceSince($pdo, $runId, $since) as $rec) {   // 1) replay the gap from the journal
    $res->sseEvent(data: json_encode($rec), event: 'trace', id: (string) $rec['seq']);
    $since = $rec['seq'];
}
while (!$res->isClosed()) {                          // 2) live: block on the bus, wake exactly on an event
    $rec = $sub->recv();                            // no delay(), no SELECT — pure push
    if ($rec->seq <= $since) continue;              // de-dupe the replay/live overlap by seq
    if (!$res->sendable()) { /* drop or coalesce for this slow client */ }
    $res->sseEvent(data: json_encode($rec), event: 'trace', id: (string) $rec->seq);
    $since = $rec->seq;
}
```

**Push, not poll.** Live delivery is an `Async\Channel`, fed by the `LiveTraceSink` the
`Tracer` writes to (§3). Wakeup is immediate (coroutine resume), not a ~250 ms tick, and there
are **zero SELECTs per stream while live** — the only DB read is the one-shot replay on connect.
`subscribe()` before `traceSince()` closes the race: anything published during replay is queued
on the channel and de-duped by `seq`. `Last-Event-ID` makes reconnect resume exactly where it
left off; the `?since=` endpoint is the same `traceSince()` query for clients that can't hold SSE.

A heartbeat `sseComment()` still goes out on an idle timer (a separate `Async\delay` ticker, or
piggybacked) to defeat proxy idle timeouts. `sendable()` guards a slow client so it can't wedge
the loop or balloon the buffer.

The board stream is the same shape over an `issue`-topic bus: `start`, status flips, gate
open/close, and token deltas publish an `issue` snapshot event; the handler replays current
`getIssues()` once, then pushes per-issue changes.

---

## 2. What the DB needs

Per-project SQLite (`<key>.db`). Existing tables (all already written by the CLI run path):

- `project(id, name, path, description, created_at)` — feeds `/api/project`.
- `issues(id, title, description, status, created_at)` — status ∈ `Open|InProgress|WaitingHuman|Done|Closed` (enum name), mapped to UI `open|inprogress|waiting|done|closed`.
- `runs(id, issue_id, workflow, status, created_at)` — status ∈ `running|generated|done|failed`.
- `trace(seq, run_id, span_id, parent_id, depth, phase, type, level, data, created_at)` — **the journal.** Everything live flows through here. `type='reply'` carries `usage.in/out` (tokens), `type='artifact'` carries `{label,kind,value}`.
- `workflow_state(run_id, done JSON, …)` — durable snapshot; `count(done)` = completed steps for the progress bar.

**The good news: almost nothing new is needed.** tokens, artifacts, progress, and the
whole waterfall are already derivable from `trace` + `workflow_state`. The new endpoints add
exactly one piece of state the read-only API never had to model — **the human gate**:

### New: pending question / gate

When a run blocks on the ask-channel (the human tier), the dashboard must (a) show the
`gate` text on the `waiting` card, and (b) let `POST /answer` resolve it — durably, so a
server restart or a reconnecting browser doesn't lose it.

Option (i) — **trace-only, no new table.** Record the question as a trace row
(`type='question'`, `data={prompt, answered:false}`) and the answer as `type='answer'`.
The gate text = the latest unanswered `question` for the run; `Issue.chat` = the
`question`/`answer`/`chat` rows in seq order. Resolution state lives in whether a matching
`answer` row exists. Zero schema change; chat falls out for free.

Option (ii) — **a `pending_question(run_id, span_id, prompt, answer, status, created_at)`
table.** Explicit, easy to query "is this run waiting", but duplicates what trace already
records and adds a table to keep in sync.

**Recommendation: (i).** It reuses the journal, gives `Issue.chat` (currently hardcoded
`[]` in `Server::issues()`) for free, and keeps the "trace is the source of truth"
invariant. The `question`/`answer` rows are the *durable* record (they survive restart and
feed chat); the *live* wakeup of the blocked run is a separate in-process channel, handled in
§3 — not the schema. So the gate is durable in the DB and instant over the channel, both.

Also add, for correctness of `start`:

- `runs.status` already distinguishes `running` — used to **reject a double-start** (a
  running run for the issue → 409, don't enqueue a second).

---

## 3. The run engine: `start` → `spawn` → bus / gate → `answer`

This is the heart of it. `claw serve` boots the event-loop server and holds a long-lived
**runs scope** plus the **trace bus** and a **gate-channel registry**. `POST /start` spawns
the solver pipeline as a coroutine in that scope; the `Tracer`'s `LiveTraceSink` publishes
every record to the bus (SSE subscribers wake instantly); a human gate parks the run on an
`Async\Channel` that `POST /answer` feeds.

Everything — runs, SSE handlers, the gate — lives on **one event loop in one thread**, so the
bus and gate channels are plain in-process `Async\Channel`s. No thread boundary, no
`ThreadChannel`, no per-stream polling.

### 3.1 Boot

```php
$scope = new Async\Scope();                 // owns every in-flight run; outlives each request
$bus   = new TraceBus();                    // runId → list of subscriber Channels (trace + issue topics)
$gates = new GateChannels();                // runId → Async\Channel for the pending human answer
$limit = new Semaphore($config->maxConcurrentRuns);   // throttle (replaces ThreadPool worker count)
```

**Why `spawn` on the loop, not `ThreadPool`:** a run is almost all *waiting* — async curl to
the LLM, file I/O, bash over async streams — which cooperatively yields the loop, so coroutines
give the concurrency without a thread per run. Same loop = the trace bus and gate are plain
`Channel`s (the whole reason this is cleaner than the cross-thread alternative). The throttle
the pool's queue used to give becomes a `Semaphore`: `start` acquires a slot, the run releases
it on finish; excess `start`s await a slot. If a genuinely CPU-bound op ever blocks the loop
(a huge diff/JSON), offload **that op** to a `ThreadPool`, not the whole run.

### 3.2 `POST /api/projects/{key}/issues/{id}/start`

```
1. load issue; if a run for it is already 'running' → 409 (no double-start).
2. set issue status InProgress; publish an `issue` event on the board bus.
3. Async\spawn(in $scope): $limit->acquire(); try { IssueRunner->run(); } finally { $limit->release(); }
4. respond 202 Accepted { runId }.            // do NOT await the run
```

The dashboard never waits on the run — it watches the board stream (status → `inprogress`)
and the run stream (trace records pushed live). The spawn is detached into the server scope so
it survives the request handler returning; the scope contains any crash so one run can't take
the server down.

### 3.3 `IssueRunner` — refactor `WorkflowMode::runIssue`

The current `runIssue()` is console-coupled in three spots; the run logic itself is reusable.
Extract an **`IssueRunner`** (the run pipeline minus CLI plumbing) used by both `claw run`
and the server. The three couplings to break:

| Console concern | Headless replacement |
|---|---|
| `ConsoleTraceSink(STDERR)` | **`LiveTraceSink($bus)`** — publishes each record to the bus *and* `TraceStore` still persists it (the Tracer fans out to both) |
| `ConsoleSpeaker(STDIN, STDOUT)` human tier | **`HttpGateSpeaker($gates, $store)`** (below) |
| `confirm("Run this workflow now?")` solver approval | a gate too: a `question`/`answer` round, surfaced as the UI's `workflow`-kind artifact + approve button |

The key seam already exists: `Tracer` takes a list of `TraceSinkInterface`. `claw run` passes
`[TraceStore, ConsoleTraceSink]`; the server passes `[TraceStore, LiveTraceSink]`. Live delivery
is *just another sink* — no special path, and persistence (for replay/`?since=`) is unchanged.

### 3.4 The human gate: durable in the DB, instant over a channel

A gate has two needs and two mechanisms, cleanly split:

- **Durable record** → `question`/`answer` trace rows keyed by `span_id` (§2). Survives restart,
  feeds `Issue.chat` and the `gate` text, replays to a reconnecting browser. (Writing the
  `question` row also publishes it on the bus, so the gate appears live with no extra work.)
- **Live wakeup** → an `Async\Channel` per run in `$gates`. The blocked run *awaits* it; the
  `answer` request *sends* into it. Instant resume, no polling.

```
HttpGateSpeaker::reply($prompt):                       // runs in the run's coroutine
    write trace: type='question', span_id=S, {prompt}  // durable + published on the bus → gate shows live
    setIssueStatus(WaitingHuman); publish issue event   // card → 'waiting' column
    $answer = $gates->for($runId)->recv()               // PARK the coroutine on the channel (no poll)
    write trace: type='answer', span_id=S, {answer}     // durable + chat row
    setIssueStatus(InProgress); publish issue event
    return $answer

POST /api/projects/{key}/issues/{id}/answer { text }:  // same loop, another coroutine
    require an open question for this run (else 409)     // no orphan answers
    $gates->for($runId)->send($text)                    // wake the run instantly
    respond 202
```

`pending` = a `question` span_id with no `answer` row for the same span_id (the DB is the
source of truth for "is this run waiting", so a restart mid-gate is recoverable).

Restart safety: if the server dies while a run waits, the in-memory channel and the run are
both gone — but the unanswered `question` row remains, so the gate is still visible, and the run
resumes via the existing `resumableRun` + `workflow_state` snapshot path (it re-enters the gate
and re-parks on a fresh channel). The channel is purely the live-wakeup optimization over the
durable record; losing it on restart costs nothing.

This is the same `EscalatingSpeaker(supervisor, human)` ladder as the CLI — the **supervisor
agent tier is unchanged** (it settles most escalations inline in the run, no gate). Only the
*human* tier swaps `ConsoleSpeaker` → `HttpGateSpeaker`.

---

## 4. Build order

1. **SSE run stream, replay-only first** `/api/projects/{key}/runs/{id}/stream`: ship the
   `traceSince()` replay + `Last-Event-ID` half against *existing* runs, before the bus exists.
   *(no new state, immediately a live-ish view via a short reconnect/poll)*
2. **`TraceBus` + `LiveTraceSink`**: the in-process push layer; wire the run stream's second
   half (subscribe-after-replay). Add the **board-stream** topic the same way.
3. **`IssueRunner`** extraction from `WorkflowMode::runIssue` (console → `LiveTraceSink` /
   `HttpGateSpeaker` seams).
4. **`spawn` + `Semaphore` + `POST /start`**: detached run in the server scope, 202,
   double-start guard, board event on status flip.
5. **Human gate + `POST /answer`**: `HttpGateSpeaker` (durable `question`/`answer` rows +
   `$gates` channel wakeup), `WaitingHuman` flow, `Issue.chat` from the same rows.
6. **Solver-approval gate** (replace `confirm()`), surfaced as the `workflow`-kind artifact.

Steps 1–2 are pure read/stream work and ship the live dashboard against existing runs.
Steps 3–6 add execution and the human-in-the-loop write paths.

---

## 5. Open questions

1. ~~Single vs multi-project API.~~ **DECIDED: multi-project** — routes keep `{key}`.
2. ~~Run engine.~~ **DECIDED: `Async\spawn` on the server loop** (not `ThreadPool`). Live
   delivery is a push bus and the gate is a channel, both plain in-process `Async\Channel`
   because run + SSE + gate share one loop. Throttle = a `Semaphore`. CPU-bound ops can be
   offloaded to a `ThreadPool` per-op if they ever block the loop.
3. ~~Gate state.~~ **DECIDED: trace-only for durability** — `question`/`answer` rows keyed by
   `span_id` (chat + restart-safe); the live wakeup is the `$gates` channel, not a poll.
4. ~~Live delivery.~~ **DECIDED: in-process trace bus** (`LiveTraceSink`, a third
   `TraceSinkInterface`) — SSE handlers subscribe and are pushed to. The DB is the durable
   journal for replay (`Last-Event-ID`) and the `?since=` fallback, not the live path.

All design questions are settled. Next: build step 1 (SSE run stream, replay half).
