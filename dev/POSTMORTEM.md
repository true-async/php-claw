# POSTMORTEM

Mistakes that cost something, and what they taught. Newest last. This file exists so the
same mistake is not paid for twice.

---

## 2026-07-20 — A concurrency bug that was never there

**What happened.** A check-then-write race was reported in `ProjectStore::addIssue()`: the
child count was read and the row inserted as two statements, so concurrent decompositions
would supposedly all pass the cap. It was "proved" by a script that ran 16 coroutines and
produced 16 children against a cap of 8.

**What was actually true.** The script had an `\Async\delay(1)` between the count and the
insert — a suspension point put there by hand. TrueAsync is cooperative and single-threaded,
and a PDO query is not a yield point here, so without that delay the same 16 coroutines
produce exactly 8. The test written to cover the "fix" passed identically with and without
it.

**Cost.** A transaction justified by a bug nobody had, and a test that certified nothing
while reading as proof — worse than no test, because it stops anyone looking again. Caught
by Edmond noticing the delay.

**Lesson.** Run the *unmodified* path before reporting a timing bug. Then check the test has
teeth: disable the fix and confirm the test fails. If it still passes, delete the test. The
TrueAsync PDO pool assigns and pins connections itself — do not add artificial yields to
"stress" it, and take a genuine extension bug to Edmond rather than working around it.

The transaction was kept, on its own merits: it is the right shape for check-then-write and
it makes the rollback on a refused decomposition real. The test and the delay were removed.

---

## 2026-07-20 — Making the critic verify turned a false pass into a false failure

**What happened.** The critic prompt was rewritten to demand that checkable facts be
verified with a tool. The very next run, the critic reviewing a *generated solver* ran the
project's test suite, found it red — which it is, the solver has not executed yet — and
rejected the source. Two rounds later the supervisor stopped the run, so generation failed
on a task that had merely not started.

**Lesson.** A general instruction added to a prompt talks over the specific rubric already
in it. The `solverReview` rubric said plainly that the artifact is code that runs later; the
new text did not defer to it. Anything added to a shared prompt has to state where it yields.

---

## 2026-07-20 — Editing `src/` to run an experiment

**What happened.** To find out whether a test had teeth, a transaction was commented out
directly in `src/Project/ProjectStore.php`, the suite was run, and the file was restored
from a backup.

**Lesson.** It is a live working tree, open in an editor, one interrupted step away from
being committed. That the revert worked is luck, not method. Experiments go on a copy or a
throwaway branch.

---

## 2026-07-22 — Reasoning about retrieval over a base that was never filled

**What happened.** Hybrid retrieval (FTS5 beside the vectors, fused by rank) was designed,
handed to a critic, and revised twice — all measured against a corpus assembled from this
folder's own markdown with a *hashed bag-of-words* standing in for the embedder, because no
project has ever had a `kb/` folder. That stand-in is good at exact word overlap, which is
precisely the thing dense embeddings are bad at, so the fake dense half looked strong and the
lexical half looked like noise displacing good answers. A whole gating mechanism was designed
to suppress it, a critic confirmed the need, and the conclusion drawn was "drop the feature —
there is nothing to measure".

**What was actually true.** Filling the base took twenty minutes: 224 notes built out of this
repository's own documents and class docblocks, indexed with the real embedder. Measured with
ground truth over 40 paraphrase questions and 40 exact-string lookups, dense-only scores
recall@5 of 48% and 25%; the ungated hybrid scores 62% and 100%. The gate that two rounds of
review had agreed on made the paraphrase family *identical to dense-only* — it suppressed the
half that was carrying the result. Every conclusion drawn from the stand-in was backwards.

**Cost.** Most of a session spent tuning thresholds against an artefact of the fixture, plus a
critic run and its verification, all discarded. Caught by Edmond refusing "there is no data"
as an answer and telling us to make the data.

**Lesson.** A stand-in for the expensive component does not measure the system; it measures the
stand-in. When the missing piece is the one under test, the only honest options are to obtain
it or to say nothing — and obtaining it is usually cheaper than the reasoning spent avoiding
it. Fill the corpus before ranking over it.

**A second bug fell out of the same twenty minutes,** which is the argument in miniature: the
chunker split on `/\R/` with no `/u`, and in byte mode `\R` matches 0x85 — the second byte of
`х` (U+0445). Every Russian note was cut through the middle of a letter, the malformed chunk
reached `json_encode(..., JSON_THROW_ON_ERROR)`, and the `JsonException` — not a
`ClawException`, so uncaught — ended the whole indexing pass. Seven tests over the knowledge
base had never once fed it a non-English note.
