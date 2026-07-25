# Resuming a run without re-running the model

A run must survive a crash and carry on — including a run paused waiting for a person. Today resume is
at STEP granularity only: a step is arbitrary imperative PHP that re-runs top to bottom on resume, and
the one thing stitched back is the first `ai()` call's history. A step parked mid-exchange on a human
question is not durably recoverable — the exchange at the park point is never persisted, so on resume the
model is asked again and the supervisor can answer in the person's place.

The fix is not "resume in the middle of a step" — PHP cannot serialise a paused call stack. It is to make
a step an atomic unit that either re-runs whole (cheap, deterministic) or replays from a record (the
model was expensive and must not be re-run). Then there is no middle to resume to.

## Two kinds of step

Distinguished by attribute — `#[Step]` already exists, `#[StepAI]` is added — so the base knows the kind
by reflection BEFORE the method runs, and the resume path decides skip / re-run / replay without invoking
the body.

- **`#[Step]` — a CODE step.** Pure, deterministic glue. May call `$this->tool(...)` any number of times.
  On resume it is RE-RUN WHOLE: cheap, nothing recorded, no middle to come back to. Its one contract is
  that re-running is safe (no non-idempotent side effect) — the sharpest asymmetry in the model, enforced
  by discipline, not by the type.
- **`#[StepAI]` — an AI step.** A PURE method that builds a prompt from durable inputs and returns an
  {@see AiStep} declaration (prompt, tools, agent, params); critic and maxRounds ride on the attribute.
  It does no work and has no side effects — the base runs the ONE `ai()` exchange it declares. EXACTLY ONE
  exchange per AI step: that single exchange is the atomic replay unit; two would force per-call recording,
  the very complexity this removes. Interleaved computation goes into neighbouring CODE steps.

## The cycle

`run()` drives the steps in declaration order (both kinds), honouring `back()` exactly as today. Each is
handed to `step()`, which now branches at the top: a `#[StepAI]` method goes to `runAiStep()`, everything
else keeps the existing imperative path. The branch is the whole seam; the cycle is otherwise unchanged.

## Resume of an AI step

`runAiStep()` asks the exchange store what it already holds for this `(run, step)` and acts on it:

- **EMPTY** → first run: open the declared prompt.
- **SETTLED** (recorded, ending on a real assistant answer) → REPLAY: take the recorded final text, **no
  model call**. A resume never re-buys a turn already produced.
- **PARKED** (recorded, ending on an unanswered `[question]`) → CONTINUE: the ask channel returns the
  human's answer (from the journal, once matched by request id — see human-requests.md) and it becomes the
  next turn. The model is not asked again; the supervisor is not consulted a second time.

Then the critic loop, unchanged in spirit — judge the AI output, while unhappy let the supervisor guide a
re-run, bounded by maxRounds — reusing the existing `critic()` / `superviseStep()`.

The prerequisite that makes PARKED possible: the turn loop persists the exchange AT THE PARK POINT, before
it blocks the ask channel on a `[question]`. Today the checkpoint fires only after a tool-turn, so a park
leaves nothing recorded. `DefaultTurnLoop::pendingQuestion()` reads the recorded tail back to tell SETTLED
from PARKED.

## The AI step's output

Reuses the existing handoff machinery: after the accepted work, the engine CONTINUES the step's own
conversation with a dedicated extraction request. Two sinks, one mechanism:

- **handoff** — the prose baton to the next step (what `formPendingHandoff()` already does).
- **param** — when a later CODE step needs a machine-readable value (a word, a path, an id), the same kind
  of request asks the model for exactly that value and `setParam()` addresses it to that step, which reads
  it with `param()`. Declared on the `AiStep` so deciding it is part of designing the step.

## The gaps, and how they close

Named so the machinery handles them rather than shipping them:

1. **The park checkpoint.** Add one checkpoint call in the turn loop's `[question]` branch, before it
   blocks — otherwise PARKED has nothing to resume from.
2. **Extraction must not overwrite the work.** `extractParams()` and `formPendingHandoff()` continue the
   step's conversation and would checkpoint into the same `(run, step)` exchange row, so a crash mid-
   extraction leaves the row holding the extraction Q&A — which resume would replay as the work. They run
   under a guard (the same shape as `$reviewing`) that suppresses that checkpoint.
3. **Artifacts on REPLAY.** `$this->artifacts[$step]` is transient, filled only by tool calls made THIS
   process; a SETTLED replay makes none, so the critic and extraction see none. They are read back from
   the journal on replay (`TraceReader`), never by re-executing the recorded `artifact` tool calls — that
   would re-run the shell behind the evidence channel, which a free replay must not.
4. **The reviewer has no ask channel.** A critic's exchange should never park on a `[question]`; the
   review palette is built without `EnvKey::Ask`, closing that off structurally rather than trying to make
   a reviewer-park resumable.
5. **SETTLED vs PARKED is a durable flag, not a text sniff.** The checkpoint records WHY it fired (a
   mid-turn checkpoint vs a park), so detection does not lean on finding the literal `[question]` in the
   tail. (First cut may sniff; the flag is the honest version.)
6. **The critic round counter is durable.** Persisted with the snapshot so a crash mid-critic-loop does
   not restart the count and let a step exceed maxRounds across incarnations.

## Coexistence and migration

`#[Step]` and `#[StepAI]` run side by side. The old imperative path is untouched, so every existing solver
and test keeps working; new work is written declaratively. The generator's prompt moves to the new rules
(a pre/post CODE step around a declarative AI step) once the machinery is proven. The old path — and the
duplicated critic loop coexistence leaves behind — is deleted when nothing writes imperative `ai()` steps
any more. The duplication is a named, temporary cost of not rewriting a load-bearing class in one motion.

## Sequence

Each increment lands green on its own.

1. **Park durability.** `DefaultTurnLoop` checkpoints at the park; `pendingQuestion()` reads it back.
   Foundational, tiny, safe.
2. **The AI step.** `AiStep`, `#[StepAI]`, the `step()` branch, `runAiStep()` with EMPTY / SETTLED /
   PARKED and the critic loop, coexisting. Gaps 2–4 closed here.
3. **Output extraction.** handoff (reused) + declared `param` extraction. 
4. **Typed requests + budget.** The human-requests.md work: `request`/`resolution`, answer-by-id, budget
   as a request. Gap 1 of human-requests (budget stops calling `ask()`).
5. **The generator.** Move `GenerateIssueWorkflow`'s prompt to pre/post-CODE + declarative-AI; migrate the
   shipped workflows; then delete the old imperative path.

This document is the engine half; the person-facing half is [`human-requests.md`](human-requests.md).
