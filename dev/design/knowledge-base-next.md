# The knowledge base — the second pass

[`knowledge-base.md`](knowledge-base.md) designs what is built: the shape on disk, the index schema,
chunking, tags, and four read-only actions. It ends by naming `write` as the obvious next step and
deliberately leaving it out. This file is that next step, plus what a survey of comparable systems
turned up — and one defect in what already exists.

Nothing here is started. Features are ordered by return on the work they cost, and each carries where
the mechanism comes from, so a reader can go argue with the source rather than with this file.

## Where this stands today

**The base has never been filled.** Notes are looked for in `kb/` inside a project
(`src/Tool/ToolFactory.php:65`); no project has that folder, and no `*.kb.db` exists anywhere. The
tool is therefore never built into a run — `knowledge()` returns null when the folder is absent — and
the tag list injected into its description (`src/Tool/KnowledgeTool.php:44-45`) has always been empty.
Everything below is reasoning about a subsystem that has run only in tests.

**Tests pass and cover more than the name suggests.** The whole suite is green — 324 tests, 1069
assertions, 7.42s. Seven of them are the knowledge base: chunking with breadcrumbs, wikilinks, code
refs, frontmatter kept out of the embedding, tags from both places Obsidian puts them plus narrowing
a search by one, incremental indexing through the stat→hash→embed ladder, the tool's semantic and
exact lookups, the notes-folder escape guard, and vector ordering in the embedder. What no test
covers is a real embedding endpoint: the embedders are stubs and `OpenAiEmbedder` runs against a
fake HTTP client. Nothing has ever been indexed from a real vault.

**The scan measurements hold.** The table in `knowledge-base.md` was re-measured today against a real
SQLite file rather than arithmetic alone: 1 000 chunks 36 ms, 5 000 chunks 188 ms, 10 000 chunks
371 ms, 20 000 chunks 827 ms (256-dim float32, ~1500-char chunks, this machine — a ZTS **debug**
build, so these are upper bounds). The original "218 ms at 10 000 chunks" reproduced as 255 ms
arithmetic-only. A realistic corpus of ~300 notes lands at 40–70 ms, under the HTTP round trip that
fetches the query's own embedding. The decision to skip `sqlite-vec` stands, with room to spare.

**One defect.** `CREATE INDEX links_target ON links (target)` is built
(`src/Knowledge/KnowledgeIndex.php:60`) and nothing queries it. It exists for exactly one question —
*what links HERE* — and that question has no implementation. Backlinks are feature 5 below.

## Decisions still open

These block work below and are not resolvable from the code.

1. **Whether a writing action exists, and in whose palette.** Not a question about what the agent is
   allowed to do — it has no concept of a knowledge base and only ever calls a tool — but about the
   API surface and about `Registry::only()`. Feature 8 states a position and the argument on both
   sides.
2. **What "required page" means when the page is absent** — the tool refuses to run, it creates an
   empty one, or the requirement is only advice in a description. Feature 2 assumes the third for
   human-authored pages and the first for nothing.
3. **Whether `kb/` is one folder per project or also a shared base.** `knowledge-base.md` left this
   open on the grounds that shared knowledge needs a rule about who may write it. Feature 8's answer
   would supply that rule, so the two are worth deciding together.

## Features, by value

### 1. Full-text search beside the vectors, fused by rank

The only entry that repairs something already broken rather than adding capability.

Retrieval is dense-only, and the vectors are 256-dimensional by deliberate choice. Developer notes
are full of exact strings — `SQLSTATE[HY000] [2002]`, `ATTR_POOL_MAX`, `src/Run/Triage.php`, a flag
name, a stack frame — and that is precisely where dense embeddings smear and lexical search is exact.
Anthropic measured retrieval failure dropping from 3.7% to 2.9% by adding BM25 to contextual
embeddings; on a corpus with precise vocabulary, BM25 alone can beat dense outright (T²-RAGBench:
0.515 vs 0.466 nDCG@10).

Fuse with **Reciprocal Rank Fusion**, which uses ranks only — no score normalisation, no training,
and it sidesteps the sign convention of SQLite's `bm25()` entirely. Use k=60 and do not tune it;
Cormack's original paper reports MAP .2123 / .2145 / .2142 at k=10 / 60 / 100 against .2016 for the
best single ranker, and states the choice "was not critical".

*Cost:* no new dependency. This build has SQLite 3.45.1 with FTS5, `bm25()`, column weighting and
porter stemming compiled in. A working prototype exists in this session's scratchpad — an
external-content FTS table over `chunks.id`, triggers to keep it in step, and the tag filter joined
through `rowid → chunks.path → tags.path`. It retrieved `SQLSTATE[HY000] [2002] Connection refused`
exactly, which is the case a 256-dim vector loses. Query latency is unchanged; both rankers are cheap
and run against the same table.

*Source:* [Contextual Retrieval](https://www.anthropic.com/engineering/contextual-retrieval) ·
[RRF, Cormack SIGIR'09](http://cormack.uwaterloo.ca/cormacksigir09-rrf.pdf)

### 2. A manifest in the tool description, and required pages on the same mechanism

The agent currently learns one thing about the base: the list of tags. On an empty base that is
nothing, and even on a full one a tag list does not tell it that a note answering its question
exists. So it does not ask.

Claude Code's skills solve exactly this: what is always loaded is **name plus one-line description**,
the body loads only on use, and the listing is a *budgeted* resource — sized as a fraction of the
context window, truncated per entry, and on overflow it drops descriptions starting with the
least-used entries **while always keeping every name**. Degradation by priority, not by cutoff. The
same shape appears independently in Cursor's "apply intelligently" rules, Windsurf's
`trigger: model_decision`, and Serena, which hands the agent the full memory *name list* up front.

Here that is a line per note — `path — description`, the description taken from frontmatter — injected
under a character budget, alongside the tag counts already injected.

**Required pages ride the same mechanism.** Letta's memory block is the form worth copying, and it is
five fields: `label`, `description`, `value`, `limit` (characters), `read_only`. Three parts of that
matter separately:

- `description` is **an instruction to the agent about what belongs in this block**, not documentation
  for a human. That is how a required section is enforced without writing any enforcement code.
- `chars_current` / `chars_limit` are rendered into the prompt, so the model can see it is running out
  of room and consolidate instead of appending blindly.
- `read_only: true` means only the developer may modify it — the primitive for "the agent may not
  touch the conventions page".

Candidate required pages: the project description (asked for), a glossary of domain terms (the thing
an agent most often gets wrong and cannot infer), the working conventions, and the current state of
play. Note that `knowledge-base.md` already fixes a folder convention — `decisions/`, `postmortems/`,
`design/` — so this adds named pages, not a competing taxonomy.

Dendron approaches the same problem from the other end: a schema binds a pattern over the note
hierarchy to required children and a template, and a note matching no schema is displayed with `?` —
a free conformance signal, and worth having as a report rather than as a refusal.

*Source:* [Claude Code skills](https://code.claude.com/docs/en/skills) ·
[Letta memory blocks](https://docs.letta.com/guides/core-concepts/memory/memory-blocks) ·
[Dendron schemas](https://wiki.dendron.so/notes/c5e5adde-5459-409b-b34d-a0d75cbb1052/)

### 3. An outline action, and reading one span between anchors

Straight at the goal of not dragging whole notes into context: ask for a note's sections, get the
headings, read one.

The outline needs no new indexing at all. `chunks(path, heading, ord, …)` already holds it with an
index on `path` — the headings were parsed and stored when the note was indexed, so an outline is one
ordered select. **Lazy section indexing is not needed and should not be built**: there is nothing left
to compute at query time.

Reading a bounded span is the other half, and Dendron has the most complete address form:
`![[note#start:#end]]` for a span between two anchors, and `![[note#start:#*]]` for "to the next
heading of equal or lesser depth" — so a caller who knows where a section begins does not need to
know where it ends.

*Source:* [Dendron ranges](https://wiki.dendron.so/notes/f1af56bb-db27-47ae-8406-61a98de6c78c/)

### 4. Log pages

A note tagged `log`, with a date as the heading of each entry — the shape of a CHANGELOG, and read
back the same way:

```markdown
---
tags: [log]
---
# Deploy log

## 2026-07-22
Rolled back at 14:10; the pool leaked connections under the new timeout.

## 2026-07-19
…
```

The heading chunker already turns each dated section into its own chunk, so an entry is a chunk and
needs no new parsing. What is missing is a date to query by, and the API to query it.

**The date must be parsed from the text and must not be `notes.mtime`.** Every column on `notes`
today — `mtime`, `size`, `sha256`, `indexed_at` — is *transaction time*, when the system last touched
the file. A typo fix or a reindex moves all of them. The date an entry describes is *valid time*, and
conflating the two is the exact mistake bi-temporal modelling exists to prevent. It needs its own
column, on `chunks` as well as on `notes`, or a range query returns whole months instead of the three
entries asked for.

The retrieval shape to copy is Graphiti's `retrieve_episodes`: filter on `occurred_at <= :reference`,
**order descending, limit N, then reverse in application code**. Descending lets the index serve the
query without a scan; reversing afterwards gives the model chronological reading order. Easy to get
wrong by sorting ascending and offsetting.

Worth taking with it: resolve relative dates at write time. mem0 injects today's date into its
extraction prompt so "last week" becomes absolute; an entry that says "the refactor from last week"
and carries no absolute date is permanently unqueryable.

*Source:* [Graphiti `retrieve_episodes`](https://github.com/getzep/graphiti/blob/main/graphiti_core/utils/maintenance/graph_data_operations.py) ·
[OpenAI temporal-KG cookbook](https://developers.openai.com/cookbook/examples/partners/temporal_agents_with_knowledge_graphs/temporal_agents)

### 5. Backlinks

The cheapest entry here: the index is already built and unused (`KnowledgeIndex.php:60`). One query
against it answers *what links here*, which is usually more useful to an agent than what a note links
to — it finds the note about an error and wants to know where else that error is discussed, without
inventing a second search.

Obsidian pairs backlinks with **unlinked mentions**: occurrences of a note's title or any alias in
plain text that are not yet links. With embeddings that can be done better than by string scan, and
it turns implicit context into an explicit, cheap edge.

*Source:* [Obsidian backlinks](https://obsidian.md/help/plugins/backlinks)

### 6. Notes about a file, surfaced without being asked

`refs(path, file, line)` exists with an index on `file`, and `about` queries it — but only when the
agent remembers to call it. Every rules system converged on making this deterministic instead:
Cursor's `globs`, Windsurf's `trigger: glob`, Claude Code's `paths:` frontmatter — a rule loads when a
matching file is *touched*, with no model judgment involved.

Here: when a run reads or edits `src/Run/Triage.php`, the notes whose refs point at it are surfaced
without a tool call. This converts a discretionary lookup into a free one, and it is the difference
between a base that helps and a base the agent forgets exists.

*Source:* [Claude Code memory](https://code.claude.com/docs/en/memory) ·
[Cursor rules](https://cursor.com/docs/context/rules)

### 7. Provable staleness, by signature rather than by age

No PKM tool in the survey detects a note whose subject has changed; all of them offer age as a proxy,
and age is the wrong question — a two-year-old decision note can be perfectly correct while a note
edited last week is stale because it was revised without checking the code.

`fiberplane/drift` does it properly: a doc is bound to a code symbol, and the binding stores a hash of
a **normalised syntax-tree fingerprint** of that symbol — node kinds and token text, with whitespace
and positions stripped — so unrelated edits elsewhere in the file do not false-positive. `drift check`
recomputes and compares, using no VCS history at all.

Our `refs` table is nearly the right shape already; it wants a symbol name and a signature column
beside `file` and `line`. Line numbers alone drift on every edit above the reference and are useless
for this.

*Cost:* the real one — it needs to parse PHP to fingerprint a symbol. That is a dependency and a pass
over the source, which is why this sits at 7 and not at 2.

*Source:* [fiberplane/drift](https://github.com/fiberplane/drift)

### 8. A writing action — which is a question about the API, not about the agent

**The case for is measured and structural.** ReasoningBank reports +4.6% resolve rate on
SWE-Bench-Verified and about three fewer execution steps per task against an identical memory-free
agent, and its gain comes specifically from distilling **failed** trajectories. That is the structural
argument: a human writes down decisions and designs, not the forty ways a run got stuck, and the
second kind is generated at agent speed and recorded at human speed. Every shipping system with
persistent memory lets the model write, and not one of them gates the write — the human check is
always a review surface afterwards.

**The case against is our threat model specifically.** MINJA shows an attacker who can only send
normal queries inducing an agent to write poisoned records into its own memory, with a reported >95%
success rate and no access to the store. Our inputs are ticket text, PR comments, test output and
third-party source in `vendor/` — all attacker-influenceable — and the base is the one artifact that
survives the run. A poisoned conventions note is not a one-shot injection; it re-enters context on
every future run carrying the authority of the project's own documentation. Our `refs` table makes it
worse: a poisoned note can *target* a file and guarantee delivery whenever that file is touched.

The non-adversarial failure is likelier than the adversarial one. mem0 is the cautionary case — its
conflict resolution is a single sentence in a prompt telling the model to delete contradicting
memories, and its delete is a hard delete. A confidently wrong agent that misreads code and
"corrects" a human's note has destroyed the more reliable source. That is the false-Done family
again: a gate that exists on paper only.

**But "may the agent write" is the wrong question.** The agent has no concept of a knowledge base. It
sees a list of actions and their descriptions, and it calls one. So there is nothing to permit or
forbid at the agent's level, and a constraint phrased as a rule the agent must follow is a gate on
paper — the false-Done family, again. Every constraint below is therefore a property of the API
surface and of who holds it, enforced where the agent cannot reach.

1. **Writes cannot leave their namespace, because the action has no parameter that would let them.**
   Not "the agent must only write to `kb/observations/`" — the write action takes a title and a body,
   never a path. `read` already works this way: escaping the notes folder is impossible, and there is a
   test for it. `src/Knowledge/Provenance.php` exists to record which run produced a note.
2. **Nothing can be deleted, because no deleting action exists.** The only permitted change to an
   existing note is a separate `supersede` action taking a target and a reason; it stamps validity and
   links the replacement. Graphiti's rule, expressed as the absence of a capability rather than as a
   prohibition.
3. **Each write is one git commit tagged with the run id — done by the tool.** The agent neither knows
   nor can skip it. The base is markdown in a repository, so provenance, diff, blame, revert and
   review all come from tooling already in use; Letta reached the same answer with MemFS after
   starting from a database.
4. **The solver's palette has no writing action at all.** It exists only in the palette of a
   consolidation pass that runs after the trajectory is finished. This is a `Registry::only()` /
   `except()` decision, and the project's rule that a palette is never defaulted already makes it the
   natural way to express it. The separation is also the safety boundary: the consolidator reads a
   completed trajectory rather than live text. That pass writes entries shaped like ReasoningBank's —
   title, one-line description, and a *principle rather than a transcript*, which is the rule that
   decides whether the base stays readable.
5. **Authority follows evidence, not a signature.** The other four constrain what kind of write is
   possible; none constrains what goes *into* it, and MINJA works precisely because the agent is only
   calling a tool — poisoned ticket text produces a poisoned argument to a legitimate action.

   The tempting fix is to require a human to promote a note before it carries weight. Rejected: a
   queue nobody drains is the gate-on-paper failure this project keeps finding, and it puts a human
   in the loop of a system whose point is not having one.

   The real distinction is what a note is **grounded in**. Poisoning arrives through prose — ticket
   text, a comment, a third-party README. It does not arrive through observation. A note saying "the
   pool leaks under this timeout", derived from a command that ran, its recorded output and a failing
   test, is checkable: the evidence is in the run's journal and can be re-read. A note saying "this
   project intentionally disables signature verification" stands on nothing but someone's prose, and
   is dangerous whether or not a human waved it through.

   So: a note distilled from **observed behaviour** — a command's output, a measurement, a test
   result — is written into the full-authority folders directly. A note distilled from **text the run
   was given** stays retrievable by search but never enters the manifest and never carries the
   authority of the conventions pages. No queue, no human step, and the boundary sits where the risk
   actually is. Review remains available and blocks nothing: constraint 3 puts every write in
   `git log` under its run id.

*Source:* [ReasoningBank](https://research.google/blog/reasoningbank-enabling-agents-to-learn-from-experience/) ·
[MINJA](https://arxiv.org/abs/2503.03704) · [Zep/Graphiti](https://arxiv.org/abs/2501.13956) ·
[Letta MemFS](https://docs.letta.com/letta-agent/memory)

### 9. Aliases, hierarchical tags, and ranking by the note graph

Three smaller mechanisms, grouped because each is cheap and none is urgent.

**Aliases.** Obsidian resolves a note under many names from an `aliases:` frontmatter key, and writes
the canonical target to disk while showing the alias. Free query expansion: an agent asking about "the
PDO pool" should reach a note titled "TrueAsync connection pooling".

**Hierarchical tags.** `tag:inbox` matching `#inbox/to-read` by prefix. Dataview's split is the useful
detail — store both the expanded chain (`#a`, `#a/b`, `#a/b/c`) and the literal tag, and one index
serves both broad and exact matching.

**Ranking by the graph.** Aider ranks code context with personalised PageRank over the symbol graph;
we have the equivalent graph already in `links` and `refs`, hand-authored and exact. Two of its
heuristics port directly: damp a note that is linked from everywhere, and take the square root of a
link count so twenty links from one page do not dominate. Worth noting Aider also spends a *larger*
context budget when it has no anchor file — more orientation when you know least, which maps onto
triage.

*Source:* [Obsidian aliases](https://obsidian.md/help/aliases) ·
[Dataview metadata](https://blacksmithgu.github.io/obsidian-dataview/annotation/metadata-pages/) ·
[Aider repo map](https://aider.chat/2023/10/22/repomap.html)

## Rejected, with the numbers

Kept so nobody re-proposes them in six months.

- **GraphRAG.** Indexing 41–57× slower than plain RAG (5 560s vs 135s), and it *loses* on our query
  shape — single-hop detail lookup — scoring 65.44 vs 68.18 F1 on NQ. Decisive: its whole purpose is
  *extracting* a graph from unstructured text, and we already have one that is hand-authored and
  exact. Paying a model to infer a worse copy is strictly negative.
- **RAPTOR.** Built for far longer documents; its soft clustering only engages above roughly 14 300
  tokens per document, and our notes are ~1 000 tokens with a human-written heading hierarchy —
  which is the summary tree RAPTOR spends model calls reconstructing.
- **sqlite-vec / any ANN index.** Nothing to gain at this scale (see the measurements above), and it
  is a platform-specific build dependency. `sqlite-vec` is brute-force too, just in C.
- **HyDE.** Measured negative on technical corpora, at 2.8× query latency.
- **Query decomposition.** +1.0 F1 standalone for roughly 500× the latency (16.7s vs 0.03s).
- **Recency weighting.** Measured 18× worse than no weighting. The intuition that newer notes
  supersede older ones has no published support, and the nearest evidence cuts the other way — older
  surviving facts are *less* likely to be superseded. If supersession matters, mark it explicitly at
  write time (feature 8, constraint 2) and let the model see the metadata.
- **Late chunking.** Needs token-level embeddings from the model, which an HTTP embedding endpoint
  does not return. Not implementable here regardless of merit.
- **ColBERT / late interaction.** Real gains, roughly 17× storage and 33× compute in PHP.
- **Contextual Retrieval, the LLM-written kind.** Deferred rather than rejected. The indexer already
  prepends `title — breadcrumb` to each chunk before embedding, which recovers much of what
  Anthropic's baseline lacked; independent measurement puts the remaining gain at +0.024 nDCG@10. The
  cost is not the ~$1–8 of tokens, it is that `Indexer` currently needs only `EmbedderInterface` and
  this would add a chat model to indexing.
- **A reranker.** Deferred, not rejected: 2.9% → 1.9% failure in Anthropic's numbers, at 150–600 ms
  per query and a new API dependency. Rerank 30–50 candidates, not 150 — rerankers degrade past about
  100 and can score below the un-reranked retriever. Measure first-stage recall before building it;
  if recall@5 is already ~95%, there is no headroom.
- **Graph views, canvases, Maps of Content.** Spatial and human-facing. An agent should generate an
  index from the links table on demand rather than maintain a hand-written hub.
- **Soft schema validation.** Tana and Anytype both *warn* on a missing required field rather than
  refusing. If a schema is enforced at write time, it should fail the write; a warning nobody reads is
  the gate-on-paper failure again.

## Traps found on the way

- **Do not use `mtime` as an event date.** Feature 4 covers why.
- **Two cosmetic defects in the breadcrumb**, measured: when a note has an H1 the title is duplicated
  (`"Deployment — Deployment / Rollback"`), and a note with no headings produces a dangling `" — "`.
  Sub-1% effects on retrieval, one line each to fix.
- **The default result limit is probably too low.** Anthropic's results improve monotonically from
  top-5 to top-10 to top-20. Worth raising and then measuring what it costs in context.
- **mem0's headline benchmark numbers are vendor-contested** and it scores *below* full-context on
  accuracy (66.88% vs 72.90%). Its mechanisms are worth copying; its benchmarks are not worth citing.
