# TODO — agreed work, not yet started

Ordered by value, not by effort. An entry leaves this file when it lands in
[`DECISIONS.md`](DECISIONS.md) or turns out to be wrong.

Items 3–5 came from reading the [Electric Agents](https://electric.ax/docs/agents/)
documentation and asking what of it applies to a single-process runtime. We are not
adopting their architecture — only the invariants that fix something already broken here.

## 1. The gates that do not hold

Found by reading every prompt in the project against the code that consumes it, and each one
confirmed against the source before being written down. They are grouped because they share a
shape: a rule stated in a prompt that nothing enforces, or a verdict parsed loosely enough that
the wrong answer passes.

**The generator never sees the ticket.** `GenerateIssueWorkflow::draftPrompt()` opens with
"Write a PHP class that solves the task below" and the task is not in it. `taskSummary()` reaches
`understand()`, `assess()` and the critic's rubric, but not the prompt that writes the solver,
which sees only `understand()`'s paraphrase. Every detail the plan dropped is unrecoverable, and
the critic judging the draft holds the real ticket text — the reviewer is better informed than
the author, which manufactures rework rounds.

**`artifact` is instructed but not wired.** `FixBugWorkflow` tells the model to call an
`artifact` tool that has never existed; both of its critic'd steps demand evidence the step
cannot record. Fixed by making `artifact` a workflow-local tool registered for every workflow —
see the decision of the same date.

**`SOLVED` is matched by prefix.** `IssueRunner::unsolvedReason()` returns null when the
uppercased answer *starts with* `SOLVED`, so "Solved for the happy path, but the null case still
fails" closes the ticket. The reverse also holds: the prompt renders the token in backticks, and
a judge that copies that formatting has its positive verdict handed back as the failure reason.

**`accept` and `stop` are matched by prefix, and silence accepts.** In
`WorkflowAbstract::superviseStep()` an empty reply and anything starting with `accept` both
accept work the critic just rejected; anything starting with `stop` kills the run. The same
prompt asks for free prose guidance, so "Stop rerunning the whole suite, run only the failing
test" aborts the run. Compare `enforceBudget()`, where silence means stop — two ask prompts give
the empty answer opposite meanings, and this one fails open.

**`assess()` inverts its own verdict.** The first token of the reply is tested with
`str_contains($word, 'complex')` before `'simple'`, so "Complexity: simple" routes the task to
the expensive tier. The comment directly above congratulates the code for avoiding this class of
bug.

**`solverReview` is a rubber stamp.** Its rubric says to judge whether the solver will actually
solve the task, then closes the reject list to three formal defects and ends "Otherwise reply
exactly: OK". A solver that edits the wrong file or does blind string surgery on source — the sin
`draftPrompt()` spends a section forbidding — matches none of the three. One of the three,
"the recipe is plainly not carried out", names a document the critic is never given.

**The validator promises more than it checks.** `draftPrompt()` heads its requirements with "the
code is validated before it is saved, and rejected if any are missed". `WorkflowValidator` checks
syntax, `name()`, `protected` steps, forbidden functions and constructs, and the expected
namespace and class. It does not check `declare(strict_types=1)`, the `use` lines, `final`,
`extends WorkflowAbstract`, that any step exists, or that every `#[Step(critic: 'x')]` has an
entry in `criticRules()` — the last of which is fully mechanical and is instead enforced by a
`LogicException` at solve time, after human approval, mid-run, discarding the work before it.

**`recall` is offered where it is not registered.** `Triage::history()` instructs the model to
open the failed run with `recall` whenever a previous attempt failed, but `RecallTool` is
registered only when `analyse()` is given a run id — which `Server.php:402` and
`Cli/WorkflowMode.php:204` do not pass.

**The decomposition caps are hidden from the model.** `MAX_DEPTH = 2` and `MAX_CHILDREN = 8` are
never named in the `Decompose` prompt, which instead tells the model that work left over after a
refused call "belongs in the pieces you already opened" — something it cannot make true, as no
action edits a sub-issue. The tail of a ticket is dropped by instruction.

**`needs_human` names two things.** It is an action on `project_manager` that parks a ticket, and
a boolean on `set_strategy` meaning "a person approves first". `Triage::history()` says "set
needs_human=true" on the one path where the action is required and every strategy is refused.

## 2. Generation strategies: prose on the shelf

The shelf holds ready-made workflow classes. It should also hold **generation strategies** —
prose describing how work of a kind is structured, fed to the generator, which writes a solver
for the ticket in front of it. The reasoning, and the full option list the ProjectManager chooses
from, are in the decision of 2026-07-21.

Not started, and deliberately after item 1: a strategy is a text poured into the generator, and
the generator currently writes solvers without reading the ticket and is reviewed by a rubric
that cannot reject them on substance. A good recipe through that funnel produces the same defects
at a higher price.

## 3. Waiting must live in the database, not on a stack

**Now:** `HttpGateSpeaker` parks the run coroutine on an `Async\Channel` and blocks until
`POST …/answer` pushes a reply. The wait exists only as a suspended stack frame. Restart
the process and the run is gone, while the ticket still reads `WaitingHuman` — the ledger
claims a wait that nobody is serving.

**Wanted:** waiting is a persisted state like any other. The channel stays, but demoted to
what it actually is — the runtime mechanism that delivers the answer to a live process,
never the record that a wait is in progress. A cold start must be able to see an unanswered
question and pick the run back up.

**Why this shape:** step-edge snapshots already exist (`SqliteStateStore`). This is not a
new mechanism, it is one more state the existing snapshot has to be able to hold.

**Prior art:** Electric entities consume nothing between wakes — no process, no memory, no
handler. State lives in a durable log and the handler is re-entered with a `wake` describing
why. We want the invariant, not the runtime.

## 4. Idempotency of a step — half exists, the contract does not

**What we already have:** resumability. The snapshot records which steps finished, and a
resumed run skips them.

**What we do not have:** safety against a step re-running. A crash *inside* a step replays
that whole step, and the database does not undo the effects it already had — an appended
file, a created ticket, a pushed commit. Nothing anywhere states that a step must survive
being executed twice with the same input, so nothing checks it.

**Wanted:** make it an explicit contract of the workflow DSL — a step body must be safe to
re-execute — and have `WorkflowValidator` reject the shapes that obviously break it.

**Prior art:** Electric documents at-least-once delivery and requires handlers to be safe
for re-execution with identical inputs. The requirement is stated, so it can be relied on.

## 5. Keep secrets out of prompts

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

## 6. MCP — client, and later server

Neither exists today: zero references across `src/`, `config/`, `workflows/`, `bin/`.
As a client this buys the existing tool ecosystem without writing a `ToolInterface`
implementation per integration; `Registry::only()`/`with()` already models least-privilege
palettes, so imported tools have somewhere to land.

## 7. Knowledge base

`KnowledgeBaseInterface` documents Obsidian-flavoured markdown plus sqlite-vec retrieval
and has no implementation and no caller. Today the only memory that survives a run is
procedural — the generated solver classes in `WorkflowStore`. Declarative memory is the
gap: what was learned about a project, as opposed to what code was written for it.

## 8. The WebSocket channel

`/api/ws` is meant to be *the* live transport — one connection, rooms for topics, SSE kept
only as a fallback. It is the newest surface in `Server.php` and the least finished. Four
things, in the order they hurt. The first two are defects, not tuning.

### 8a. The connection has no heartbeat, so a dead one looks alive

`handleWs()` is `foreach ($ws as $message)` — it blocks on inbound frames and never sends
anything unprompted (`src/Server.php:745`). A dashboard that has subscribed and is watching
a quiet board sends nothing and receives nothing, sometimes for minutes. Any proxy with an
idle timeout closes that, and neither end finds out until the next publish is dropped into
a socket that is gone.

The SSE paths already solved this — a comment frame every ~10s (`src/Server.php:586`, `703`).
The WS path needs the same: a server-side ping on an idle timer, and a client that reconnects
when pongs stop. Until then, "live over one connection" is true only while traffic flows.

### 8b. Only run traces resume; boards do not

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

### 8c. Board updates are polled, not pushed

`broadcastBoards()` walks every project, reloads all of its issues from SQLite, diffs them,
then sleeps two seconds — for the server's lifetime, whether or not anyone is connected
(`src/Server.php:804-819`). Work happens when nothing changed; a change waits up to two
seconds to cross a connection that is already open; cost scales with projects rather than
with activity.

The write should announce itself, with two controls borrowed from Electric's wake options:
**coalescing** (a burst arriving during a publish merges into one send — a run touching a
ticket ten times in a second is one board frame, not ten) and **`debounceMs`** (send once
the changes stop, so bursts settle into one frame while a lone change still lands in
milliseconds). The periodic tick then survives only as 8a's heartbeat.

Note this mostly deletes the diff-against-`$sent` bookkeeping rather than optimising it —
and it interacts with 8b, since a pushed board still needs a resume cursor. One change.

### 8d. Two transports doing the same job

The run trace and the board each exist twice — once as SSE with `Last-Event-ID`, once as a
WS room — with the same seq de-duplication implemented on both paths. Every fix above has to
be made once per transport or the fallback quietly diverges from the primary. Worth deciding
whether SSE stays a real fallback or goes; not worth carrying two of everything by accident.

**Not blocked on anything.** `broadcastBoards()` already runs on its own coroutine
(`src/Server.php:118`); none of this needs concurrency inside a run.

**Smaller, noted while reading:** a malformed control frame is swallowed with `continue`
(`src/Server.php:752`, `768`) — no error frame goes back, so a client that typos a topic
waits forever on silence with nothing to debug.

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
  moved up to item 8.)
