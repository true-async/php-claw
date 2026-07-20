# DECISIONS

Why things are the way they are. Newest last.

---

## 2026-07-20 — Creating a ticket and triaging it are two stages, not one

**Decision.** Two steps, in order:

1. **The ticket is created**, and that is all. It is written to the ledger and appears on
   the board at once. Creation stays fast and costs nothing — the same from either door,
   the dashboard's create button (`POST /api/projects/{key}/issues`) and `claw -i`.
2. **The ProjectManager then analyses it** and decides how the work should be done —
   whether a workflow is needed at all. When it decides, it **changes the ticket's state**,
   and the board shows that immediately over the existing issue stream.

The verdict is a `Strategy`: `direct` (one agent, a localized change), `library` (a
ready-made workflow fits), `generate` (write a bespoke solver — today's only path), or
`decompose` (split into sub-issues). Running the issue later routes on that verdict instead
of always generating.

**Why two stages.** Folding the analysis into creation would make the create call wait on a
model — the button would hang for seconds, and a failed model call would mean no ticket. A
ticket the user typed must exist whether or not anything downstream succeeds. Separating
them also makes the decision watchable: it lands as a state change the person sees arrive,
rather than as a property that was quietly there from the start.

**Open.**

- What the intermediate state is called — whether triage adds an `IssueStatus` of its own,
  or writes only the strategy and reuses the existing statuses. Not yet decided.
- Sub-issues created *by* a decomposition are themselves tickets, so they are triaged in
  turn. That recursion is bounded by the depth and breadth caps in
  `ProjectStore::addIssue()`, but the interaction has not been built or measured.

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

---

## 2026-07-20 — What a ticket IS, and how it is solved, are two answers

**Decision.** An issue carries an `IssueType` — `bug`, `feature`, `refactor`, `design`,
`research`, `chore` — as a single column on `issues`, alongside but separate from the
`Strategy` verdicts in `issue_strategy`. The ProjectManager records both in the one
`set_strategy` call it is allowed per triage, and the type is required: a verdict that
does not classify the ticket is refused.

**Why.** The two answer different questions and change on different clocks. A bug is still
a bug after the cheap attempt at it failed, so the type is a property of the ticket and one
column is enough; a strategy is the verdict on one attempt, and a retry is *required* to
change it — which is why the verdicts are a ledger with an escalation rule.

The type earns its place by routing: a workflow from the library declares the types it
serves, so the ProjectManager is shown only the ones that fit. Made optional, the model
would skip it and the filter would degrade to showing everything — a gate on paper again.

**Cases are added only when the PROCEDURE differs.** `refactor` is separate from `feature`
because it has no acceptance criteria at all — the existing tests passing *is* the
criterion. Two kinds of work that would be done the same way do not earn two types.

---

## 2026-07-20 — The library is two shelves, and a verdict off it names what it took

**Decision.** `library` becomes a strategy the runner can actually carry out.

- Ready-made workflows live in two places: a **global** folder at `CLAW_LIBRARY`, offered to every
  project, and a **project's own** at `.claw/workflows/` inside its repository. The project's
  wins a name clash.
- A class is offered only if it carries `#[LibraryWorkflow(...types)]`. Its **description is its
  docblock**, read by reflection. A workflow that is offered with no description, or serving no
  type, is an error when the catalogue is built — not an entry nobody can judge.
- The ProjectManager sees the shelf through `list_workflows`, **filtered by the type it just
  decided**, and records the name it chose in the verdict. `set_strategy` refuses a `library`
  verdict whose workflow does not exist or does not serve that type.

**Why the two-shelf split.** A project's own procedures belong to that project: versioned with the
code they serve, present in a checkout, editable by the person whose repository it is. What is
general belongs where the person keeps general things — hence a configured path, not a folder we
pick inside the app home.

**Why the type filter rather than an instruction.** The wrong pick is not on the list, so it cannot
be made. Telling a model to match a workflow to a ticket is one more rule to be ignored; not offering
it is not.

**Why the name is refused at record time.** It used to be recordable and unroutable: the router had
no arm for `library` and quietly generated a bespoke solver — the exact expense the verdict existed
to avoid. Worse, both rungs then ran the same code, so a `library` failure escalated to `generate`,
which had already just failed under another name. A refusal reaches the model while it still has the
ticket in mind; a run-time discovery reaches a person an hour later.

**Namespaces are per shelf.** `ClawWorkflow\Library\…`, `ClawWorkflow\Project\P<key>\…`, and the
generated solvers' `ClawWorkflow\Common\…`. The dashboard holds every registered project open in one
process, so one namespace between them would resolve a shared name to whichever autoloader
registered first, silently. There is a test that two shelves holding the same name do not shadow.
