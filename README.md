# php-claw

[![CI](https://github.com/true-async/php-claw/actions/workflows/ci.yml/badge.svg)](https://github.com/true-async/php-claw/actions/workflows/ci.yml)
[![Built on PHP TrueAsync](https://img.shields.io/badge/built%20on-PHP%20TrueAsync-8892BF?logo=php&logoColor=white)](https://github.com/true-async/)
[![Tested with Testo](https://img.shields.io/badge/tested%20with-Testo-2ea44f)](https://github.com/php-testo/testo)

An **AI-agent orchestration system** that **metaprograms its own logic**, built **entirely on
PHP [TrueAsync](https://github.com/true-async/)**. You give it an issue; it does not solve the
issue directly — it **writes a program to solve it**: the model generates a solver _workflow_ as
real PHP source (a `WorkflowAbstract` subclass), which is validated, approved, and then run as a
**supervised hierarchy of agent steps**. The orchestration logic is not hardcoded — it is code
the system generates, reviews, and executes at runtime. Each step is its own ReAct turn loop with
a scoped tool palette, a **critic** that reviews the step's work against a rubric, and a
**supervisor** that unblocks a stuck step or escalates to you. Every run is **durable** (resumes
from a snapshot after a crash or kill) and **fully traced** (a tinted tree you can replay).

It runs on a single thread: all the I/O — model HTTP, `bash` subprocesses, file and DB
access — is `await`ed under TrueAsync, so many agents, turns and tool calls overlap with no
callbacks and no blocking.

## The orchestration model

```
project ──▶ issue ──▶ run
                       │
                       ├─ 1. generate a solver workflow for the issue   (define_workflow)
                       ├─ 2. critic + human approve the generated code
                       └─ 3. run the solver in the project's real folder
                              step ─▶ ai turn loop ─▶ tool calls
                                 ├─ critic reviews the step (rework until it passes)
                                 ├─ supervisor unblocks / escalates when a step is stuck
                                 ├─ budget caps tokens & time
                                 └─ snapshot + handoff persisted after each step  (durable)
```

- **Project / Issue / Run ledger.** `claw -c` registers a project (its own SQLite state
  db), `claw -i` opens an issue, `claw run <id>` starts a run. Projects resolve like a git
  repo — from the current directory up to the nearest registered one.
- **Metaprogramming — a workflow that writes a workflow.** The default workflow
  (`GenerateIssueWorkflow`) sizes the issue, drafts a solver workflow as PHP source, has a critic
  review it, validates it (`WorkflowValidator` blocks dangerous code), and saves it via the
  `define_workflow` tool — generating new logic at runtime rather than hardcoding it. A solver
  already generated for an issue is reused — the project's growing *procedural memory*.
- **Human in the loop.** The generated solver is shown for approval before it ever touches
  the real folder. An autonomous run is opt-in, not the default.
- **Supervised steps.** A workflow is written as a small DSL on `WorkflowAbstract`:
  `step()`, `ai()`, `tool()`, `ask()`, `artifact()`, `critique()`. A step can carry a
  **critic rubric** (the step reworks until the critic passes or a round cap is hit) and the
  **supervisor** settles in-run escalations (`accept` / `stop` / one more concrete attempt)
  or defers to the person via the **ask channel**. The **handoff** between steps is formed
  automatically — the model summarizes what a step did for the next one.
- **Durable & resumable.** State, completed steps and the **handoff** carried between them
  are snapshotted (`SqliteStateStore`). Kill a run mid-flight and `claw run` resumes it,
  re-running only the unfinished tail. A crashing solver is repaired-and-resumed by
  `SuperviseWorkflow` up to a small cap.
- **Traced.** Every workflow → step → ai → turn → tool is recorded by the `Tracer` to live
  and stored sinks; `claw log [<id>]` replays the run as an indented, type-tinted tree
  (`NO_COLOR` or a pipe turns it plain).

The agents reach the host through a **tool layer** — `define_workflow` (write a new workflow),
`recall` (read back what a sibling step/tool/artifact did), `artifact` (record what this step
produced, and run the command whose output proves it), plus `bash`, `read_file`, `write_file`,
`list_files`.

Every call goes through an executor chain, and what guards it differs by path. The interactive
chat has the full set: a permission gatekeeper that asks you, an audit log, and a per-tool
timeout. An autonomous run has the timeout, and is traced instead of audited — every call and
result is written to the project database. It has **no permission gate**: that gate asks a
person, and an autonomous run has none. What it should do instead is an open question in
[`dev/TODO.md`](dev/TODO.md), and until it is answered a run's `bash` is unrestricted inside the
project folder you point it at.

## Architecture at a glance

| Layer | What it does |
| --- | --- |
| **Cli** | `claw` front door: parse argv, pick a mode (`WorkflowMode` default, `SessionMode` for `--session`). |
| **Workflow** | The orchestration core: `WorkflowAbstract` DSL, `Environment`/`EnvKey` scope, generation + supervision, `WorkflowValidator`, state store. |
| **Agent** | Decides the next move: pluggable backend (Claude / any OpenAI-compatible) with cause-aware retry, the ReAct `DefaultTurnLoop`, the ask-channel speakers. |
| **Tool** | Real actions behind a typed registry, run through a security/audit/timeout middleware chain. |
| **Trace** | One `Tracer` per run → live console + stored sinks; `TraceReader` replays history. |
| **Project** | The per-project ledger: projects, issues, runs. |
| **Chat / Session** | The older interactive path (`--session`): console + Telegram gateway over the same agent loop. |

The design is documented in [`ARCHITECTURE.md`](ARCHITECTURE.md).

## Requirements

- A PHP **TrueAsync** build (PHP 8.6+) with the `true_async`, `curl` and `pdo` extensions.
- Composer (for the dev tooling and autoloader).

## Quick start

```bash
composer install
cp .env.example .env             # set an API key (DeepSeek or Anthropic) and the workspace dir

php bin/claw -c                  # register the current folder as a project
php bin/claw -i "Add a --version flag"   # open an issue (prints its id)
php bin/claw run 1               # generate a solver workflow, approve it, run it
php bin/claw log                 # replay the last run's trace tree
```

Commands (`claw` is `php bin/claw` from a source checkout):

| Command | Does |
| --- | --- |
| `claw -c [folder]` | register a project (state db in the app home) |
| `claw -i "<title>"` | open an issue in the current project |
| `claw run <id>` | generate (or reuse) and run the solver workflow for an issue |
| `claw log [<id>] [-v]` | replay a run's recorded trace (`-v` raises the density) |
| `claw --session` | the older interactive chat (console / Telegram) |

`.env` selects the backend (`CLAW_AGENT` = `claude` | `openai-compatible`), the model, the
API key and the workspace directory. `openai-compatible` covers DeepSeek, Groq, Mistral,
Qwen, Ollama, OpenRouter and OpenAI — they differ only by base URL, model and key. Secrets
stay in memory and are never exposed to the `bash` tool's environment.

## Run with Docker

The image builds on the official [TrueAsync PHP image](https://hub.docker.com/r/trueasync/php-true-async),
so you do not need a local TrueAsync build.

```bash
docker build -t php-claw .
docker run --rm -it \
  -v "$PWD/.env:/app/.env" \
  -v "$PWD/workspace:/app/workspace" \
  php-claw
```

The `.env` holds your secrets and is never baked into the image, so mount it at run time.
Mounting `workspace` lets the file and `bash` tools act on a directory you can see on the host.

## Development

Run everything under the TrueAsync PHP binary:

```bash
php vendor/bin/testo            # tests (Testo)
php vendor/bin/phpstan analyse  # static analysis (level 8)
php vendor/bin/php-cs-fixer fix # coding style
```

Composer shortcuts: `composer test`, `composer analyse`, `composer cs`, `composer cs-fix`,
`composer qa` (all three).

## Status

End-to-end on a single TrueAsync thread: project/issue/run ledger, per-issue solver
generation with critic + human approval, supervised step execution (critic rubric,
supervisor escalation ladder, token/time budgets), durable snapshot-and-resume with a
repair loop, a full trace (`claw log`), the workflow tool layer (`define_workflow`, `done`,
`recall`, `bash`, `read_file`, `write_file`, `list_files`) behind a permission/audit/timeout
middleware chain, and the older session path (console + Telegram, per-conversation SQLite
history, `/stop`, inline approvals, plus the `date`/`php_eval`/`schedule` tools). Next:
hardening the autonomous-run permission policy and OS-level sandboxing of the `bash` tool.

Not affiliated with Anthropic, OpenClaw or NanoClaw.
