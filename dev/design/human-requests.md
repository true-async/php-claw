# A run's request to a person

A run that cannot go on without a human today lands the ticket in `WaitingHuman` and writes a line of
prose to explain — and it does this from five different places for five different reasons, every one of
which looks identical on the board. The person opens the ticket to discover what is even being asked.
This makes the pause a single typed thing: a run raises ONE request, the request says what it is and how
it can be answered, and everything downstream — the board, the panel, the API, resume — reads that one
shape.

## Why one shape

`WaitingHuman` is already overloaded. A run reaches it when the model asked a person a question and is
parked on the answer; when a generated solver waits to be approved before it runs; when a ticket judged
too big has no sub-issues and nobody has said what the pieces are; when triage ran out of strategies and
handed it back; and — once this lands — when the budget is spent. These are not the same request: the
person answers a question, approves code, writes sub-tickets, or raises a limit. But the ticket carries
none of that distinction. The reason lives in a `report()` string the dashboard never structured, so the
board shows a column of identical cards each meaning something different.

One typed request settles all of them at once, and it is the same shape the ask-gate already reaches for —
a durable `question`/`answer` pair — generalized so every other reason inherits the same durability and the
same resume for nothing.

## Shape

A request and its resolution, both durable in the run's journal:

```
request    { id, run, kind, prompt, payload, options }   -- what the run needs
resolution { ref -> request.id, data }                   -- what the person gave
```

The OPEN request of a run is its newest `request` with no `resolution` pointing back at it — exactly how
`openGate()` reads `question` against `answer` today. A resolution names its request by `id`, and that is
the whole point: a reply resolves the request it was given, never "the oldest unanswered one."

`question`/`answer` become `request(kind: question)` / `resolution`; the gate's `answeredAfter` + cursor
generalize to `resolvedAfter` + cursor over all kinds. Nothing about the durability machinery is new — it
is the gate's, widened.

## The pause has two forms, and the person sees neither

Every pause records the request and sets `WaitingHuman`. Underneath, how the run WAITS differs, and that
difference is an optimization the person never sees:

- **Live block.** The run coroutine is alive and parks on a channel for the resolution — the current gate:
  the worker asked a question, the answer may arrive in seconds, and blocking lets the run continue
  in-process without a relaunch. The journal is the durable fallback if the process dies while parked,
  which is the whole of issue #87's fix.
- **Durable stop.** The run records the request and EXITS. There is nothing to wait on in-process — the
  resolution is an out-of-band act (raise the budget, approve the solver) that may take a day, so holding a
  coroutine open buys nothing. Resume relaunches the run, which reads its snapshot and the resolution and
  carries on.

The person, the board, and the API see one thing either way: an open request of some kind, with options.

## Resolution and resume

A resolution is the durable input that lets resume proceed; it is not itself the resume. The run resumes
through the same snapshot-and-replay machinery every restart uses (`workflow_state` plus the recorded
exchange); the resolution is simply the fact the resumed run was blocked on. An answer becomes the next
turn of the parked exchange; a raised budget lets `enforceBudget` pass; an approval lets the solver run.
Same engine, different durable fact.

## The kinds

| kind | raised when | payload | options |
|---|---|---|---|
| `question` | the model needs a person to decide (the `[question]` gate) | the question text | answer (free text) |
| `budget` | the run's token/time total is spent | spent, limit, tree context | give +N and resume · stop |
| `approve-solver` | a generated solver is written but not yet run | the solver source | run · reject (with reason) |
| `split` | a ticket judged too big has no sub-issues | the ticket, and why it is too big | write sub-issues and continue |
| `strategy` | triage has no strategy left to escalate to | the failure reason | take it manually · reformulate · close |

The table is the map of today's scattered `setIssueStatus(WaitingHuman)` sites onto one mechanism. Each
row is a real place that reaches `WaitingHuman` now (`HttpGateSpeaker`, and `IssueRunner`'s
`ensureSolver` / `reportDecomposition` / `giveBackToProjectManager`); the change is that each RAISES a
typed request instead of setting a status and writing prose beside it.

## Budget is a request, not a question

The budget pause is why this document has the shape it does, and it carries a decision worth stating on
its own.

`enforceBudget()` runs when the run's total is spent. Under the old `BudgetPolicy::Ask` it reacted by
calling the ask channel — "enter extra tokens to continue" — which is wrong twice over. First, the ask
channel's front tier is the supervisor, an agent: to handle "there are no tokens" it spends tokens making
a model call, and the supervisor cannot authorize a budget anyway. Second, on a resumed run that call
reaches the human gate before the parked worker does, and the gate — matching answers to the run by a
FIFO cursor rather than to the question by `id` — hands the budget check the human's answer to the
WORKER's question. The answer is consumed, parsed as a token count, comes back zero, the run stops, and
the person's reply is gone.

So budget stops being an in-run question. `enforceBudget`, when the total is spent, raises a `budget`
request and STOPS — no channel, no model call. The ticket goes to `WaitingHuman` like any other request.
The person raises the limit out of band and resumes; on resume the budget is above zero, `enforceBudget`
passes, the run continues. `BudgetPolicy::Ask`, `parseExtraTokens`, and the in-run top-up are deleted.
Matching a resolution to its request by `id` closes the answer-stealing bug at the root, for every kind,
not only this one.

## The dashboard

- **Board.** A `WaitingHuman` card carries a badge of its request kind, so the person sees what is wanted
  without opening it — a question, a budget, and a solver to approve are three different jobs and should
  not look alike.
- **The request panel.** Opening the ticket shows the open request: its `prompt` (what is needed and
  why), its `payload` as context (the question, the budget figures, the solver source, the split brief),
  and its `options` as real controls — a text box for a question, a +N field with give/stop for a budget,
  run/reject for a solver, a sub-issue editor for a split.
- **The bell.** A new open request rings the notification bell, on the channel that already carries run
  events.
- **Live.** The request appears and clears over the board's live transport; resolving it takes the ticket
  out of `WaitingHuman` without a reload.
- **History.** A resolved request stays in the ticket's timeline — the generalization of the
  question/answer pair the chat renders today.

## The API

- `GET issue/{id}` includes the open request, if any: `{ kind, prompt, payload, options }`. The UI needs
  nothing else to render the panel.
- `POST issue/{id}/resolve { requestId, ... }` supersedes the answer-only endpoint. It resolves a request
  BY ID — which is what makes a resolution land on its own request — and rejects a body whose kind does
  not match the open request.

## What this rests on

- **The resume machinery** — a run resuming into a recorded exchange, and, for a parked question,
  continuing it from the resolution — is a sibling subject, captured with the two-step-kind cycle it
  belongs to, not here.
- **The gate's answer-by-`id`** matching is the small change that both fixes the answer-stealing bug and
  makes a typed resolution possible; the `ref` is already stored, only the read side ignores it.

## Open

- Whether `resolve` is one endpoint keyed by request id or a small set per kind. One endpoint is cleaner
  and inherently id-addressed; per-kind endpoints read more explicitly. Leaning to one.
- Whether every current `WaitingHuman` site converts in one pass, or `question` + `budget` land first
  (the two with a live path, and the two this discussion produced) and the rest follow as their UIs are
  built.
- Whether `IssueStatus` gains a per-kind hint or the board reads the kind off the open request. Reading
  the request keeps one source of truth; a status hint is cheaper to query. Not decided.
