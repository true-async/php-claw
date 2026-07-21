# TODO — agreed work, not yet started

Ordered by value, not by effort. An entry leaves this file when it lands in
[`DECISIONS.md`](DECISIONS.md) or turns out to be wrong.

Items 1–2 came from reading the [Electric Agents](https://electric.ax/docs/agents/)
documentation and asking what of it applies to a single-process runtime. We are not
adopting their architecture — only the invariants that fix something already broken here.

## 1. Waiting must live in the database, not on a stack

**Half done (#56).** A cold start no longer strands the ticket: `TraceReader::openGate()` finds a
run's unanswered question, the ledger can name the runs it still calls Running, and the server
settles them at startup and hands each ticket back to Open. The wait is visible and the ticket is
re-runnable.

**What is left:** the run is not RESUMED. `HttpGateSpeaker` still parks its coroutine on an
`Async\Channel`, and an answer that arrives while no process is serving that gate has nowhere to
go. Picking the run back up and delivering it needs a durable record of which answers have already
been consumed — otherwise a resumed run either re-asks a question that was answered, or eats the
same answer twice. The state store now carries a step's in-flight exchange (#57); this is one more
thing it should hold.

**Wanted:** waiting is a persisted state like any other. The channel stays, but demoted to
what it actually is — the runtime mechanism that delivers the answer to a live process,
never the record that a wait is in progress. A cold start must be able to see an unanswered
question and pick the run back up.

**Why this shape:** step-edge snapshots already exist (`SqliteStateStore`). This is not a
new mechanism, it is one more state the existing snapshot has to be able to hold.

**Prior art:** Electric entities consume nothing between wakes — no process, no memory, no
handler. State lives in a durable log and the handler is re-entered with a `wake` describing
why. We want the invariant, not the runtime.

## 2. Keep secrets out of prompts

**The exposure:** `Tracer::prompt()` records the prompt text, and tool spans record full
tool input and output, into the project database (`src/Trace/Tracer.php`, `Level::Debug`).
Anything said to a model is stored for as long as the project exists. `BashTool` scrubs the
child environment, so keys do not leak that way — but a key placed into a prompt by a step
is written down permanently, and no scrubbing happens on that path.

Not yet audited: whether anything currently does this. Assume nothing until it is checked.

**Wanted:** design the rule before we need it. Two halves to settle — where a secret may be
held, and how a step reaches an authenticated API without the credential passing through
model context.

**Prior art (Electric's answer):** manager-side prefetching. A worker never receives a
secret in its prompt or message, because those are persisted durably. The manager holds the
credential, calls the authenticated API itself, and passes only the resulting data down to
the worker. The privilege stays with the coordinator; the worker gets facts.

## 3. MCP — client, and later server

Neither exists today: zero references across `src/`, `config/`, `workflows/`, `bin/`.
As a client this buys the existing tool ecosystem without writing a `ToolInterface`
implementation per integration; `Registry::only()`/`with()` already models least-privilege
palettes, so imported tools have somewhere to land.

## 4. Knowledge base

`KnowledgeBaseInterface` documents Obsidian-flavoured markdown plus sqlite-vec retrieval
and has no implementation and no caller. Today the only memory that survives a run is
procedural — the generated solver classes in `WorkflowStore`. Declarative memory is the
gap: what was learned about a project, as opposed to what code was written for it.

## 5. The WebSocket channel

`/api/ws` is meant to be *the* live transport — one connection, rooms for topics, SSE kept
only as a fallback. It is the newest surface in `Server.php` and the least finished. Four
things, in the order they hurt. The first two are defects, not tuning.

### 5a. The connection has no heartbeat, so a dead one looks alive

`handleWs()` is `foreach ($ws as $message)` — it blocks on inbound frames and never sends
anything unprompted (`src/Server.php:745`). A dashboard that has subscribed and is watching
a quiet board sends nothing and receives nothing, sometimes for minutes. Any proxy with an
idle timeout closes that, and neither end finds out until the next publish is dropped into
a socket that is gone.

The SSE paths already solved this — a comment frame every ~10s (`src/Server.php:586`, `703`).
The WS path needs the same: a server-side ping on an idle timer, and a client that reconnects
when pongs stop. Until then, "live over one connection" is true only while traffic flows.

### 5b. Only run traces resume; boards do not

`wsSubscribe()` replays the journal past `since` — but only for a topic matching
`project/{key}/run/{id}/trace` (`src/Server.php:783-794`). A board room
(`project/{key}/issues`) falls through the regex and returns having sent nothing.

That leaves the board room unable to bootstrap a subscriber at all. `broadcastBoard()` diffs
against `$sent`, which is **server-global, not per-connection** (`src/Server.php:806`) — so
an issue that has not changed since the server last published it is never sent again, and a
client subscribing later simply does not receive it. The board has to be fetched over REST
first, and there is a race between that snapshot and the subscription taking effect.

Boards need what traces already have: a cursor a subscriber can resume from, so subscribe
alone is enough to arrive at a correct board.

### 5c. Board updates are polled, not pushed

`broadcastBoards()` walks every project, reloads all of its issues from SQLite, diffs them,
then sleeps two seconds — for the server's lifetime, whether or not anyone is connected
(`src/Server.php:804-819`). Work happens when nothing changed; a change waits up to two
seconds to cross a connection that is already open; cost scales with projects rather than
with activity.

The write should announce itself, with two controls borrowed from Electric's wake options:
**coalescing** (a burst arriving during a publish merges into one send — a run touching a
ticket ten times in a second is one board frame, not ten) and **`debounceMs`** (send once
the changes stop, so bursts settle into one frame while a lone change still lands in
milliseconds). The periodic tick then survives only as 5a's heartbeat.

Note this mostly deletes the diff-against-`$sent` bookkeeping rather than optimising it —
and it interacts with 5b, since a pushed board still needs a resume cursor. One change.

### 5d. Two transports doing the same job

The run trace and the board each exist twice — once as SSE with `Last-Event-ID`, once as a
WS room — with the same seq de-duplication implemented on both paths. Every fix above has to
be made once per transport or the fallback quietly diverges from the primary. Worth deciding
whether SSE stays a real fallback or goes; not worth carrying two of everything by accident.

**Not blocked on anything.** `broadcastBoards()` already runs on its own coroutine
(`src/Server.php:118`); none of this needs concurrency inside a run.

**Smaller, noted while reading:** a malformed control frame is swallowed with `continue`
(`src/Server.php:752`, `768`) — no error frame goes back, so a client that typos a topic
waits forever on silence with nothing to debug.

## 6. Stated but not true — a backend, a guard, a route table

Same shape as the prompt defects closed in #42–#49, one layer out: not a prompt promising what the code will not do, but
the configuration, the README and `ARCHITECTURE.md` promising it. Each was confirmed against
the source before being written here.

**`gemini` is an offered backend that does not exist.** `Config::AGENTS` accepts it
(`src/Config.php:28`), it has a key slot (`'gemini' => 'GEMINI_API_KEY'`, line 34), and
`.env.example:7` lists it as one of three valid values of `CLAW_AGENT`. `AgentFactory::make()`
handles `claude` and `openai-compatible` and returns `null` for it (`src/Agent/AgentFactory.php:26`)
— its docblock even says "or null if that agent is not wired yet". So one of the three
documented backends is a null every one of the five call sites has to defend against
(`Cli/SessionMode.php:66`, `Cli/WorkflowMode.php:199,237`, `Server.php:391,891`). Either wire
it or stop offering it; the current state costs a null branch everywhere and gives a user who
follows `.env.example` a failure at run time.

**The autonomous path has no permission gate**, and this is now the only part of that gap left.
The timeout was added and the README corrected; audit turned out not to be missing at all — a
run is TRACED, and `Tracer` writes every tool call and result to the project database, which is
the same record the chat keeps through a different door.

What remains needs a decision, not code. `PermissionMiddleware` asks a PERSON, and an autonomous
run has none by definition. So one of:

- **refuse** anything the policy would have prompted for — safest, and it will stop real work
  the first time a solver needs a command the policy has not seen;
- **consult the supervisor**, which is the tier already built for exactly this shape of question
  and which can now read the project for itself;
- **record and allow**, which is today's behaviour made explicit rather than accidental.

Same conversation as item 2: an unrestricted `bash` in the project folder and a durable journal
of everything said to the model are two halves of one security story.

**The route table is behind the code.** `ARCHITECTURE.md:212-223` lists nine routes. Missing:
the whole `/api/ws` WebSocket endpoint (item 5's subject), `POST issues/{id}/close|stop|delete`,
and `artifact-file`. The document is the knowledge map — a map that omits the primary live
transport sends the next reader to the fallback.

## Deferred, deliberately

- **Serverless / addressable entities.** Electric's core model. Rejected: it rewrites the
  execution model to buy scale-out we have no need for. One process is a choice.
- **Sync layer to clients** (their streams materialised into typed client collections).
  Rejected: SSE and WebSocket with a `trace.seq` cursor already resume correctly for one
  dashboard.
- **One append-only log replacing `trace` + `workflow_state` + `workflow_handoff`.**
  Rejected for now: tidier, but it fixes nothing that is broken.
- **`spawn(child, {wake:'runFinished'})` for real fan-out/fan-in** — a parent waiting on its
  children instead of `Decompose` filing tickets somebody starts later. Blocked: there is no
  concurrency inside a run, `Async\spawn` appears nowhere in `src/Workflow/`. Revisit if that
  changes. (The debounce and coalescing half of the same idea is *not* blocked on this and
  moved up to item 5.)
