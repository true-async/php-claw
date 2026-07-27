# INDEX — where to look

Pointers only. Nothing is explained here; this says where the explanation lives.

## Modules

The knowledge map is [`ARCHITECTURE.md`](../ARCHITECTURE.md) in the repository root
(kept there because `README.md` links to it). A layer-by-layer table is in the README's
"Architecture at a glance".

## Entry points

| What | Where |
|---|---|
| CLI front door | `bin/claw` → `src/Cli/Cli.php` → `WorkflowMode` (default) / `SessionMode` (`--session`) |
| Run pipeline | `src/Run/IssueRunner.php` — wires the environment, generates or reuses the solver, runs it, repairs a crash |
| Workflow DSL | `src/Workflow/WorkflowAbstract.php` — `step()`, `ai()`, `tool()`, `artifact()`, the critic loop |
| Default workflow | `src/Workflow/GenerateIssueWorkflow.php` — writes a solver for an issue |
| Dashboard API | `src/Server.php` — routes listed in its docblock |
| Ticket ledger | `src/Project/ProjectStore.php` — projects, issues, runs, strategy attempts |
| Tools | `src/Tool/` — registry + one class per tool; `ToolFactory` builds a run's palette |
| Tests | `tests/`, run with `php vendor/bin/testo` |

## Project conventions

- How work is done here: [`WORKFLOW.md`](WORKFLOW.md) — branches, commits, PRs, what must be green.
- Why things are the way they are: [`DECISIONS.md`](DECISIONS.md).
- What has already gone wrong: [`POSTMORTEM.md`](POSTMORTEM.md).
- What is agreed but not started: [`TODO.md`](TODO.md) — and what was deliberately rejected.

## Design documents

Per-subject designs live in [`design/`](design). One subject per file; a second pass gets its own
file rather than overwriting the first, so the reasoning behind what was built stays readable.

| Subject | Where |
|---|---|
| The knowledge base, as built | [`design/knowledge-base.md`](design/knowledge-base.md) |
| The knowledge base, second pass | [`design/knowledge-base-next.md`](design/knowledge-base-next.md) |
| A run's request to a person | [`design/human-requests.md`](design/human-requests.md) |
| Resumable workflow steps | [`design/workflow-resume.md`](design/workflow-resume.md) |
| Prior art (7 problems, borrows) | [`design/prior-art.md`](design/prior-art.md) |

## Hot paths

None measured. Nothing in this project is on a hot path worth a benchmark yet — the wall
clock is dominated by model HTTP, not by our own code. `dev/BENCHMARKS.md` stays empty
until that stops being true; see it for the rule.

## Notes

- `docs/` holds user-facing and design documents that predate this folder
  (`workflow-architecture.md`, `dashboard-server-plan.md`, `agentic-loops-survey.md`,
  `diagrams/`). They were not moved.
- The dashboard front end is a separate repository: `true-async/php-claw-ui`.
