# DECISIONS

Why things are the way they are. Newest last.

---

## 2026-07-20 — The ProjectManager runs when a ticket is created

**Decision.** Triage is not a separate command. Opening a ticket *is* the trigger: the
ProjectManager fires the moment an issue is created, from either door — the dashboard's
create button (`POST /api/projects/{key}/issues`) and the CLI (`claw -i`) behave
identically.

It reads the ticket, decides how the work should be done, and records a `Strategy` on the
issue: `direct` (one agent, a localized change), `library` (a ready-made workflow fits),
`generate` (write a bespoke solver — today's only path), or `decompose` (split into
sub-issues). Running the issue later routes on that verdict instead of always generating.

**Why.** A verdict that arrives at run time is a verdict nobody can look at. Deciding at
creation puts the strategy on the board next to the ticket, where it can be read and
overridden before it spends anything. It also removes a command: there is no state in
which a ticket exists but has not been triaged.

**Cost, accepted.** Ticket creation now costs a model call and is no longer instant. The
create path has to stop being synchronous, or the caller waits on the model.

**Open.** Sub-issues created *by* a decomposition are themselves tickets, so they trigger
the ProjectManager in turn. That recursion is bounded by the depth and breadth caps in
`ProjectStore::addIssue()`, but the interaction has not been built or measured yet.

---

## 2026-07-20 — `done` is a claim the critic settles, not an exit

**Decision.** The `done` tool no longer ends a run on its own. `WorkflowAbstract::step()`
holds the signal, runs the step's critic anyway with the claim in hand, and re-raises only
on a passing verdict. A refuted claim dies with the attempt that made it.

**Why.** `WorkflowFinished` unwound straight past the critic loop, so the party being
reviewed decided whether the review happened. Measured: a worker ran `php -l`, called
`done`, and the issue closed with 8 of 10 tests erroring.

---

## 2026-07-20 — An artifact can be evidence, and evidence cannot be composed

**Decision.** `artifact()` has a third channel, `evidence:`, carrying the verbatim output
of a tool the step ran. A `text:` passed alongside is stored and shown separately as the
step's own claim about that output. A step whose critic checks a fact a command can settle
must record evidence.

**Why.** Distrusting prose only goes so far while prose is the only thing the reviewer has.
A generated step wrote the artifact `"All tests passed."` over an erroring suite and was
believed. Handing the reviewer the raw output leaves nothing to fabricate.

---

## 2026-07-20 — Decomposition limits live in the store, not in the tool

**Decision.** The depth cap, the breadth cap and the escalation rule are enforced in
`ProjectStore`, inside a transaction, rather than in `ProjectManagerTool`.

**Why.** The tool is not the only door — the CLI and the dashboard open issues through the
same `addIssue()`. A bound one caller honours is not a bound.
