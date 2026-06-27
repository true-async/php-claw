# php-claw — Architecture

A per-issue **autonomous solver** for software projects, built entirely on PHP
**TrueAsync**. You register an external project folder, open issues against it, and
`claw run <id>` drives an issue to resolution. A second face — `claw serve` — is a
JSON + SSE dashboard API that runs and observes those issues live.

## Core idea: a workflow that writes a workflow

`claw run <id>` does **not** solve the issue directly. The default entry workflow,
`GenerateIssueWorkflow`, is a *workflow that writes a workflow*: it generates a PHP
**solver class** tailored to that one issue, saves it as the project's procedural
memory (via the `define_workflow` tool), a human approves the generated code, and
then that solver runs against the real project folder. A solver already generated
for an issue is reused. Crashes are auto-repaired (a supervisor rewrites the class)
and resumed from a durable snapshot.

So claw's unit of work is a **run**: one execution of one issue's solver, with a
ledger row, a trace journal, and a resumable state snapshot tying it together.

## Concurrency: single thread, all coroutines

Everything claw does is **I/O-bound**: async curl to the model, `bash` over async
streams, SQLite. Under TrueAsync these `await` and cost no CPU while suspended, so
there is **one process, one reactor, a coroutine per unit of work — no ThreadPool**.

- `claw run` is a single foreground run on the reactor.
- `claw serve` boots `TrueAsync\HttpServer`; **every request handler is a coroutine**
  on the one event loop. `POST .../start` `Async\spawn`s the run as a *detached
  coroutine*, so it outlives the request; the handler returns 202 at once and the run
  records its own final status. Because runs, SSE streams, and the human gate all share
  one loop, the trace bus and the gate are plain in-process `Async\Channel`s — no thread
  boundary.

`Tracer` is synchronous and single-stack; parallel sub-workflows (a per-coroutine
span stack) are a known limitation.

## Projects & state

A **project** is an external working tree (a folder, maybe a git repo) that lives
elsewhere on disk; claw never creates or mutates that folder. It owns only the
*application-side* state: **one SQLite file per project** under the app home
(`<workspace>/projects/<key>.db`), keyed by the folder's absolute path
(`ProjectStore::keyFor` slugifies it). `ProjectStore::discover` walks up from the cwd
to the nearest registered project, the way git finds the repo root. One open `\PDO`
is shared by the state store and the tracer.

Tables (the source of truth is the code that creates them):

| table | columns | role |
|---|---|---|
| `project` | `id, name, path, description, created_at` | the registered folder |
| `issues` | `id, title, description, status, created_at` | status = enum **name** (`Open\|InProgress\|WaitingHuman\|Done\|Closed`) |
| `runs` | `id, issue_id, workflow, status, created_at` | the ledger; status = enum **value** (`running\|generated\|done\|failed`) |
| `trace` | `seq, run_id, span_id, parent_id, depth, phase, type, level, data, created_at` | the journal; `seq` is the global resume cursor |
| `workflow_state` | `run_id, state, done, updated_at` | durable run snapshot (JSON) |
| `workflow_handoff` | `run_id, from_step, handoff, updated_at` | the step-to-step baton, persisted |
| `state_seq` | `id` | monotonic leaf-call ids |

## CLI surface (`Claw\Cli\WorkflowMode`)

- `claw -c [folder]` — register an external folder, create its `<key>.db`. The only
  command that does not resolve an existing project.
- `claw -i "<title>"` — open an issue in the resolved project.
- `claw run <id>` — generate/run the solver for an issue (loads the full `Config`;
  needs an API key). Wires the console seams and delegates to `IssueRunner`.
- `claw log [runId]` — print a run's recorded trace tree via `TraceReader`
  (read-only, no API key). Defaults to the latest run.
- `claw serve [--host H] [--port N]` — boot the dashboard `Server`
  (default `127.0.0.1:8787`). Requires the TrueAsync server extension.

Cross-cutting: `--project <dir>` / `-C` (also `CLAW_PROJECT`), `-q`/`-v` verbosity.
`--session` reaches the legacy chat mode.

## The run pipeline (`Claw\Run\IssueRunner`)

`IssueRunner` is the shared headless engine behind **both** `claw run` and the
server's `POST start`. `IssueRunner::run(Issue)`:

1. Build the environment against the **real project folder**: a `Workspace`, a
   `Registry` of tools, a `SqliteStateStore` + `TraceStore` on the project PDO,
   role models and budgets from `Config`.
2. Resolve the solver class (`Issue<id>Solver`). `ProjectStore::resumableRun` reuses
   the runId of an interrupted run (still `running`), else `recordRun`. Issue →
   `InProgress`.
3. `ensureSolver`: reuse the solver on disk, or run `GenerateIssueWorkflow` to write
   it, then call the **`$approve`** seam. Declined → run `generated`, stop.
4. `runSolver`: instantiate the solver, `->run()`. `WorkflowFinished` (the `done`
   tool) = clean finish. Any `\Throwable` → **repair-and-resume**: `SuperviseWorkflow`
   (supervisor role) writes a fixed class `…R<n>`, the same runId resumes from its
   snapshot (finished steps skipped), bounded by `MAX_REPAIRS = 2`. Success → run +
   issue `Done`.

The pipeline holds no I/O opinion — it takes one **`RunFrontendInterface`** (in
`Claw\Run`), so console vs server differ only in which frontend is injected:

| method | console — `ConsoleRunFrontend` | server — `HttpRunFrontend` |
|---|---|---|
| `human(Tracer): SpeakerInterface` | `ConsoleSpeaker` | `HttpGateSpeaker` (parks on the answer `Channel`) |
| `approveSolver(path, code): bool` | show + `confirm` | auto-`true` |
| `report(msg, isError): void` | STDOUT/STDERR | discard (dashboard reads the journal) |
| `traceSinks(\PDO): array` | `ConsoleTraceSink` | `LiveTraceSink` (publishes to the `TraceBus`) |

`human` takes the run's `Tracer` because the HTTP gate records through it, and the tracer
only exists once the environment is built.

## Workflows (`Claw\Workflow`)

A workflow is a PHP class — `WorkflowAbstract` is a **helper, not an engine**. State is
the subclass's own typed fields; the base offers one narrow surface:

- `ai(prompt, ?tools, ?agent)` — drive the model (the work happens here; the model
  calls tools / `ask`s, never the PHP).
- `tool(name, params)` — call a tool (errors come back as a string, never thrown).
- `step()`, `ask()`, `artifact()`, `critique()`, `criticRules()`, `param()`, `log()`.

Mechanics:

- **`#[Step]`** marks a `protected` method (the validator rejects public/private). The
  default `run()` drives them in declaration order; a subclass may override `run()`
  with plain `if/while` for branching/looping phases.
- **Critics** — a step may declare `#[Step(critic: '<name>', maxRounds: N)]`. After the
  method runs, its result is judged on the **reviewer** role against
  `criticRules()['<name>']` (a name with no rule fails the run). The critic is a full
  AI with every tool — it opens the artifacts, runs `php -l`/tests, and re-runs the
  step until it passes (default cap `DEFAULT_MAX_ROUNDS = 50`, then escalates).
- **The ask channel** is a ladder, `EscalatingSpeaker(first, …rest)`: ask each tier,
  first non-null wins, null passes up. In a run it is
  `EscalatingSpeaker(supervisorAgent, human)` — a tool-less **supervisor agent**
  settles `accept` / `stop` / guidance on its own judgement, and only `ESCALATE`
  (→ null) reaches the human tier.
- **Durable resume is snapshot-based, not replay.** After each step the base saves
  `{state, done}` to `workflow_state`; construction restores the fields and `done`
  skips finished steps. The durability boundary is the **step edge** — a crash
  mid-step re-runs that whole step.
- **Handoffs** — a selective context baton: after a step the model is asked, in that
  step's own history, to summarise what the next step must watch for; it is persisted
  to `workflow_handoff` so a resumed process restores it without re-asking.
- **`Environment` / `EnvKey`** — a scoped key→value with a parent link, so
  project → issue → workflow → sub-workflow inherit (worker, registry, model, store,
  tracer, ask channel, budgets…).

`WorkflowStore` writes/loads generated classes; `WorkflowValidator` is the safety gate
before saving; `DefineWorkflowTool` is the `define_workflow` door.

## Agents (`Claw\Agent`)

- **`AgentInterface`** — one method, `send(AgentRequest): AgentResponse`: a single model
  round-trip (text or tool-use). It never executes tools.
- **`AbstractAgent`** — `send()` wraps the provider-specific `attempt()` with cause-aware
  retry (`BackoffAgentRetryPolicy`, typed exceptions classifying transient vs permanent),
  suspending via `Async\delay`. `CurlHttpClient` is a single request (no retry).
- Concrete: **`ClaudeAgent`** (Anthropic Messages) and **`OpenAiCompatibleAgent`** (Chat
  Completions — DeepSeek / Groq / Mistral / Qwen / Ollama / OpenRouter / OpenAI). A
  `gemini` config value is accepted but **not yet wired** in `AgentFactory::make`.
- **Role tiers** differ only by model. `SpeakerRole`: `Worker, Reviewer, Supervisor,
  Planner, Human`, plus `*-smart` tiers. `CLAW_AGENT_<ROLE>=<model>` → `Config::$agents`
  → `EnvKey::Agents`; `ai(…, agent: 'reviewer')` routes by name, an unknown role falls
  back to the scope model. `DefaultTurnLoop` is the ReAct loop; `Budget` caps tokens +
  time along the parent chain.

## Tools (`Claw\Tool`)

```php
interface ToolInterface {
    public function name(): string;
    public function description(): string;
    public function inputSchema(): array;          // JSON Schema
    public function risk(): Risk;                   // Safe | Mutating | Dangerous
    public function handle(array $input): string;   // tool_result text; may await
}
```

The run-path tool set wired in `IssueRunner`: **`bash`, `read_file`, `write_file`,
`list_files`, `define_workflow`, `done`** (`FinishTool`, throws `WorkflowFinished`),
and **`recall`** (the run's own journal + task brief, added once the tracer exists).
A workflow may also expose its own `#[Tool]`-annotated methods. `done` ends the whole
run — it means the deliverable exists and is verified, not "this step finished".

## Tool execution & security (`Claw\Exec`, `Claw\Permission`)

`ChainExecutor` runs a middleware onion (`AuditMiddleware`, `PermissionMiddleware`,
`TimeoutMiddleware`) around a terminal that resolves and `await`s the tool.

**Honest note:** the *autonomous run path* (`Environment::executor()`) builds the chain
with an **empty middleware list** — no permission gate, no timeout, no audit. An
autonomous run is effectively allow-all; its safety story today is observability (the
`Tracer`) plus the human gate, not a permission layer. The middlewares + `Policy`
(deterministic denylist, then Safe→allow / Mutating→confirm / Dangerous→deny) are used
**only** by the legacy chat path. A real autonomous-bash policy is a known gap.

## Tracing (`Claw\Trace`)

`Tracer` is the single typed recorder per run: hierarchical `enterWorkflow / enterStep
/ enterAi / enterTurn` + `exit`, and point events `prompt / reply / toolCall /
toolResult / log / artifact / handoff / question / answer`. It holds a parent stack +
depth and fans each `TraceRecord` out to every `TraceSinkInterface` (a failing sink
never breaks the run). `reply` carries token usage, so the journal doubles as a cost
ledger. The span hierarchy is **workflow → step → ai → turn → reply / tool**.

Sinks: `TraceStore` (durable `trace` table), `ConsoleTraceSink` (live stderr tree for
`claw run`), `LiveTraceSink` (publishes to the `TraceBus` for the server),
`ArrayTraceSink` (tests). `TraceReader` + `claw log` render the tree back.

## Dashboard server (`src/Server.php`)

Boots `TrueAsync\HttpServer` and routes every request through one `handle()` coroutine
(permissive CORS, `OPTIONS`→204). It holds a `TraceBus`, an `$active` double-start
guard, `$gates` (issue id → answer `Channel`), and per-project caches of reused read
handles (`$readStores` / `$readers`) so a stream does not re-open the db each tail.

```
GET  /api/health
GET  /api/projects
POST /api/projects                                      {path} — register a folder (201; `claw -c`)
GET  /api/projects/{key}/issues
GET  /api/projects/{key}/issues/stream                  SSE — board (an `issue` event per change)
GET  /api/projects/{key}/runs/{id}/stream               SSE — live trace, keyed by seq
GET  /api/projects/{key}/runs/{id}/trace?since=<seq>    poll fallback for the run stream
GET  /api/projects/{key}/runs/{id}/artifacts
POST /api/projects/{key}/issues/{id}/start              launch the solver (202)
POST /api/projects/{key}/issues/{id}/answer             reply to the run's open gate
```

**Run stream — push, not poll.** A server-started run's tracer fans out to a
`LiveTraceSink` that, after `TraceStore` persists a record, reads its `seq` and
publishes the formatted row to the `TraceBus`. The SSE handler subscribes to the bus
*before* replaying the journal gap (`since` from `Last-Event-ID`/`?since=`), then
blocks on `channel->recv(Async\timeout(10s))` — pure push, with a ~10s heartbeat on
timeout, seq de-dupe of the replay/live overlap, and a gap-heal from the DB on any seq
discontinuity. A pushed row is byte-identical to a replayed one.

**Board stream — a deliberate poll.** The Kanban is low-frequency, so `issuesStream`
re-derives the issue snapshot every ~2s and emits an `issue` event per issue whose JSON
changed. Only the hot per-record path (the run stream) needed push.

**Start / gate.** `start` rejects a concurrent run for the same issue (409), then
`Async\spawn`s the `IssueRunner` detached, with `HttpRunFrontend` wiring `HttpGateSpeaker`
as the human tier.
When the supervisor escalates, the gate writes a `question` trace row, flips the issue
to `WaitingHuman`, and **parks the run coroutine** on the answer channel; `answer`
(valid only while `WaitingHuman`) sends the reply, the gate writes an `answer` row and
the run resumes. The question/answer rows are the durable record, the channel is the
live wakeup — so a restart keeps the gate visible and the run resumes from its snapshot.

Run it with the server extension:

```
php -d extension=/path/to/true_async_server.so bin/claw serve [--port 8787] [--host 127.0.0.1]
```

## Config (`Claw\Config`, `.env`)

- `CLAW_AGENT` (`claude` | `openai-compatible` | `gemini`), `CLAW_MODEL`, `CLAW_BASE_URL`.
- Keys: `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / `GEMINI_API_KEY`, or `CLAW_API_KEY`.
- Role models: `CLAW_AGENT_<ROLE>=<model>` (e.g. `CLAW_AGENT_WORKER_SMART`).
- Budgets: `CLAW_BUDGET_TOKENS/SECONDS`, `CLAW_TURN_TOKENS/SECONDS`, `CLAW_BUDGET_POLICY`
  (`stop`|`ask`), `CLAW_MAX_HISTORY`.
- Paths: `CLAW_WORKSPACE` (app home), `CLAW_PROJECT` (project override).

Secrets stay in memory on `Config` and are never exported to the environment, so `bash`
subprocesses do not inherit them.

## File layout (`src/`)

```
Config.php  Server.php  HttpGateSpeaker.php  Session.php(legacy)
Cli/        Cli.php  WorkflowMode.php  SessionMode.php
Run/        IssueRunner.php  RunContext.php  RunFrontendInterface.php
            ConsoleRunFrontend.php  HttpRunFrontend.php
Workflow/   WorkflowAbstract.php  WorkflowInterface.php  Step.php  Tool.php  MethodTool.php
            Environment.php  EnvKey.php  GenerateIssueWorkflow.php  SuperviseWorkflow.php
            WorkflowStore.php  WorkflowValidator.php  SqliteStateStore.php
            InMemoryStateStore.php  WorkflowStateStoreInterface.php  Artifact.php  BudgetPolicy.php
Agent/      AgentInterface.php  AbstractAgent.php  ClaudeAgent.php  OpenAiCompatibleAgent.php
            DefaultTurnLoop.php  AgentSpeaker.php  ConsoleSpeaker.php  EscalatingSpeaker.php
            SpeakerInterface.php  SpeakerRole.php  Budget.php  AgentRequest/Response  …
Tool/       ToolInterface.php  Registry.php  Risk.php  Workspace.php  BashTool.php
            ReadFileTool.php  WriteFileTool.php  ListFilesTool.php  RecallTool.php
            DefineWorkflowTool.php  FinishTool.php  (legacy: DateTool, PhpEvalTool, ScheduleTool)
Exec/       ExecutorInterface.php  ChainExecutor.php  MiddlewareInterface.php
            AuditMiddleware.php  PermissionMiddleware.php  TimeoutMiddleware.php
Permission/ Policy.php  Decision.php  Verdict.php
Trace/      Tracer.php  TraceStore.php  ConsoleTraceSink.php  LiveTraceSink.php  TraceBus.php
            ArrayTraceSink.php  TraceReader.php  TraceFormat.php  TraceEvent/Record  Level.php
Project/    Project.php  ProjectStore.php  Issue.php  IssueStatus.php  RunStatus.php
Http/       HttpClientInterface.php  CurlHttpClient.php  HttpResponse.php
Exceptions/ ClawException.php  WorkflowFinished.php  + typed agent/http/tool/config errors
Chat/  Store/   (legacy: Telegram + console chat, SessionStore)
Knowledge/      (forward-looking skeleton: declarative memory / KB, not yet wired)
```

## Legacy

The original claw was an interactive **Telegram/console chat bot**; that code still
exists but is reached only via `claw --session` (`Cli\SessionMode`): `src/Chat/*`,
`src/Session.php`, `src/Store/SessionStore.php`, the `CLAW_CHANNEL=telegram` config and
its allowlist, the chat-only tools (`current_date`, `php_eval`, `schedule`), and the
`Exec` middleware chain + `Permission\Policy` (wired only on this path). The autonomous
workflow system above is the focus; the legacy chat path is left as-is.
