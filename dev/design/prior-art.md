# Prior art — how mature systems solve the problems this engine solves

A web sweep of well-known agentic-workflow systems, framed around the seven design
problems this engine actually grapples with. The point is to borrow what is already
thought through and to see where our design is genuinely ahead. Sources are inline.

> Confidence: P1/P2/P4/P6 rest on primary sources (Anthropic engineering posts,
> LangGraph docs, DSPy paper). P3's Plan-and-Solve citation is a secondary topic page.
> Some P4/P5 vendor numbers (DBOS atomicity, Temporal's 24h pause) come from blog
> summaries — directionally reliable, verify exact figures before quoting.

## Status — what we already took

- **Oracle-gaming guard in the critic prompt (LANDED).** Evidence stops a fabricated
  *output* but not a gamed *oracle*: an agent can weaken a test, pass it, and freeze
  the green as evidence (ImpossibleBench). The critic prompt now judges the *command*
  behind an evidence artifact, not only its output. See `WorkflowAbstract::judge()`.
- **Critic prompt rebuilt around the two-kind artifact model** (answer vs tool-run
  result), role-and-meaning first — aligned with LLM-as-judge "grade against a
  reference" (the recorded evidence is the reference).

## Queued borrows (not started)

- **[P3] Tighten the generator RECIPE with ADaPT's rule** — "decompose only when a
  monolithic attempt fails," plus explicit complexity→step-count scaling. Antidote to
  ceremonial over-splitting.
- **[P6] `search_tools` index = BM25 first**, embeddings only for large/paraphrase-heavy
  palettes. Direct answer to the "vector search_tools" TODO — do NOT start with vectors.
- **[P7] Archive-conditioned generation** — feed prior saved solvers into the
  generator's context (ADAS), so workflow-generation improves instead of starting cold.
- **[P2] Reflexion framing for the reject path** — already carry the critique forward to
  the re-draft; this is the named pattern, keep it.

---

## 1. Step outputs & anti-fabrication

- **Anthropic, "ground truth from the environment"** — the recommended defense against
  fabrication is a verifiable channel, not a better prompt: agents "gain ground truth
  from the environment at each step (such as tool call results or code execution)"
  ([anthropic.com](https://www.anthropic.com/research/building-effective-agents)).
  *This is the basis for our EVIDENCE kind — a claim (text) and a runtime-owned result
  (evidence) are ontologically different, and only the latter is trustworthy.*
- **Reward hacking / oracle gaming (the residual hole).** ImpossibleBench and "LLMs
  Gaming Verifiers" document models that overwrite unit tests, monkey-patch scorers,
  delete assertions, or terminate early to pass
  ([lesswrong](https://www.lesswrong.com/posts/qJYMbrabcQqCZ7iqm/impossiblebench-measuring-reward-hacking-in-llm-coding-1),
  [arxiv 2604.15149](https://arxiv.org/abs/2604.15149)).
  *Our evidence channel stops fabricated output but not a gamed oracle — hence the
  critic-prompt guard, and the standing idea to review the command/test definition
  itself, and prefer oracles the step did not author.*
- **DSPy `Assert` / `Suggest`** — a hard constraint that forces retry-with-feedback vs a
  soft constraint that self-refines but never halts
  ([dspy.ai](https://dspy.ai/learn/programming/7-assertions/),
  [arxiv 2312.13382](https://arxiv.org/abs/2312.13382)).
  *This is exactly accept/reject (must-fix retry) vs advisory, expressed as code.*
- **Guardrails AI provenance validators** — a second LLM (or embedding distance) checks
  whether generated text is supported by the provided sources
  ([guardrailsai](https://guardrailsai.com/hub/validator/guardrails/provenance_llm)).
  *Cheap gate for "text" artifacts that are claims about sources; weaker than runtime
  evidence because it is LLM-graded.*
- **OpenAI Agents SDK guardrails / tripwires** — parallel input/output/tool guardrails;
  a tripwire halts; a tool guardrail can replace a tool's output
  ([openai](https://openai.github.io/openai-agents-python/guardrails/)).
  *The "replace the tool output" hook is a clean seam to inject a graded evidence record
  between raw command output and the agent's context.*

## 2. Critic / LLM-as-judge / reflection

- **Reflexion (verbal reinforcement)** — convert a pass/fail signal into a *written*
  self-critique stored and prepended to the next attempt; a "semantic gradient"
  ([arxiv 2303.11366](https://arxiv.org/html/2303.11366)).
  *A reject should carry the critic's prose reason into the retry's fresh context, not
  just re-run blind — we already do this for the re-draft.*
- **Anthropic evaluator-optimizer** — generator + evaluator loop, recommended only "when
  we have clear evaluation criteria and iterative refinement provides measurable value"
  ([anthropic.com](https://www.anthropic.com/research/building-effective-agents)).
  *The criterion for whether a step even declares a critic — not every step should pay
  for one.*
- **Constitutional AI critique→revision** — a fixed, named set of principles drives
  critique-then-revise
  ([anthropic.com](https://www.anthropic.com/research/constitutional-ai-harmlessness-from-ai-feedback)).
  *Each critic gets an explicit named rubric and must cite which rule failed — maps onto
  our orphan-critic-rules rejection.*
- **LLM-as-judge: reference-guided grading** — judges are far more reliable given a
  reference/anchor or task-specific grading notes than an abstract scale; strong judges
  reach 80-90% human agreement
  ([arxiv 2408.09235](https://arxiv.org/pdf/2408.09235),
  [databricks](https://www.databricks.com/blog/enhancing-llm-as-a-judge-with-grading-notes)).
  *Feeding the critic the recorded evidence as its reference is exactly "grade against
  the artifacts, not vibes."*
- **Where mainstream is weak — abstention.** Most judge setups force a score and rarely
  abstain, and suffer position/verbosity/self-preference bias
  ([arxiv 2408.09235](https://arxiv.org/pdf/2408.09235)).
  *Our explicit `cannot_verify` tri-state is unusual and correct. Keep the critic on a
  different context/model from the step to blunt self-preference.*

## 3. Step decomposition guidance

- **Anthropic multi-agent scaling rules** — because "agents struggle to judge
  appropriate effort," the prompt hard-codes budgets (1 agent / 3-10 calls for
  fact-finding; 2-4 subagents for comparisons; 10+ only for complex research), fixing
  observed "spawning 50 subagents for simple queries"
  ([zenml](https://www.zenml.io/llmops-database/building-production-multi-agent-research-systems-with-claude)).
  *Give the generator an explicit complexity→step-count rubric; bias toward fewer steps.*
- **ADaPT — as-needed decomposition** — a single executor attempts the task and
  self-assesses; the planner recursively decomposes a sub-task *only when the LLM cannot
  execute it*
  ([arxiv 2311.05772](https://arxiv.org/abs/2311.05772)).
  *The anti-ceremony principle: split only after a monolithic attempt demonstrably
  fails. Fold into RECIPE.*
- **Least-to-most prompting** — an ordered chain where each answer feeds the next; strong
  compositional generalization
  ([arxiv 2205.10625](https://arxiv.org/pdf/2205.10625)).
  *When we do split, an ordered dependency chain (each step consumes the prior's
  param/handoff) is the shape that generalizes — which is our carry model.*
- **Plan-and-Solve** — separate an explicit planning phase from execution
  ([emergentmind](https://www.emergentmind.com/topics/plan-and-solve-prompting); canonical
  arXiv ID unverified). *Keep the plan step cheap and revisable; the first plan is not a
  contract.*

## 4. Durable execution & resume

- **Temporal — deterministic replay over an event history.** Recovery re-executes the
  workflow code and checks regenerated commands against the logged history; hence the
  determinism constraint; guarantees exactly-once activity completion
  ([temporal](https://docs.temporal.io/workflow-execution)).
  *Resume = replay against a recorded log. Cost: determinism constraints on orchestration
  code.*
- **Inngest — step memoization instead of full replay.** Each step's result is persisted;
  completed steps are skipped with stored results injected on re-execution
  ([inngest](https://www.inngest.com/blog/durable-execution-key-to-harnessing-ai-agents)).
  *The better fit for an agent engine — a model call is not deterministically replayable,
  so memoize the exchange/artifact per step and skip completed steps. This is essentially
  our per-step snapshot + recorded exchanges; Inngest is the vocabulary for choosing it
  over Temporal-style replay.*
- **DBOS — checkpoints in the same transaction as app data** — a step's result and its
  side effect commit atomically
  ([dbos.dev](https://www.dbos.dev/blog/building-durable-agents-dbos-databricks)).
  *Co-locating the snapshot store with the run's data buys atomic "step done + artifact
  written" — worth it if snapshot store and project state can share a transaction.*
- **LangGraph checkpointer** — a `StateSnapshot` at every super-step captures channel
  values AND the `next` nodes; a `thread_id` selects which checkpoint to resume
  ([langgraph](https://docs.langchain.com/oss/python/langgraph/interrupts)).
  *Snapshot what runs NEXT, not just current values — the pending-step pointer is what a
  crashed resume needs.*
- **Field convergence** — Temporal, Restate, Inngest, Hatchet, DBOS, Cloudflare
  Workflows, AWS Step Functions all expose the same primitive: persist completed
  boundaries, recover without repeating tool calls
  ([zylos](https://zylos.ai/research/2026-02-17-durable-execution-ai-agents)).
  *"Don't repeat the tool/model call on resume" is the whole game — the agent step is the
  expensive, non-idempotent unit never to re-run silently.*

## 5. Human-in-the-loop pause / resume

- **LangGraph `interrupt()` / `Command(resume=...)`** — `interrupt(value)` surfaces a
  JSON payload, persists state, waits indefinitely; the answer routes back as the return
  value of the original `interrupt()` call
  ([langgraph](https://docs.langchain.com/oss/python/langgraph/interrupts)).
  *The request is typed data and the resume value flows back to the exact call site —
  model our `[question]` marker this way, so the code that asked is the code that
  receives.*
- **Idempotency at the pause boundary** — because nodes re-run from the top on resume,
  side effects before `interrupt()` must be idempotent or you get duplicate records
  ([langgraph](https://docs.langchain.com/oss/python/langgraph/interrupts)).
  *Put the pause before irreversible actions, or memoize them.*
- **Temporal signals** — HITL is pause→signal→resume; the same channel carries human
  input and other external events; can pause up to 24h
  ([fredk8.dev](https://fredk8.dev/blog/durable-ai-agents-orchestrating-the-future-with-fred-and-temporal/)).
  *Unify human answers, budget top-ups, and other events under one typed "signal"
  primitive — our budget-pause and question-pause are two signal types, not two
  mechanisms.*
- **"A human is just a long-running activity."** Every durable engine models "wait for a
  person" identically to "wait for a slow call"
  ([appscale](https://appscale.blog/en/blog/durable-execution-llm-agents-temporal-langgraph-checkpointing-2026)).
  *Don't build a special HITL subsystem — our durable-resume machinery IS the HITL
  machinery, differing only in who supplies the resume value.*
- **OpenAI Agents SDK handoffs (typed routing)** — a handoff is a tool, so routing to
  another agent/human is a typed decision in the palette
  ([openai](https://openai.github.io/openai-agents-python/guardrails/)).
  *Represent "escalate to human" as a palette entry (a typed request) so escalation is
  expressible only when appropriate — aligns with "constraints belong in the API."*

## 6. Progressive / dynamic tool disclosure

- **Anthropic Tool Search Tool (`defer_loading`)** — deferred tools are hidden; the model
  sees only the search tool plus non-deferred tools, searches an index, and matching
  definitions are injected on demand. Index backends: regex, BM25, or embeddings.
  Measured: ~77K→~8.7K tokens (85% reduction), Opus 4.5 accuracy 79.5%→88.1%; cost is an
  extra search step's latency
  ([anthropic](https://www.anthropic.com/engineering/advanced-tool-use)).
  *This is our `search_tools` almost exactly. Adopt BM25 over names+docstrings as the
  default; reserve embeddings for large, paraphrase-heavy palettes.*
- **Programmatic tool calling** — the model writes code that orchestrates several tools in
  a sandbox; only the final output re-enters context, avoiding many inference passes
  ([anthropic](https://www.anthropic.com/engineering/advanced-tool-use)).
  *For code-steps that read params, calling tools in code rather than round-tripping every
  result through the model is a large context saving — relevant to the param channel.*
- **MCP dynamic tool discovery** — the same pattern for MCP servers, so big catalogs don't
  burn ~55K tokens before the first turn
  ([tessl](https://tessl.io/blog/anthropic-brings-mcp-tool-search-to-claude-code/)).
  *If tools are ever MCP-backed, discovery should be lazy per-server.*
- **Tradeoff** — tool search adds latency and can misfire on ambiguous names
  ([anthropic](https://www.anthropic.com/engineering/advanced-tool-use)).
  *Keep a small always-loaded core palette non-deferred; defer only the long tail — a
  hybrid, not all-or-nothing.*

## 7. Self-generating / skill-library agents

- **Voyager — an ever-growing skill library of executable code.** The agent writes code to
  master a skill and stores it *only after self-verification passes*; skills are retrieved
  by embedding similarity and composed; they transfer to a fresh world
  ([arxiv 2305.16291](https://arxiv.org/abs/2305.16291)).
  *The blueprint for workflow-generates-workflow: store the solver as code, admit it only
  after a verification gate (our critic), index for retrieval. Compatible with "generation
  strategies as prose, artifacts as code."*
- **ADAS / Meta Agent Search** — a meta-agent iteratively *programs* new agent designs in
  a code space, augmented by an archive of prior discoveries; invented agents beat
  hand-designed ones and transfer
  ([arxiv 2408.08435](https://arxiv.org/abs/2408.08435)).
  *The archive-augmented loop — feed prior saved solvers into the generator's context so
  generation improves monotonically rather than starting cold.*
- **Generative Agents — memory stream + reflection** — retrieval scores by recency ×
  importance × relevance; periodic reflection synthesizes observations into higher-level
  insights written back
  ([park et al.](https://3dvar.com/Park2023Generative.pdf)).
  *(1) that retrieval formula is a good default for surfacing past solvers/lessons; (2)
  "reflection" is how a run's evidence/handoffs could distill into a reusable strategy
  note (the KB record action).*

---

## Gaps — where this engine may already be ahead

- **The EVIDENCE artifact kind is stronger than mainstream "tool-result provenance."**
  Guardrails grades prose with another LLM; Anthropic's guidance leaves representation to
  you. Our three-way split — claim (text) vs file vs runtime-executed, pass/fail-graded
  verbatim output the agent cannot author — is a sharper ontology than any framework
  surveyed; nobody makes "an agent literally cannot fabricate this field" a first-class
  type. **Caveat:** it closes fabrication, not oracle-gaming — so the command deserves its
  own review.
- **A critic that may only replay commands the step recorded** is deterministic-replay
  repurposed for verification: the verdict is reproducible and can't wander off-task.
  **Caveat:** replay-only can verify what the step tested but is blind to what it *should*
  have tested — pair it with a rubric that can reject for *missing* evidence.
- **The tri-state `cannot_verify` verdict is ahead of the field** — the judge literature
  overwhelmingly forces a score and rarely abstains. Keep it.
- **Workflow-generates-workflow, persisted as a durable, resumable, critic-gated class,**
  combines two research lines nobody was found to combine — Voyager/ADAS generate
  skills/agents as code but in research harnesses, not durable production workflows;
  Temporal/DBOS give durable execution but assume human-authored workflows. The borrow-back
  is only the discipline: an archive to condition generation, a hard gate before admission.
- **Three explicit typed carries (artifact / handoff / param) with a fresh context per
  step** is a stricter context-isolation contract than the mainstream (LangGraph carries
  whole shared state; OpenAI handoffs pass the entire prior conversation). Worth documenting
  as a deliberate design stance.
