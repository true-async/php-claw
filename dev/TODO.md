# TODO — agreed work, not yet started

Ordered by value, not by effort. An entry leaves this file when it lands in
[`DECISIONS.md`](DECISIONS.md) or turns out to be wrong.

## 0. The knowledge base becomes visible — wiki, artifacts, notifications

Ordered first because the retrieval half landed on 2026-07-22 and every project now gets a `kb/`
folder, so for the first time there is something to look at and nowhere to look at it.

**Blocked on one thing that does not exist yet, and it must not be discovered late.** Three of the
four items below are about a *write* to the base. There is no writing action — the tool offers
`search`, `about`, `read`, `tags` and nothing else, deliberately
([`design/knowledge-base-next.md`](design/knowledge-base-next.md) feature 8 argues the shape and item
4 below records the open decision). So the write action is the first piece of work, not an
assumption underneath the other three.

### 0a. A wiki in the dashboard

The whole base as a browsable thing: the note tree, one note rendered, its tags, what it links to and
what links back. `KnowledgeIndex` already holds every part of this — `notes`, `chunks(path, heading,
ord)` for an outline, `tags`, `links` both ways (the `links_target` index is built and still queried
by nothing), `refs` for "what mentions this file".

Design needed before code: what the API surface is (a listing, a note, a backlink query — REST beside
the existing project routes, or one room on the WebSocket the board already uses), and whether the
wiki reads the SQLite index or the markdown on disk. They disagree between reindexes, and which one
is authoritative is a decision, not a detail — the notes are the truth and the index is a cache of
them, so a wiki reading the index shows what a *run* would retrieve, which is arguably the more
useful view and definitely the surprising one.

### 0b. A write to the base is a step artifact

When a run edits the base, that edit becomes an artifact of the step, listed like any other and
readable afterwards. This is what makes a write reviewable without a human gate in front of it —
the same argument as feature 8's constraint 3, one layer up: the artifact is the review surface.
`Artifact::file()` already carries a path relative to the project and the dashboard fetches the body
lazily, so a note written under `kb/` fits the existing shape with nothing new underneath.

### 0c. A write rings the bell

Base edits appear in the dashboard's notification bell. Cheap once 0b exists — the artifact is
already an event on the run's trace, so this is a filter and a label rather than a new channel.

### 0d. Then, and only then, a survey of what else is worth having

Deliberately last. [`design/knowledge-base-next.md`](design/knowledge-base-next.md) already holds
nine features with sources and a rejected list with numbers; a new survey should argue with that file
rather than restart it, and it should wait until the base has been filled by real runs, because the
last round of reasoning about an empty base cost a session and was wrong in every direction
([`POSTMORTEM.md`](POSTMORTEM.md), 2026-07-22).

## 1. The WebSocket channel

`/api/ws` is meant to be *the* live transport — one connection, rooms for topics, SSE kept
only as a fallback. It is the newest surface in `Server.php` and the least finished. Four
things, in the order they hurt. The first two are defects, not tuning.

### 1a. The heartbeat is not missing — measured, and this entry was wrong

This used to read "the connection has no heartbeat, so a dead one looks alive", reasoned from
`handleWs()` being `foreach ($ws as $message)` and never sending anything unprompted
(`src/Server.php:854`). That reasoning skipped the runtime. Measured against the loaded
server extension:

```
wsPingIntervalMs default = 30000
wsPongTimeoutMs  default = 60000
```

and those survive the settings `Server::run()` actually applies — it sets read, write and
keep-alive timeouts and never touches the two above. So an idle connection is pinged every
30s and one whose peer misses a PONG for 60s is torn down with 1001, by the server, without a
line of our code. A browser answers PING at the protocol level. The extension's own note says
as much: "Handlers rarely need this."

**What is genuinely open, and is worse than what this entry claimed.** Whether
`setReadTimeout(15)` (`src/Server.php:110`) applies to the socket after the upgrade. If it
does, an idle connection dies at 15s — before the first ping at 30s — and the reconnect churn
would look exactly like a flaky network. The documentation describes the timeout as "for
receiving request" and says nothing about WebSocket. Not answerable from the stub: it needs a
running server and a connection left idle.

Also unverified here: whether the dashboard reconnects when the server does drop a dead
connection. That code is in the UI repository, not this one.

### 1b. Only run traces resume; boards do not

`wsSubscribe()` replays the journal past `since` — but only for a topic matching
`project/{key}/run/{id}/trace` (`src/Server.php:888-903`). A board room
(`project/{key}/issues`) falls through the regex and returns having sent nothing.

That leaves the board room unable to bootstrap a subscriber at all. `broadcastBoard()` diffs
against `$sent`, which is **server-global, not per-connection** (`src/Server.php:915`, `944`) — so
an issue that has not changed since the server last published it is never sent again, and a
client subscribing later simply does not receive it. The board has to be fetched over REST
first, and there is a race between that snapshot and the subscription taking effect.

Boards need what traces already have: a cursor a subscriber can resume from, so subscribe
alone is enough to arrive at a correct board.

### 1c. Board updates are polled, not pushed — but wait for item 2

**Do not fix this on its own.** The tick is not laziness, it is the only place the server
looks for what the *other* process wrote, and item 2 is the decision that removes that
process. Fixing 1c first buys a push path that still has to keep the tick as a backstop —
which is most of the code the fix was meant to delete.



`broadcastBoards()` walks every project, reloads all of its issues from SQLite, diffs them,
then sleeps two seconds — for the server's lifetime, whether or not anyone is connected
(`src/Server.php:913-928`). Work happens when nothing changed; a change waits up to two
seconds to cross a connection that is already open; cost scales with projects rather than
with activity.

The write should announce itself, with two controls borrowed from Electric's wake options:
**coalescing** (a burst arriving during a publish merges into one send — a run touching a
ticket ten times in a second is one board frame, not ten) and **`debounceMs`** (send once
the changes stop, so bursts settle into one frame while a lone change still lands in
milliseconds). The periodic tick then survives only as 1a's heartbeat.

Note this mostly deletes the diff-against-`$sent` bookkeeping rather than optimising it —
and it interacts with 1b, since a pushed board still needs a resume cursor. One change.

### 1d. Two transports doing the same job

The run trace and the board each exist twice — once as SSE with `Last-Event-ID`, once as a
WS room — with the same seq de-duplication implemented on both paths. Every fix above has to
be made once per transport or the fallback quietly diverges from the primary.

**Decided: SSE stays, as a real fallback.** A proxy that refuses to upgrade leaves the
dashboard with nothing otherwise. That makes the duplication a constraint on how 1b is built
rather than something to delete: the fix belongs in one place both transports read from, not
written twice.

The traces already have that shape — `TraceBus` pushes and `TraceWire::row()` fixes the wire
form, so a replayed row and a live one are identical on both paths (`src/Server.php:749-752`).
The board has no equivalent: `issuesStream()` (`762-822`) and `broadcastBoards()` (`913-950`)
each carry their own copy of poll-and-diff, which is exactly why 1b is broken on one of them
and not the other.

**Not blocked on anything.** `broadcastBoards()` already runs on its own coroutine
(`src/Server.php:122`); none of this needs concurrency inside a run.

**Smaller, noted while reading:** a malformed control frame is swallowed with `continue`
(`src/Server.php:856`, `862`, `868`) — no error frame goes back, so a client that typos a
topic waits forever on silence with nothing to debug.

## 2. The CLI talks to the server, not to the database

Agreed, not started. Today two processes write the same project database: the dashboard
server, and `bin/claw` running a workflow in its own process. Nothing is wrong with the file
— WAL is on and the busy timeout is set deliberately (`src/Project/ProjectStore.php:846-853`)
— but everything downstream of "there are two writers" is.

It is why the board is *discovered* rather than *announced*: `broadcastBoards()` re-reads
SQLite on a tick because a change written by the other process leaves no trace in this one's
memory (item 1c). Every fix that starts "have the write announce itself" runs into the same
wall, because the write is not always here.

**The CLI becomes a thin client.** It asks the server to start work and watches the event
stream; the server is the only process that opens the database. `Server.php:510` and `1033`
already spawn a run, so the execution side exists — what is missing is the CLI using it
instead of doing the work itself.

**The alternative was considered and rejected.** Keeping the run in the CLI process but
routing its data through the API sounds smaller and is not: `ProjectStoreInterface::pdo()`
hands out the connection itself, and ten call sites use it to open the trace journal and the
workflow state store on the same database (`Run/IssueRunner.php:175`, `Run/Triage.php:196`,
`Run/HttpRunFrontend.php:49`, `Cli/WorkflowMode.php:265`, `HttpGateSpeaker.php:53`,
`Server.php:157,212,231,525,599`). A run writes there on every model call, every tool call and
every state transition. Over HTTP that is a shared file traded for a constant conversation, on
the hottest write path there is — and the server would become mandatory anyway.

**The price, and it is real.** The server becomes required: `claw run` on its own stops
working unless the CLI starts one when it does not find it. That is the whole cost of the
change and it should be paid knowingly, not discovered later.

**What it removes.** Item 1c disappears — with one writer, the write announces itself
in-process and the tick is gone. So does the `/api/event` endpoint sketched on the way to
this, which existed only to carry the other process's news. Item 1b survives untouched: a
subscriber arriving late still needs a cursor to catch up from, no matter who wrote the rows.

**Open:** how the CLI finds a running server (a port file written by `serve`, or a probe of
the default 8787), and whether it starts one itself when there is none.

## 3. Stated but not true — a backend, a guard, a route table

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

Same conversation as the secrets work in #59/#60: an unrestricted `bash` in the project folder and a durable journal
of everything said to the model are two halves of one security story.

**The route table is behind the code.** `ARCHITECTURE.md:212-223` lists nine routes. Missing:
the whole `/api/ws` WebSocket endpoint (item 1's subject), `POST issues/{id}/close|stop|delete`,
and `artifact-file`. The document is the knowledge map — a map that omits the primary live
transport sends the next reader to the fallback.

## 4. The knowledge base, second pass

Designed, not started: [`design/knowledge-base-next.md`](design/knowledge-base-next.md) — nine
features ordered by return, what was rejected and with which numbers, and the traps found on the way.
Only the headline is repeated here.

**It has never been filled.** No project has a `kb/` folder and no `*.kb.db` exists, so the tool has
never been built into a run and the tag list in its description has always been empty. Everything
known about this subsystem comes from seven tests and two stub embedders.

**The first feature repairs something already broken.** Retrieval is dense-only at 256 dimensions, and
developer notes are full of exact strings — an error code, a flag, a file path — which is where dense
embeddings smear and lexical search is exact. FTS5 is already compiled into this build, so full-text
beside the vectors, fused by rank, costs no new dependency.

Then: a manifest so the agent knows what the base holds, required pages on the same mechanism, an
outline action, dated log pages in CHANGELOG shape, and backlinks — whose index is already built and
queried by nothing (`src/Knowledge/KnowledgeIndex.php:60`).

**Open, and needing a decision rather than code:** whether a writing action exists and in whose
palette. Framed correctly it is a question about the API surface and `Registry::only()`, not about
what an agent may be trusted to do — it has no concept of a knowledge base and only ever calls a tool.

## 5. MCP — php-claw as a server. Later, deliberately

**Not now.** Recorded so the direction is not rediscovered from scratch.

An MCP *client* was considered and rejected — see "Deferred, deliberately" below. The server
is the opposite direction and the only one that buys something the project cannot already do:
expose this project's board and runs — file a ticket, read a run's trace, fetch an artifact —
to any MCP client, so php-claw becomes something another agent drives rather than only a
thing driven from its own CLI and dashboard.

What it would rest on already exists: `ProjectStore` is the ledger, `TraceReader` replays a
run, and `Server.php`'s routes are close to the operations an MCP server would expose — the
work is a protocol surface over them, not new machinery underneath.

Worth doing after item 1, because a live transport that is still being reshaped is a poor
foundation for a second consumer of it.

## Deferred, deliberately

- **Serverless / addressable entities.** Electric's core model. Rejected: it rewrites the
  execution model to buy scale-out we have no need for. One process is a choice.
- **Sync layer to clients** (their streams materialised into typed client collections).
  Rejected: SSE and WebSocket with a `trace.seq` cursor already resume correctly for one
  dashboard.
- **One append-only log replacing `trace` + `workflow_state` + `workflow_handoff`.**
  Rejected for now: tidier, but it fixes nothing that is broken.
- **An MCP client.** Rejected: it would import tools this project has no room for. Every entry
  in `src/Tool/` is either the project's own machinery (`ProjectManagerTool`,
  `DefineWorkflowTool`, `KnowledgeTool`, `RecallTool`, `ListWorkflowsTool`, `ScheduleTool`) or
  a primitive over the workspace (`bash`, read/write/list a file, `PhpEvalTool`, `DateTool`) —
  no MCP server could have supplied any of them. What an MCP server *would* supply from
  outside (`gh`, an issue tracker, an error reporter) is already reachable through `bash`,
  which runs in the project folder with that project's credentials in its environment
  (`src/Tool/BashTool.php:97-102`). So a client adds no capability, only structure: typed
  schemas and a palette narrow enough that `bash` could be taken away. That is a security
  argument, and it belongs to item 2's permission gate — revisit it there, not on its own.
  The *server* direction is different and is item 4.
- **`spawn(child, {wake:'runFinished'})` for real fan-out/fan-in** — a parent waiting on its
  children instead of `Decompose` filing tickets somebody starts later. Not blocked, just
  unwritten: `Async\spawn`/`await` are used throughout (`src/Session.php:135`, `175`;
  `src/Server.php:122`, `510`, `1033`), and `src/Agent/Dialogue.php:52-53` already runs two
  agents concurrently and awaits both. The accurate statement is narrower — `spawn` is called
  nowhere in `src/Workflow/`, so a workflow step is sequential today by construction, not by
  constraint. (The debounce and coalescing half of the same idea is independent of this and
  moved up to item 1.)
