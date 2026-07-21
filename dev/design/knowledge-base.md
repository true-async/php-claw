# The knowledge base

Declarative memory: what has been LEARNED about a project, as opposed to what code was written for
it (procedural memory, the solver classes) or what a run is holding right now (working memory, the
context tree). The gap `KnowledgeBaseInterface` has documented since it was written, with no
implementation behind it.

## Shape

```
<project>/kb/decisions/**.md    what was decided and WHY
<project>/kb/postmortems/**.md  what went wrong and what it cost
<project>/kb/design/**.md       how a part of the system works, one document per subject
<appHome>/<key>.kb.db           the index — SQLite, OUTSIDE the repository, disposable
```

Three kinds, because the three questions are asked at different moments and answered badly by one
pile. *Why is it like this* is a decision; *why did that hurt* is a postmortem; *how does it work* is
design. A base that mixed them would answer "how does deployment work" with the argument about
whether to deploy that way, which is the right document at the wrong time.

The names are the same shape as this repository's own `dev/` — `DECISIONS`, `POSTMORTEM`, `design/` —
because the convention already works here and inventing a second vocabulary for the same three
things helps nobody. `postmortems/` rather than something more evocative for one reason: the first
reader is a model choosing where to file, and a name it has to interpret is a name it will get
wrong.

The notes are the truth. The index is a cache of them: delete it and the next run rebuilds it, and
nothing is lost. That asymmetry decides most of what follows.

## Why the freshness marker is not in the notes

The open question was where to keep "has this file changed since it was indexed" — in each note's
frontmatter, or in a manifest beside them.

**Neither.** It goes in the index, as columns on the note row: `path`, `mtime`, `size`, `sha256`.

A hash written into a note's frontmatter makes the note's content a function of the indexer. A
person edits these files in Obsidian; every reindex would rewrite them, every rewrite is a commit,
and two machines indexing the same checkout would conflict over bytes neither author typed. A
manifest file in the repository has the same churn and is additionally in the wrong place: it
describes the index, not the knowledge, and it is the one thing that must be thrown away when the
index is rebuilt.

In the index there is one store and one truth, and `rm *.kb.db` is a complete reset.

**Both mtime and hash, in that order.** `mtime`+`size` is a stat call and settles the common case.
It also lies — a checkout, an rsync, a `touch` — so a difference in stat means "read and hash", not
"reindex". Only a changed hash costs an embedding call, which is the only expensive step here.

## Which vector store

Measured before choosing (`hrtime` over packed float32, this machine, PHP 8.4):

| chunks | dimensions | full scan | index size |
|---|---|---|---|
| 2 000 | 1536 | 240 ms | 11.7 MB |
| 2 000 | 256 | 43 ms | 2.0 MB |
| 10 000 | 256 | 218 ms | 9.8 MB |
| 10 000 | 1536 | 1332 ms | 58.6 MB |

**SQLite with float32 blobs and a brute-force cosine scan in PHP, at 256 dimensions.**

`sqlite-vec` is not loaded in this build and would become a build and deployment dependency —
a platform-specific `.so`, `enable_load_extension`, a fallback path for when it is absent. It buys
an index that matters at a scale a project's own notes do not reach: ten thousand chunks is a very
large knowledge base and scans in a fifth of a second, inside a tool call that already waits on a
model. `text-embedding-3-small` returns shortened vectors natively through its `dimensions`
parameter, so 256 is a supported mode rather than truncation.

`VectorStoreInterface` keeps the seam. When a base outgrows the scan, sqlite-vec slots in behind it
and nothing above changes.

## Schema

```sql
notes    (path PK, title, mtime, size, sha256, indexed_at)
chunks   (id PK, path, heading, ord, text, embedding BLOB)   -- embedding: packed float32
links    (path, target)        -- [[wikilinks]], the note graph
refs     (path, file, line)    -- src/Foo.php:12 mentioned in a note
tags     (path, tag)           -- how a note is FILED
```

`chunks`, `links` and `refs` are deleted by `path` and re-inserted when a note changes: a note is
the unit of reindexing, because a heading moving would otherwise leave orphans nothing points at.

## Chunking

By heading. Each chunk carries the note title and the heading path (`Deployment / Rollback / Manual
steps`) prepended to its text before embedding. Retrieval on technical notes improves markedly with
that context, and it costs nothing — the same string is what a reader sees as the result's breadcrumb.

A note with no headings is one chunk. A section longer than ~1500 characters is split on paragraph
boundaries, keeping the same breadcrumb.

## Tags

Read from both places Obsidian puts them — `tags:` in frontmatter (either spelling) and `#inline`
tags in the prose — because people use both, and a base that understood one would hold half a
classification with no sign that it did.

**A tag is never embedded with the text.** Tags file a note; they should NARROW a search rather than
compete with the note's own content for relevance. So they are an index column and a filter, not
part of the vector: `search` takes an optional tag and scans only what carries it, which is the cheap
half of retrieval doing work the expensive half would do worse.

The tool can list what the base is filed under, and the counts go in its own description, so a model
narrows with a tag that exists rather than one that sounds plausible.

## Links and code references, which are not vector search

`[[wikilinks]]` and `src/Foo.php:12` mentions are extracted while chunking and stored as edges. They
answer questions embeddings answer badly:

- *what do we know about this file?* — a lookup on `refs`, exact and instant, no embedding call;
- *what else is relevant?* — expand a hit to its linked neighbours.

A search result therefore returns its chunk, its breadcrumb, and what it links to.

## Indexing

Incremental, on its own coroutine (`Async\spawn`), triggered when a run starts and by the tool
before a search. Walk `kb/`, stat, hash the changed, chunk, embed, upsert. Embedding is an HTTP
round trip — the only slow part, and exactly what a coroutine is for.

It must be interruptible and idempotent: a note is committed to the index only after its embeddings
return, so a killed indexer leaves the previous version in place rather than a half-updated note.
Deleted notes are removed by comparing the walk against `notes`.

## The tool

One `knowledge` tool, actions like `project_manager`:

- `search` — the semantic lookup, optionally narrowed by tag; returns chunks with breadcrumbs, tags
  and links
- `about` — everything referencing a given file path; the `refs` lookup
- `read` — one note in full, by path
- `tags` — what the base is filed under, with counts

All four are read-only and land in this pass. `write` — an agent adding a decision or a postmortem
of its own, with {@see Provenance} recording which run did it — is the obvious next step and is
deliberately not here: it needs a rule about what an agent may file unprompted, and a base that
grows faster than anyone reads it is worse than a small one.

## What is deliberately not here

- **No sqlite-vec, no vector service.** Measured above; the seam is left.
- **No embedding of source code.** The repository holds text notes about the code, not the code
  itself — that is what `read_file` and `grep` are for, and they are exact where this is fuzzy.
- **No cross-project base yet.** `KnowledgeBaseInterface` mentions shared/session scopes; a project's
  own base is the one with an obvious owner and an obvious lifetime. Shared knowledge needs a rule
  about who may write it, which nobody has chosen.
