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

---

## 2026-07-20 — The first shelved workflow, and where the shipped ones live

**Decision.** `workflows/` in this repository is the library that ships with claw, and
`CLAW_LIBRARY` defaults to it — resolved against the package, not the current directory. Its
contents are linted, analysed and catalogue-tested like `src/`.

The first entry is `FixBugWorkflow`: reproduce as a failing test → fix the production code →
run the whole suite. Two of its three steps carry a critic, and both rubrics judge **evidence**
— the verbatim output of a command — rather than the step's account of it.

**Why the default resolves against the package.** A relative `./workflows` would mean the
built-in workflows existed or not depending on which directory the command was run from.

**Why these three steps.** The generator's own recipe warns against ceremonial phases, and an
earlier draft had four: "reproduce" and "pin it with a failing test" were the same work described
twice. What is left is three steps that each leave a result, and the boundaries fall exactly where
a fresh context earns its cost — demonstrating the bug, fixing it, and proving nothing else broke.

**What is NOT claimed.** These tests check that the workflow is whole and offered for the right
kind of ticket. Whether its prompts and rubrics actually hold up is settled by running it on a
real bug, not by the suite.

---

## 2026-07-20 — Repair answers one question: is this workflow's code broken?

**Decision.** The supervisor's repair-and-resume loop is entered only when the failure could
actually be a defect in the workflow's code. Two things now stop it:

- An `AgentException` — the model backend refusing or failing (a malformed request, a rate limit,
  an outage, a spent quota) — is reported, never repaired. The taxonomy for this already existed
  in `AgentErrors::classify()`; the repair boundary simply never asked.
- A workflow from the LIBRARY is never repaired at all, whatever the error.

**Why.** Found by running `FixBugWorkflow` on a real bug. The run died on a 400 —
`An assistant message with 'tool_calls' must be followed by tool messages` — which is our own
malformed history, not broken code. `runSolver()` caught it as it catches everything, decided the
solver was broken, and sent the supervisor to rewrite it. The supervisor then read the source from
the project's generated-workflow store, where a library workflow is not, got an empty string, and
invented a replacement class out of the error text alone.

**Why the library is excluded even from a genuine code defect.** A shelved workflow is written and
reviewed by a person and shared by every ticket that picks it. A per-run rewrite would leave the
original in place for the next ticket while this one quietly finished on the model's version — and
the defect nobody was told about would still be on the shelf.

---

## 2026-07-20 — A history that stops mid-turn still answers every tool it asked for

**Decision.** When the turn budget is spent right after the model requested tools, the loop
appends a result block for every unanswered call before returning.

**Why.** That history does not end there. A critic re-run continues it, and so does the handoff.
Both backends reject an assistant message carrying `tool_calls` with no matching `tool_result`,
so continuing it produces a 400 — nowhere near where the damage was done.

Measured: a live run stopped at turn 19 immediately after the model asked to write a file, the
critic rejected the step, and the re-run died on *"An assistant message with 'tool_calls' must be
followed by tool messages"*. Two steps of misdirection later, that surfaced as "the solver crashed"
and sent the supervisor to rewrite perfectly good code.

The `done` path already closed its calls for exactly this reason, with a comment saying so. This
exit was the one that did not.

---

## 2026-07-20 — The ProjectManager is shown the shelf, not told to ask for it

**Decision.** Triage is traced (under `triage-<issue>`, read with `claw log`), and the catalogue of
ready-made workflows is put in the ticket brief itself rather than left behind `list_workflows`.

**Why the trace.** Triage decides how every ticket is solved and left no record at all — its
environment was built without a tracer. Whether the shelf was even opened was unanswerable.

**Why the shelf is shown.** The first traced triage answered it: `list_workflows` was never called,
not once. The instruction was circular — call the tool before choosing `library`, but a model with
no reason to believe anything is on the shelf never considers `library`, so it never calls the tool,
so it never learns what is there. A capability reachable only by asking for it is one nobody asks
for. Measured on the same ticket with the same model: the verdict went from `generate` to `library`.

`list_workflows` stays, for reading one in full before committing to it. The brief carries a name,
the types it serves and its opening sentence — a few hundred tokens for the decision that picks how
everything else is spent.

---

## 2026-07-20 — A step gets the tools its job needs, and `done` is a job of its own

**Decision.** In `FixBugWorkflow`, the `reproduce` and `fix` steps are handed every tool the run
offers EXCEPT `done`. Only `verify` can finish the run.

**Why.** `done` declares the whole TASK solved and ends the run, skipping the steps that have not
happened yet. Reproducing a bug is not grounds for that claim, and the critic guarding `done`
cannot catch the mistake: it judges the step's own rubric, so a perfectly reproduced bug passes
review and finishes the run with the defect still in place.

Measured, on the second live run: the ticket closed as `Done` after `reproduce` alone, with the
failing test it had just found still failing. `fix` and `verify` never ran.

An instruction not to call it would have been one more rule to ignore — the same shape as every
other gate this project has had to move out of prose and into code. This is the tool not being there.

**Also in this change.** The `reproduce` step is told to run the existing tests FIRST — a defect the
suite already catches is reproduced by running it, with nothing to write — and is forbidden from
changing what an existing test expects. Its first live run had "reproduced" the bug by editing the
assertion to expect the buggy output, comment and all, which turns the suite green and ships the
defect. The critic caught that one; the instruction that invited it was ours.

**Still open, and wider than this workflow.** A `done` raised in a non-final step ends any run the
same way, generated solvers included. Narrowing one workflow's palette does not close that.
