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
