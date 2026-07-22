# Knowledge base

What has been LEARNED about this project — notes that outlive any one run. This page is the index
and the rules; everything else is a note beside it.

**Edit this file.** It ships as a default and it is meant to be replaced with what is actually true
of this project. A run reads it before writing anything down, so it is the one place where this
project's conventions can differ from every other project's.

## What is in here

<!-- Replace this with a short account of what this project is and what the base holds. Two or three
     lines. A reader who knows nothing should learn what the project is for; a reader who knows the
     project should learn what is already written down and what is not. -->

Nothing yet — the base is empty.

## How it is filed

Three folders, by the question a note answers.

- `decisions/` — **what was decided and why**, so a choice is not re-argued from nothing. Three to
  six lines: what was decided, what problem forced it, what was considered and rejected, what it
  costs. A decision that replaces an earlier one is a NEW note; the old one is not edited away.
  Routine changes, renames and bug fixes do not belong here — git already has them.
- `postmortems/` — **what went wrong and what it cost**, so it is not paid for twice. Only mistakes
  that were expensive: those that broke something working, sent work in a false direction, or
  happened twice. Record the symptom as it was first seen, then the root cause — not "a typo" but
  why the typo survived — then the lesson.
- `design/` — **how one part of the system works**, one document per subject, named after it.
  Around a diagram: what it shows, which invariants hold, and what is deliberately absent.

## How a note is written

- **One subject per note.** If it needs two titles, it is two notes.
- **Tags file it, prose says it.** A tag narrows a search to a subject; it never competes with the
  note's content. Put them in frontmatter: `tags: [pool, sqlite]`.
- **Link with `[[wikilinks]]`.** The links are the graph, and the graph is what makes this a base
  rather than a pile of files. A link to a note that does not exist yet is fine — it marks something
  worth writing.
- **Name the code you mean.** `src/Run/Triage.php:196` is an exact edge, looked up instantly by
  `action='about'`. A note that says "the triage class" is not findable that way.
- **Write the principle, not the transcript.** "The pool starved because the timeout outlived the
  connection" is worth keeping. A replay of what happened in order is not.
- **Say what you measured.** A number without a measurement behind it is worse than no number: it
  gets believed and built on. If it was not measured, say so.

## What does not belong

Anything git already answers — what changed, when, by whom. Anything the code says plainly. And
anything true only of one run: this base is what survives it.
