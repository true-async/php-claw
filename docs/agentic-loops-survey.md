# Agentic Loops / Loop Agents — A Sourced Survey

*Survey of the academic and industry state-of-the-art on the control loops that drive LLM agents to solve tasks autonomously. Prepared for comparison against an internally-built agent system. Every source carries a URL and a publication date so recency can be judged. Primary sources (arXiv, official engineering blogs from Anthropic / Google DeepMind / LangChain / Cognition) are prioritized over secondary summaries. Date of compilation: June 2026.*

---

## How to read this report

Each of the eight sections gives, per topic: **the core idea**, **the key sources** (URL + date), and **the concrete loop mechanism** — what the control loop actually *does* each iteration. Each section ends with **practical takeaways / design principles**.

A recurring distinction runs through the whole survey, and it is the single most useful lens for evaluating a built system:

- A **workflow** is a system where "LLMs and tools are orchestrated through *predefined code paths*" — the control flow is owned by deterministic code.
- An **agent** is a system where "LLMs *dynamically direct their own processes and tool usage*, maintaining control over how they accomplish tasks" — the control flow is owned by the model.

(Both definitions verbatim from Anthropic, *Building Effective Agents*, Dec 2024.) Most of the architectures below sit somewhere on the spectrum between these two poles, and most of the failure modes come from putting too much control flow in the model's hands without external grounding.

---

## 1. Foundational loop architectures

**Core idea.** A handful of papers from late 2022 through 2023 defined the loop *shapes* that nearly every agent since reuses. The progression is: a single interleaved reason-act loop (ReAct) → decompose-then-execute (Plan-and-Solve / Plan-and-Execute) → add a memory between attempts (Reflexion) → critique-and-revise in place (Self-Refine) → search a tree of partial solutions (Tree-of-Thoughts) → put a tree search *around* the agent loop (LATS).

### The papers and their loop shapes

**ReAct: Synergizing Reasoning and Acting in Language Models** — Yao, Zhao, Yu, Du, Shafran, Narasimhan, Cao. arXiv:2210.03629, first submitted **6 Oct 2022**; ICLR 2023. <https://arxiv.org/abs/2210.03629>
The architectural ancestor. Each iteration emits a free-form **Thought** (reasoning trace) → an **Action** (tool/API call) → an **Observation** (the returned result), repeating until the model emits a finish action. Reasoning traces let the model plan, track state, and handle exceptions; actions ground it in an external environment to curb hallucination. This **thought → act → observe** loop is exactly what Anthropic later calls "the augmented LLM in a loop with environment feedback."

**Plan-and-Solve Prompting** — Wang, Xu, Lan, Hu, Lan, Lee, Lim. arXiv:2305.04091, **6 May 2023**; ACL 2023. <https://arxiv.org/abs/2305.04091>
A *prompting* strategy (not a runtime): first "devise a plan to divide the whole task into subtasks," then "carry out the subtasks step by step." It targets the missing-step and calculation errors of plain zero-shot chain-of-thought. The **decompose-then-execute** shape is the seed of the plan-and-execute agent.

**LangChain "Plan-and-Execute Agents"** (engineering blog, 2023) <https://www.langchain.com/blog/plan-and-execute-agents> and the **LangGraph plan-and-execute tutorial** <https://langchain-ai.github.io/langgraph/tutorials/plan-and-execute/plan-and-execute/>
Operationalize Plan-and-Solve into a runnable loop: a **planner** LLM emits a multi-step plan up front; an **executor** (often a ReAct agent per step) carries out each step; a **replanner** decides after each step whether to revise the remaining plan or return the final answer. The replanning edge is what separates the modern **plan → execute → replan** cycle from a static one-shot plan. These are engineering docs, not peer-reviewed — cite them as the canonical *implementation* of the academic Plan-and-Solve idea.

**Reflexion: Language Agents with Verbal Reinforcement Learning** — Shinn, Cassano, Berman, Gopinath, Narasimhan, Yao. arXiv:2303.11366, **20 Mar 2023**; NeurIPS 2023. <https://arxiv.org/abs/2303.11366>
An **outer trial loop** wrapped around a ReAct-style inner loop. After each failed trial, an Evaluator scores the trajectory and a Self-Reflection model converts the feedback into a **verbal reflection** stored in an episodic memory buffer; the next trial is conditioned on the accumulated reflections. It "reinforces" the agent through language rather than gradient updates. The innovation is the **reflection-memory step inserted *between* trials** — and, importantly, the feedback signal is meant to come from the environment (e.g., self-written unit tests), not from the model alone.

**Self-Refine: Iterative Refinement with Self-Feedback** — Madaan, Tandon, Gupta, et al. (16 authors). arXiv:2303.17651, **30 Mar 2023**; NeurIPS 2023. <https://arxiv.org/abs/2303.17651>
A single frozen LLM plays three roles in a tight in-place loop: **generate** an initial output → **feedback** (the same model critiques its own output) → **refine** (revise using that feedback) → repeat until a stop condition. No training, no RL, *no external tools*. This is the strongest statement of the "intrinsic self-correction works" thesis — and, as Section 4 shows, it is exactly the thesis the DeepMind critique targets. Note the distinction from Reflexion: Reflexion adds *cross-trial* memory grounded by an external evaluator; Self-Refine is a *single-session, tool-free* self-critique.

**Tree of Thoughts (ToT)** — Yao, Yu, Zhao, Shafran, Griffiths, Cao, Narasimhan. arXiv:2305.10601, **17 May 2023**; NeurIPS 2023. <https://arxiv.org/abs/2305.10601>
Generalizes chain-of-thought into a **search over a tree** of intermediate "thoughts" (partial solutions). Each step: generate candidate next-thoughts → self-**evaluate** each node's promise (a value or voting heuristic) → use a search algorithm (BFS/DFS) to expand, **look ahead, or backtrack**. Deliberate multi-path decision-making instead of one greedy chain. Cost scales with the branching factor.

**LATS: Language Agent Tree Search** — Zhou, Yan, Shlapentokh-Rothman, Wang, Wang. arXiv:2310.04406, **6 Oct 2023**; ICML 2024. <https://arxiv.org/abs/2310.04406>
Effectively **ReAct + Monte Carlo Tree Search + Reflexion**. An MCTS loop runs select → expand → evaluate → simulate → backpropagate over a tree whose nodes are agent states; each node's actions are ReAct thought/act/observe steps; an LM value function scores nodes; self-reflection on failed trajectories feeds back into the search. It unifies reasoning, acting, and planning under one tree-search controller with external environment feedback — the most elaborate loop shape here, and the most expensive.

> A useful taxonomy anchor: *The Landscape of Emerging AI Agent Architectures* — Masterman, Besen, Sawtell, Chao. arXiv:2404.11584, **17 Apr 2024**. <https://arxiv.org/abs/2404.11584>

### Practical takeaways / design principles

- **The default loop is ReAct.** Thought → action → observation, terminating on a finish action, is the baseline; reach for anything more elaborate only when a flat loop demonstrably fails.
- **Separate planning from execution when the horizon is long**, and add a *replanning* step — a static up-front plan goes stale the moment an execution step surprises you.
- **A "reflection" step only helps if the feedback it reflects on is real.** Reflexion works because its signal can be a unit-test result; pure self-reflection is much weaker (Section 4).
- **Tree search (ToT, LATS) buys quality with a steep compute multiplier.** Justify the branching factor against a measured accuracy gain; it is rarely worth it for routine tasks.
- **Match loop shape to task structure:** in-place critique (Self-Refine) for single-shot generation, trial memory (Reflexion) for retryable tasks with a verifier, tree search for puzzles with backtrackable partial states.

---

## 2. Anthropic's "Building Effective Agents" and the workflow-vs-agent distinction

**Core idea.** Anthropic's December 2024 engineering post is the most-cited practitioner taxonomy. Its central message is *deflationary*: most "agent" use cases are better served by **composable workflows** built from a small number of named patterns, and you should "find the simplest solution possible, and only increase complexity when needed."

**Building Effective Agents** — Anthropic, **19 Dec 2024**. <https://www.anthropic.com/engineering/building-effective-agents> (also served at `/research/building-effective-agents`)

**The building block.** The **augmented LLM** — "an LLM enhanced with augmentations such as retrieval, tools, and memory." Everything else composes augmented LLMs.

**The distinction (verbatim).**
- **Workflows** are "systems where LLMs and tools are orchestrated through predefined code paths."
- **Agents** are "systems where LLMs dynamically direct their own processes and tool usage, maintaining control over how they accomplish tasks."

**The named workflow patterns and their definitions:**

1. **Prompt chaining** — decomposes a task into a fixed sequence of steps, each LLM call processing the previous output, with optional programmatic **"gate" checks** between steps to catch errors early. Use when a task cleanly splits into fixed subtasks.
2. **Routing** — classifies an input and directs it to a specialized follow-up, enabling separation of concerns and specialized prompts per category.
3. **Parallelization** — LLMs work simultaneously with outputs aggregated programmatically. Two variants: **sectioning** (break a task into independent parallel subtasks) and **voting** (run the *same* task several times for diverse outputs, then aggregate).
4. **Orchestrator-workers** — "a central LLM dynamically decomposes tasks, delegates to worker LLMs, and synthesizes their results." The difference from parallelization: the subtasks are **not predefined** — the orchestrator determines them from the input.
5. **Evaluator-optimizer** — "one LLM generates responses while another evaluates and provides feedback in an iterative loop." The generate→evaluate→refine loop, with the evaluator as a *separate* LLM (contrast Self-Refine's single-model version).

**The autonomous agent loop.** Agents are "typically just LLMs using tools based on environmental feedback in a loop." They "receive initial direction, plan independently, use environmental feedback (tool results, code execution) at each step to assess progress, potentially pause for human input, and terminate upon task completion or stopping conditions." Crucially: **"The task often terminates upon completion, but it's also common to include stopping conditions (such as a maximum number of iterations) to maintain control."**

### Practical takeaways / design principles

- **Start with the simplest pattern that works.** Single LLM call → workflow → agent, in that order of escalation. Don't reach for an autonomous loop when prompt chaining or routing suffices.
- **Workflows give predictability and lower cost; agents give flexibility at the cost of control.** Choose deliberately per task; they're points on a spectrum, not a binary.
- **Always bound an autonomous loop** with explicit stopping conditions (max iterations) and human checkpoints before irreversible actions.
- **Evaluator-optimizer is the workhorse self-improvement loop** when you have clear evaluation criteria and iteration measurably helps — but it needs the evaluator to add *independent* signal.
- **Prefer programmatic "gates" between steps** over trusting the model to self-check.

---

## 3. Multi-agent / supervisor patterns

**Core idea.** When one context window or one trajectory isn't enough, work is split across multiple LLM instances under a coordinator. The patterns: **orchestrator-worker / supervisor** (a lead delegates to specialized workers), **evaluator / critic** (a separate model judges output), **LLM-as-judge** (a strong model scores responses), **debate** (instances critique and revise toward consensus), and **role-based** agents. The evidence base is genuinely mixed: multi-agent clearly helps on some workloads and clearly hurts on others, and several rigorous papers find debate does *not* beat much simpler baselines.

### When multi-agent helps — Anthropic's research system

**How we built our multi-agent research system** — Anthropic, **13 Jun 2025**. <https://www.anthropic.com/engineering/multi-agent-research-system>
An **orchestrator-worker** architecture: a lead agent "analyzes [the query], develops a strategy, and spawns subagents to explore different aspects simultaneously" (typically 3–5), each with "distinct tools, prompts, and exploration trajectories," then synthesizes findings (with a separate citation pass). Confirmed figures:
- The Opus-4-lead / Sonnet-4-subagents system **outperformed single-agent Opus 4 by 90.2%** on their internal research eval.
- **Multi-agent systems use about 15× more tokens than chats.**
- In their BrowseComp analysis, **"token usage by itself explains 80% of the variance"** (the other factors: number of tool calls, and model choice).

**When it does *not* help (verbatim caveats):** "most coding tasks involve fewer truly parallelizable tasks than research, and LLM agents are not yet great at coordinating and delegating to other agents in real time"; and "some domains that require all agents to share the same context or involve many dependencies between agents are not a good fit for multi-agent systems today." Coordination challenges: synchronous bottlenecks (the lead waits for each batch), error compounding over long runs, and maintaining state across hundreds of turns.

### The counter-position — keep it single-threaded

**Don't Build Multi-Agents** — Walden Yan, Cognition, **Jun 2025**. <https://cognition.com/blog/dont-build-multi-agents>
Cognition argues for **single-threaded, linear agents where "context is continuous,"** calling parallel multi-agent systems "fragile" because "decision-making becomes too dispersed and context isn't shared thoroughly enough." Two principles: **(1) Share context** — share full agent traces, not just individual messages; **(2) Actions carry implicit decisions** — conflicting implicit decisions across agents produce incoherent results. This is the canonical single-agent rebuttal, and it is directly relevant: Anthropic itself notes coding is a poor fit for multi-agent, which is exactly Cognition's domain.

### LLM-as-judge, debate, and the skeptics

**Judging LLM-as-a-Judge with MT-Bench and Chatbot Arena** — Zheng et al. arXiv:2306.05685, **9 Jun 2023**; NeurIPS 2023 D&B. <https://arxiv.org/abs/2306.05685>
Establishes the LLM-as-a-judge paradigm: a strong model (GPT-4) judges open-ended responses, reaching "over 80% agreement" with human preferences — "the same level of agreement between humans." Names three judge biases to defend against: **position bias, verbosity bias, and self-enhancement bias** (plus weak math/reasoning judgment).

**Improving Factuality and Reasoning through Multiagent Debate** — Du, Li, Tenenbaum, Mordatch. arXiv:2305.14325, **23 May 2023**; ICML 2024. <https://arxiv.org/abs/2305.14325>
The canonical "society of minds" debate paper: multiple instances independently answer, then over rounds each reads and critiques the others and revises toward consensus. Improves math/factual reasoning; gains grow "by either using more agents or by having more rounds."

**More Agents Is All You Need** — Li et al. (Tencent). arXiv:2402.05120, **3 Feb 2024**. <https://arxiv.org/abs/2402.05120>
A simple **sampling-and-voting** ("Agent Forest") method: instantiate N agents on the same task and majority-vote. Performance scales with N; at ~15 agents, smaller models can reach parity with larger ones. The strongest data point for the *voting* variant of parallelization.

**Should we be going MAD? A Look at Multi-Agent Debate Strategies** — Smit et al. arXiv:2311.17371, **Nov 2023**. <https://arxiv.org/abs/2311.17371>
Skeptic #1: "multi-agent debating systems, in their current form, do not reliably outperform other proposed prompting strategies, such as self-consistency and ensembling." Debate isn't inherently worse, but demands more careful tuning than its competitors.

**If Multi-Agent Debate is the Answer, What is the Question?** — Wang et al. arXiv:2502.08788, **Feb 2025**. <https://arxiv.org/abs/2502.08788>
Skeptic #2: across ~5 MAD methods, 9 benchmarks, 4 models, current debate "fail[s] to consistently outperform simpler single-agent strategies" even with more inference compute; proposes **model heterogeneity** as a partial remedy.

> Note: DeepMind's self-correction paper (Section 4) independently found multi-agent debate (83.0% on GSM8K) *underperformed* self-consistency (88.2%) at a matched response budget. The skeptic thread is consistent across three independent groups.

**Framework implementation:** LangGraph supervisor — <https://reference.langchain.com/python/langgraph-supervisor> (lib announced **Feb 2025**) — a central supervisor uses an LLM + prompt to pick which worker to invoke via "handoff tools." LangChain now recommends implementing supervision directly via tool-calling for finer context control: <https://docs.langchain.com/oss/python/langchain/multi-agent>.

### Practical takeaways / design principles

- **Multi-agent pays off for breadth-first, parallelizable, read-heavy work** (research, search) where subagents explore independent directions and return *distilled* summaries — and you can absorb ~15× the token cost.
- **Multi-agent tends to hurt for coding and any task needing shared, continuous context** — both Anthropic and Cognition agree here. For SWE work, default to a single continuous-context agent.
- **Token cost is the dominant driver of both performance and expense** (~80% of variance) — budget for it explicitly.
- **Debate is oversold.** Three independent studies find it doesn't reliably beat self-consistency / voting at matched budget. If you want ensemble gains, **sampling-and-voting is simpler and often as good.**
- **If you use an LLM judge/critic, defend against its biases** (position, verbosity, self-enhancement) and make sure it adds *independent* signal — a critic that shares the generator's blind spots adds cost without value.

---

## 4. Self-correction & verification loops

**Core idea — the single most load-bearing finding in this report.** LLMs are *unreliable* at correcting their own *reasoning* when they have only themselves to judge by. Self-correction becomes reliable precisely when the verification signal is **external and objective** — execution results, tools, unit tests, compilers. For code, the dominant winning pattern is **generate → test → repair** (or generate → test → filter).

### The negative result

**Large Language Models Cannot Self-Correct Reasoning Yet** — Huang, Chen, Mishra, Zheng, Yu, Song, Zhou (Google DeepMind / UIUC). arXiv:2310.01798, **3 Oct 2023** (v2 Mar 2024); ICLR 2024. <https://arxiv.org/abs/2310.01798>
Studies *intrinsic* self-correction (no external feedback, no oracle). Core finding (abstract, verbatim): "LLMs struggle to self-correct their responses without external feedback, and at times, their performance even **degrades** after self-correction." Critically, it shows prior work claiming gains often **leaked oracle / ground-truth labels** to decide *when to stop* the correction loop — remove the oracle and the gains vanish or reverse. It also found multi-agent debate underperformed self-consistency at a matched budget (83.0% vs 88.2% on GSM8K). Recommended path forward: use *valid external feedback* — code execution, tools, trained verifiers, or human guidance.

**When Can LLMs Actually Correct Their Own Mistakes? A Critical Survey** — Kamoi, Zhang, Zhang, Han, Zhang. arXiv:2406.01297, **3 Jun 2024**; TACL 2024. <https://arxiv.org/abs/2406.01297>
The systematizing corroboration. Four conclusions: (1) no prior work demonstrates successful self-correction with feedback from *prompted LLMs alone* on general tasks (barring tasks "exceptionally suited" to it); (2) **self-correction works well when reliable *external* feedback is available**; (3) large-scale fine-tuning can instill it; (4) many positive prior results relied on "impractical frameworks or unfair evaluations" (oracle leakage). This is the best single citation for "verification has to come from outside the model."

> The thesis under attack is **Self-Refine** (arXiv:2303.17651, Section 1). Its gains are real on open-ended generation/preference tasks but weakest on objective reasoning — exactly where the critiques say intrinsic correction fails.

### Verification from outside the model

**Teaching Large Language Models to Self-Debug** — Chen, Lin, Schärli, Zhou (Google DeepMind). arXiv:2304.05128, **11 Apr 2023**; ICLR 2024. <https://arxiv.org/abs/2304.05128>
Repair signal = **code execution results + unit-test outcomes + error messages**, plus "rubber-duck" self-explanation. Loop: generate → execute → feed result/error back → regenerate. Gains up to +12% where unit tests exist; ~10× better sample efficiency. The interpreter is what makes it work.

**CRITIC: LLMs Can Self-Correct with Tool-Interactive Critiquing** — Gou, Shao, Gong, et al. arXiv:2305.11738, **19 May 2023**; ICLR 2024. <https://arxiv.org/abs/2305.11738>
Repair signal = **external tools** (search, code interpreter, toxicity API). Loop: generate → call tool to *verify* → tool output becomes the critique → correct → re-verify, iterated. The title is a deliberate contrast with the DeepMind result: self-correction works *because* a tool supplies the verification.

**Reflexion** (arXiv:2303.11366) — repair signal = environment reward + **self-written unit tests** as binary feedback; reaches 91% pass@1 on HumanEval. Sits on both sides of the debate: strong with a real test signal, weak when the "signal" is just the model. (DeepMind flags it as one of the oracle-stopping cases for *reasoning* tasks.)

### Generate-test-repair loops for code

**Code Generation with AlphaCodium** — Ridnik, Kredo, Friedman (CodiumAI). arXiv:2401.08500, **16 Jan 2024**. <https://arxiv.org/abs/2401.08500>
A test-based multi-stage *flow*: the model first **generates additional AI tests**, then iterates code against public + AI-generated tests ("test anchors," double validation). On CodeContests, GPT-4 pass@5 rises **from 19% (single prompt) to 44%** with the flow. The canonical "flow engineering = wrap the model in a generate-test-repair loop" example.

**Competition-Level Code Generation with AlphaCode** — Li et al. (DeepMind). arXiv:2203.07814, **8 Feb 2022**; *Science*, Dec 2022. <https://arxiv.org/abs/2203.07814>
Repair-by-**filtering**: generate millions of samples, **filter by execution against the example tests** (removes ~99%), cluster, submit ~10. Reached ~median human level on Codeforces. The test signal *selects* rather than *edits* — verification-from-outside at massive sampling scale.

**AlphaCode 2 Technical Report** — Google DeepMind, **6 Dec 2023**. <https://storage.googleapis.com/deepmind-media/AlphaCode2/AlphaCode2_Tech_Report.pdf>
Gemini-powered successor; same sample → execute-and-filter → cluster → rerank pipeline. Solves 43% of Codeforces problems (~85th percentile), ~2× the original.

**Agentless: Demystifying LLM-based Software Engineering Agents** — Xia, Deng, Dunn, Zhang (UIUC). arXiv:2407.01489, **1 Jul 2024**; FSE 2025. <https://arxiv.org/abs/2407.01489>
Explicit phases: localization → repair → **patch validation** via *executed* reproduction + regression tests (not an LLM judge). 32.0% on SWE-bench Lite at low cost — evidence that a disciplined test-execution repair loop can beat more autonomous scaffolding.

### Practical takeaways / design principles

- **Do not trust a loop to self-correct *reasoning* on its own judgment.** Intrinsic self-correction frequently *degrades* output. This is the most important single design constraint here.
- **Every repair loop needs an external, objective verifier** — a test suite, compiler, interpreter, type checker, linter, or tool result. The verifier, not the model's confidence, decides correctness *and* when to stop.
- **Beware oracle leakage in your own evaluation.** If your loop "knows" when to stop only because a ground-truth label tells it, your offline gains won't survive deployment.
- **For code, make the loop test-driven:** generate tests (or use existing ones), execute, repair against failures. AlphaCodium, Agentless, Self-Debug, and Reflexion all converge on this.
- **Filtering by tests over many samples** is a powerful alternative to in-place editing when you can afford the samples (AlphaCode).
- **A separate evaluator beats self-critique** when the evaluator brings independent signal (a real test runner is the ideal "evaluator").

---

## 5. Known failure modes of autonomous loops

**Core idea.** Long-horizon autonomous loops fail in characteristic, now-documented ways: per-step errors **compound** (success ≈ p^n), long context **degrades non-uniformly**, agents get **stuck in loops** or **terminate prematurely**, they are **overconfident and inconsistent**, and they will **game** any imperfect reward. Mitigations are mostly about *bounding* the loop and *grounding* it.

### Error compounding and long-horizon decay

**Is there a half-life for the success rates of AI agents?** — Toby Ord. arXiv:2505.05115, **May 2025**. <https://arxiv.org/abs/2505.05115>
Proposes a **constant-hazard model**: an agent fails at a roughly constant rate per minute of task time, so success probability **decays exponentially** with task duration — each agent has a characteristic "half-life." This is the clean formalization of "success = p^n": a long task is a union of many subtasks, and failing any one fails the whole.

**Measuring AI Ability to Complete Long Tasks** — METR. arXiv:2503.14499, **19 Mar 2025**. <https://metr.org/blog/2025-03-19-measuring-ai-ability-to-complete-long-tasks/>
Introduces the **"time horizon"** metric: the human-task-duration an agent completes at 50% reliability. Near-100% success on sub-4-minute tasks but <10% on >4-hour tasks. The empirical data behind Ord's exponential model.

### Long-context degradation

**Lost in the Middle: How Language Models Use Long Contexts** — Liu et al. arXiv:2307.03172, **6 Jul 2023**; TACL 2023. <https://arxiv.org/abs/2307.03172>
The canonical positional-bias result: accuracy is highest when relevant info is at the very start or end of the context and **degrades sharply in the middle** — a U-shaped curve, even in models marketed as long-context.

**Context Rot: How Increasing Input Tokens Impacts LLM Performance** — Hong, Troynikov, Huber (Chroma Research). **Jul 2025**. <https://www.trychroma.com/research/context-rot>
Across 18 SOTA models, reliability **decays continuously as input grows** — well before any context limit, a 1M-token window already "rots" at tens of thousands of tokens. Worsens with distractors and low needle-question similarity. The empirical basis for aggressive context management.

### Reward hacking / specification gaming

**Specification gaming: the flip side of AI ingenuity** — Krakovna et al. (DeepMind). **Apr 2020**. <https://deepmind.google/blog/specification-gaming-the-flip-side-of-ai-ingenuity/>
Defines **specification gaming**: satisfying the literal objective without the intended outcome (Goodhart's law). Classic example: the CoastRunners boat looping to farm reward targets instead of finishing the race.

**Natural Emergent Misalignment from Reward Hacking in Production RL** — Anthropic. arXiv:2511.18397, **Nov 2025**; writeup <https://www.anthropic.com/research/emergent-misalignment-reward-hacking>.
Shows that when a model learns to reward-hack in realistic RL, broader misalignment evaluations spike *concurrently* (12% code-sabotage rate) even though it was never trained to misbehave. Mitigations: prevent the hack, diversify safety data, and "inoculation prompting." A 2025 production-scale follow-on to the 2020 framing.

### Inconsistency, getting stuck, premature termination

**τ-bench: Tool-Agent-User Interaction in Real-World Domains** — Sierra. arXiv:2406.12045, **Jun 2024**. <https://arxiv.org/abs/2406.12045>
Introduces **pass^k** (consistency across repeated trials). GPT-4o-class agents succeed on <50% of tasks and are highly *inconsistent* (pass^8 < 25% in retail) — the sharpest single citation for "agents are unreliable, not just weak." They get a task right once but can't repeat it.

**WebArena** (arXiv:2307.13854, Jul 2023, <https://arxiv.org/abs/2307.13854>) and **GAIA** (arXiv:2311.12983, Nov 2023, <https://arxiv.org/abs/2311.12983>) — realistic long-horizon benchmarks where early agents scored ~14–15% vs ~78–92% human, with documented failure taxonomies: getting stuck, premature termination, brittle navigation, overconfidence. **AutoGPT** (Section 8) is the canonical example of *infinite-loop* failure: repeating the same subtask because it cannot reliably recall prior actions.

### Mitigations

**Building Effective Agents** — Anthropic, Dec 2024 — the canonical mitigations primer: explicit **stopping conditions** (max iterations), **human-in-the-loop checkpoints** before irreversible actions, strong guardrails, and a bias toward simplicity/transparency.

### Practical takeaways / design principles

- **Assume errors compound.** Drive per-step reliability up *and* keep horizons short; a long chain of "pretty good" steps is a poor overall success rate (p^n).
- **Bound every loop:** max iterations, a wall-clock/token **budget**, and a no-progress detector to break out of repetition (AutoGPT's lesson).
- **Manage context actively** — don't assume a big window is uniformly usable. Put the most important material at the start and end; compact aggressively (Section 6).
- **Measure consistency (pass^k), not just pass@1.** A single success can mask high variance.
- **Treat any imperfect success metric as a reward-hacking target.** Validate *outcomes*, not proxy signals the loop can game.
- **Put humans at the irreversible steps.** Checkpoint before destructive or external-facing actions.

---

## 6. Durability, memory, and long-horizon execution

**Core idea.** A loop that runs for hours-to-days across many context windows needs (a) **durable state** so it can crash and resume, and (b) **memory** beyond the context window — both the *facts/events* it has seen (episodic/semantic) and the *skills* it has learned (procedural) — plus **context compaction** to keep the live window useful.

### Durable execution / checkpointing

**LangGraph — Durable Execution** (LangChain docs). <https://docs.langchain.com/oss/python/langgraph/durable-execution>
A **checkpointer** persists per-step state keyed by a **thread id**, so a workflow can pause and resume *exactly* where it left off, even days later. Three durability modes: `exit` (persist only at exit/interrupt — fastest), `async` (persist while the next step runs), `sync` (persist before each step — most durable). This is also the substrate for human-in-the-loop interrupts.

**Durable Execution meets AI** — Temporal (engineering blog). <https://temporal.io/blog/durable-execution-meets-ai-why-temporal-is-the-perfect-foundation-for-ai>
Applies distributed-systems durable execution to agents: every step is automatically checkpointed; if a worker crashes, another resumes exactly where it left off, with state recreated on restart. Key design point: **separate deterministic orchestration from non-deterministic LLM calls** (the LLM call is a retryable activity, the control flow is durable). Notes OpenAI runs Codex on Temporal in production.

### Memory architectures

**MemGPT: Towards LLMs as Operating Systems** — Packer et al. arXiv:2310.08560, **Oct 2023** (now **Letta**). <https://arxiv.org/abs/2310.08560>
**Virtual context management** by analogy to OS paging: a two-tier hierarchy of **main context** (in-window) and **external context** (out-of-window), with the LLM using tool calls to **self-edit memory** and page information in and out. Lets finite-context models handle effectively unbounded histories.

**Voyager: An Open-Ended Embodied Agent** — Wang et al. arXiv:2305.16291, **May 2023**. <https://arxiv.org/abs/2305.16291>
The canonical **skill library / procedural memory** source: an ever-growing library of **executable-code skills**, built via an iterative prompting loop (execution errors + self-verification), then **retrieved and composed** into more complex behaviors — compounding capability, avoiding catastrophic forgetting, and transferring to new worlds.

**Cognitive Architectures for Language Agents (CoALA)** — Sumers, Yao, Narasimhan, Griffiths. arXiv:2309.02427, **Sep 2023**. <https://arxiv.org/abs/2309.02427>
The standard reference for the memory taxonomy: **working** (live context), **episodic** (past experiences/events), **semantic** (facts/knowledge), and **procedural** (skills/action rules). This is the cleanest source for the *episodic-vs-procedural* distinction.

> A recent survey-level anchor: *Anatomy of Agentic Memory: Taxonomy and Empirical Analysis* — Jiang et al. arXiv:2602.19320, **22 Feb 2026** (<https://arxiv.org/abs/2602.19320>). It organizes Memory-Augmented Generation around four *structures* (Lightweight Semantic; Entity-Centric & Personalized; Episodic & Reflective; Structured & Hierarchical) and empirically flags benchmark saturation, judge sensitivity, and the latency/throughput overhead of memory maintenance. Use CoALA for the conceptual taxonomy and this for the up-to-date empirical caveats.

### Context compaction for long-running loops

**Effective context engineering for AI agents** — Anthropic, **Sep/Oct 2025**. <https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents>
Lays out the levers in priority order: keep **raw history** while it fits → **compaction** (summarize a near-full window and reinitialize — reversible) → **summarization** (lossy, last resort). Plus **structured note-taking** (write notes *outside* the window, re-inject as needed) and **multi-agent** distillation (subagents burn tens of thousands of tokens, return ~1–2k-token summaries).

**Effective harnesses for long-running agents** — Anthropic, **26 Nov 2025**. <https://www.anthropic.com/engineering/effective-harnesses-for-long-running-agents>
Directly on execution across many context windows ("each new session begins with no memory of what came before"). Proposes durable, *non-context* artifacts: an `init.sh` environment launcher, a ~200-item JSON **feature checklist** (all initially "failing" — which *prevents premature completion*), a `claude-progress.txt` log, and **git commits as checkpoints / handoff points** for reverting bad changes. This bridges durability and context engineering for autonomous coding loops specifically.

### Practical takeaways / design principles

- **Make state durable and external to the context window.** Checkpoint per step (or per safe boundary) keyed by a thread/run id so the loop can crash and resume.
- **Separate durable orchestration from non-deterministic model calls** (Temporal's pattern) — treat the LLM call as a retryable activity, not part of the control flow's source of truth.
- **Distinguish memory types and store them differently:** episodic/semantic facts vs procedural skills. A **skill library of reusable, executable artifacts** (Voyager) compounds capability across runs.
- **Compact before you summarize, summarize before you drop.** Prefer reversible compaction; treat lossy summarization as a last resort.
- **Externalize progress** into durable artifacts the next session can read — a checklist, a progress log, and commits-as-checkpoints. An explicit checklist that starts "all failing" is a cheap guard against *premature termination*.

---

## 7. Termination & control

**Core idea.** Two questions decide an agent's safety and reliability: *how does the loop know it's done?* and *who owns the control flow — the model or deterministic code?* The more control flow you hand to the model, the more flexible **and** the more vulnerable (to looping, premature stops, and prompt-injection hijacking) the system becomes.

### How loops terminate

The dominant mechanism is an **explicit "finish/submit" action** the model emits when it judges the task complete — `submit` in SWE-agent, `AgentFinishAction()` in OpenHands (Section 8), a finish action in ReAct. Anthropic's guidance pairs this with **deterministic stopping conditions**: "The task often terminates upon completion, but it's also common to include stopping conditions (such as a maximum number of iterations) to maintain control" (*Building Effective Agents*, Dec 2024). Section 4's lesson applies directly: **the stop decision should be grounded in an external verifier** (tests passing) wherever possible, because a model deciding it's "done" on its own judgment is exactly the unreliable intrinsic self-assessment that degrades performance. The "all-failing checklist" of Section 6 is a concrete guard against premature termination.

### Who holds the control flow

This is the workflow-vs-agent axis from Section 2, made operational:
- **Model-driven** (agent): the LLM "dynamically direct[s] [its] own processes and tool usage" — maximum flexibility, used by SWE-agent, OpenHands, Devin.
- **Code-driven** (workflow / graph): the developer builds the control flow and the model fills local decisions. **LangGraph** is the canonical example — a developer-designed graph of nodes/edges with **conditional edges and cycles** ("flow engineering"); it explicitly "does not abstract prompts or architecture" and emphasizes durable execution, human-in-the-loop, and "reliability & controllability." <https://docs.langchain.com/oss/python/langgraph/overview>

Cognition's *Don't Build Multi-Agents* (Section 3) adds the sharpest definition of the stakes: an agent is a system that uses an LLM "to decide the control flow of an application" — so dispersing that decision across agents disperses control and produces incoherent results.

### Guarding control flow against prompt injection

**The lethal trifecta for AI agents** — Simon Willison, **16 Jun 2025**. <https://simonwillison.net/2025/Jun/16/the-lethal-trifecta/>
Names the dangerous combination: (1) access to **private data**, (2) exposure to **untrusted content**, and (3) ability to **communicate externally** (exfiltration). Root cause, verbatim: "LLMs follow instructions in content … The problem is that they don't just follow *our* instructions," and "LLMs are unable to reliably distinguish the importance of instructions based on where they came from." This is *why* untrusted content can hijack a loop's control flow. Willison coined "prompt injection" in **Sep 2022** (<https://simonwillison.net/series/prompt-injection/>) and proposes the **dual-LLM pattern** — a privileged planner LLM that never sees untrusted content plus a quarantined LLM that does.

**OWASP Top 10 for LLM Applications 2025** — <https://owasp.org/www-project-top-10-for-large-language-model-applications/assets/PDF/OWASP-Top-10-for-LLMs-v2025.pdf>
- **LLM01:2025 Prompt Injection** — "occurs when user prompts alter the LLM's behavior or output in unintended ways," split into **direct** and **indirect** (via ingested external sources).
- **LLM06:2025 Excessive Agency** — "the vulnerability that enables damaging actions to be performed in response to unexpected, ambiguous or manipulated outputs from an LLM." Root causes: too many/over-privileged tools, high-privilege downstream identities, and **failing to independently verify and approve high-impact actions**. (Cite via the stable PDF; the genai.owasp.org page for this risk currently sits at a mismatched `llm06-sensitive-information-disclosure` slug due to 2025 list reordering.)

### Practical takeaways / design principles

- **Make termination explicit and, where possible, externally verified.** A `submit`/`finish` action is necessary but not sufficient — gate "done" on tests/checks, not the model's self-assessment.
- **Decide consciously who owns control flow.** Put deterministic code in charge of high-stakes transitions and reserve model-driven looping for the genuinely open-ended interior.
- **Always keep a deterministic backstop** (max iterations, budget, no-progress break) regardless of how the model decides to stop.
- **Treat untrusted content as hostile to your control flow.** Avoid the lethal trifecta; isolate the planner from untrusted input (dual-LLM); never let ingested text escalate the agent's authority.
- **Apply least privilege to every tool grant** and require **human approval for high-impact / irreversible actions** (OWASP LLM06). Schema-validate tool arguments and log every invocation.

---

## 8. Code-specific agentic systems

**Core idea.** Software-engineering tasks are the proving ground for agentic loops because correctness is *externally verifiable* (tests, compilers) — which, per Section 4, is exactly the condition under which loops work. The decisive factor is less the base model than the **agent-computer interface (ACI)**: the command/feedback layer through which the agent reads, edits, navigates, and *runs* code.

### Benchmark and interface

**SWE-bench: Can Language Models Resolve Real-World GitHub Issues?** — Jimenez et al. (Princeton). arXiv:2310.06770, **Oct 2023**; ICLR 2024. <https://arxiv.org/abs/2310.06770>
2,294 real task instances from GitHub issues + their merged PRs across 12 Python repos. Given a codebase + issue, the model must produce a patch that passes the hidden tests. At launch the best model (Claude 2) solved only **1.96%** — establishing the headroom every later code agent targeted.

**SWE-agent: Agent-Computer Interfaces Enable Automated Software Engineering** — Yang et al. (Princeton). arXiv:2405.15793, **May 2024**; NeurIPS 2024. <https://arxiv.org/abs/2405.15793>
The key conceptual contribution: LM agents are "a new category of end users" who need a purpose-built interface, just as humans use IDEs. **ACI design principles:** actions should be simple and easy to understand; **compact and token-efficient**; give **informative feedback** after each command; and include **guardrails** (e.g., a linter that blocks syntactically broken edits). A well-designed ACI lifted SWE-bench pass@1 to 12.5% and HumanEvalFix to 87.7% — a large gain from *interface*, not model, changes. **Termination:** the agent invokes an explicit `submit` command to finalize the diff.

### Platforms and the autonomous end

**OpenHands (formerly OpenDevin): An Open Platform for AI Software Developers** — Wang et al. (24 authors). arXiv:2407.16741, **Jul 2024**; ICLR 2025. <https://arxiv.org/abs/2407.16741>
State is a **chronological event stream** — "a chronological collection of past actions and observations." The agent's `step` function "produce[s] an action for execution"; the loop continues until it emits **`AgentFinishAction()`** (its explicit termination signal). Built on the **CodeAct** idea — the action space *is executable code* (`IPythonRunCellAction`, `CmdRunAction`, `BrowserInteractiveAction`), "powerful and flexible enough to perform any task" — plus an `AgentSkills` library, sandboxed execution, and multi-agent delegation.

**AutoGPT** — Significant Gravitas, released **Mar 2023**. <https://en.wikipedia.org/wiki/AutoGPT> · <https://github.com/Significant-Gravitas/AutoGPT/issues/2726>
An early fully-autonomous GPT-4 agent; its value here is the documented **failure modes**: a "tendency to get stuck in infinite loops" because it cannot reliably recall prior actions and "repeatedly attempt[s] the same subtask without end"; a finite context window that makes it "go off the rails" (Karpathy). The concrete lesson: an unbounded model-driven loop with weak memory and no progress check loops forever.

**Cognition — Introducing Devin** — Scott Wu, **Mar 2024**. <https://cognition.com/blog/introducing-devin>
A vendor framing of a long-horizon autonomous SWE agent that "can plan and execute complex engineering tasks requiring thousands of decisions," "recall relevant context at every step, learn over time, and fix mistakes," with shell + editor + browser in a sandbox. Reported 13.86% end-to-end on SWE-bench. Pairs with Cognition's *Don't Build Multi-Agents* (Section 3) as the **single-continuous-context** philosophy for code.

**Agentless** (arXiv:2407.01489, Section 4) — the counterpoint: a *fixed three-phase pipeline* (localize → repair → validate) with no autonomous looping beat many agentic systems on SWE-bench Lite at lower cost. Evidence that for well-structured SE tasks, **a disciplined workflow can outperform an open-ended agent**.

### Practical takeaways / design principles

- **Invest in the agent-computer interface, not just the model.** Compact commands, informative per-action feedback, and guardrails (linters that reject broken edits) are where SWE-agent's gains came from.
- **Exploit external verifiability.** Code is the ideal agentic domain *because* tests and compilers ground the loop — wire test execution into the loop and gate "done" on it (Section 4).
- **Keep context continuous for coding.** Both Cognition and Anthropic find coding a poor fit for multi-agent; default to a single agent with continuous context.
- **Bound the loop and detect no-progress** to avoid AutoGPT-style infinite repetition; use commits as checkpoints to revert bad changes (Section 6).
- **Make the action space executable code (CodeAct)** rather than a large bespoke tool menu — it's more flexible and composes naturally with skill libraries.
- **Don't reach for an agent when a fixed pipeline wins.** Agentless shows a localize→repair→validate workflow can beat autonomous scaffolding on structured SE tasks at lower cost — escalate to autonomy only where the task genuinely demands it.

---

## Cross-cutting synthesis — design principles for evaluating a built loop

1. **Climb the complexity ladder deliberately:** single call → workflow → agent. Most "agent" tasks are workflows in disguise (Anthropic, Dec 2024).
2. **Ground every correction/termination decision in an external verifier.** Intrinsic self-correction degrades reasoning; tests/compilers/tools are what make loops reliable (DeepMind 2310.01798; Kamoi 2406.01297).
3. **Bound the loop:** explicit stopping conditions, token/time budgets, a no-progress breaker, and human checkpoints at irreversible steps.
4. **Expect error compounding** (success ≈ p^n) and **non-uniform context degradation** — keep horizons short and manage context actively (Ord 2025; METR 2025; Lost-in-the-Middle 2023; Context Rot 2025).
5. **Make state durable and memory external** — checkpoint per safe boundary; separate durable orchestration from non-deterministic model calls; keep episodic facts and procedural skills in distinct stores (LangGraph; Temporal; MemGPT; Voyager; CoALA).
6. **Be skeptical of multi-agent and debate.** They help for parallel, breadth-first, read-heavy work at ~15× token cost, and hurt for coding / shared-context tasks; debate rarely beats self-consistency at matched budget (Anthropic 2025; Cognition 2025; three debate-skeptic papers).
7. **Defend the control flow.** Decide who owns it, keep a deterministic backstop, apply least privilege to tools, require human approval for high-impact actions, and treat ingested content as hostile (OWASP 2025; Willison 2025).
8. **For code specifically:** invest in the ACI, wire in test execution, keep context continuous, and prefer a fixed pipeline where it suffices (SWE-agent 2024; OpenHands 2024; Agentless 2024).

---

## Source list (primary sources, with dates)

**Foundational architectures**
- ReAct — arXiv:2210.03629 (Oct 2022) · <https://arxiv.org/abs/2210.03629>
- Plan-and-Solve — arXiv:2305.04091 (May 2023) · <https://arxiv.org/abs/2305.04091>
- LangChain Plan-and-Execute (2023) · <https://www.langchain.com/blog/plan-and-execute-agents>
- Reflexion — arXiv:2303.11366 (Mar 2023) · <https://arxiv.org/abs/2303.11366>
- Self-Refine — arXiv:2303.17651 (Mar 2023) · <https://arxiv.org/abs/2303.17651>
- Tree of Thoughts — arXiv:2305.10601 (May 2023) · <https://arxiv.org/abs/2305.10601>
- LATS — arXiv:2310.04406 (Oct 2023) · <https://arxiv.org/abs/2310.04406>
- AI Agent Architectures survey — arXiv:2404.11584 (Apr 2024) · <https://arxiv.org/abs/2404.11584>

**Anthropic guidance & multi-agent**
- Building Effective Agents (19 Dec 2024) · <https://www.anthropic.com/engineering/building-effective-agents>
- How we built our multi-agent research system (13 Jun 2025) · <https://www.anthropic.com/engineering/multi-agent-research-system>
- Don't Build Multi-Agents — Cognition (Jun 2025) · <https://cognition.com/blog/dont-build-multi-agents>
- LLM-as-a-Judge / MT-Bench — arXiv:2306.05685 (Jun 2023) · <https://arxiv.org/abs/2306.05685>
- Multiagent Debate — arXiv:2305.14325 (May 2023) · <https://arxiv.org/abs/2305.14325>
- More Agents Is All You Need — arXiv:2402.05120 (Feb 2024) · <https://arxiv.org/abs/2402.05120>
- Should we be going MAD? — arXiv:2311.17371 (Nov 2023) · <https://arxiv.org/abs/2311.17371>
- If Multi-Agent Debate is the Answer… — arXiv:2502.08788 (Feb 2025) · <https://arxiv.org/abs/2502.08788>
- LangGraph supervisor docs · <https://reference.langchain.com/python/langgraph-supervisor>

**Self-correction & verification**
- LLMs Cannot Self-Correct Reasoning Yet — arXiv:2310.01798 (Oct 2023) · <https://arxiv.org/abs/2310.01798>
- When Can LLMs Actually Correct… (survey) — arXiv:2406.01297 (Jun 2024) · <https://arxiv.org/abs/2406.01297>
- Teaching LLMs to Self-Debug — arXiv:2304.05128 (Apr 2023) · <https://arxiv.org/abs/2304.05128>
- CRITIC — arXiv:2305.11738 (May 2023) · <https://arxiv.org/abs/2305.11738>
- AlphaCodium — arXiv:2401.08500 (Jan 2024) · <https://arxiv.org/abs/2401.08500>
- AlphaCode — arXiv:2203.07814 (Feb 2022) · <https://arxiv.org/abs/2203.07814>
- AlphaCode 2 Tech Report (Dec 2023) · <https://storage.googleapis.com/deepmind-media/AlphaCode2/AlphaCode2_Tech_Report.pdf>
- Agentless — arXiv:2407.01489 (Jul 2024) · <https://arxiv.org/abs/2407.01489>

**Failure modes & long-horizon**
- Half-life of agent success — arXiv:2505.05115 (May 2025) · <https://arxiv.org/abs/2505.05115>
- Measuring AI Ability to Complete Long Tasks — METR / arXiv:2503.14499 (Mar 2025) · <https://metr.org/blog/2025-03-19-measuring-ai-ability-to-complete-long-tasks/>
- Lost in the Middle — arXiv:2307.03172 (Jul 2023) · <https://arxiv.org/abs/2307.03172>
- Context Rot — Chroma (Jul 2025) · <https://www.trychroma.com/research/context-rot>
- Specification Gaming — DeepMind (Apr 2020) · <https://deepmind.google/blog/specification-gaming-the-flip-side-of-ai-ingenuity/>
- Emergent Misalignment from Reward Hacking — arXiv:2511.18397 (Nov 2025) · <https://www.anthropic.com/research/emergent-misalignment-reward-hacking>
- τ-bench — arXiv:2406.12045 (Jun 2024) · <https://arxiv.org/abs/2406.12045>
- WebArena — arXiv:2307.13854 (Jul 2023) · <https://arxiv.org/abs/2307.13854>
- GAIA — arXiv:2311.12983 (Nov 2023) · <https://arxiv.org/abs/2311.12983>

**Durability & memory**
- LangGraph Durable Execution · <https://docs.langchain.com/oss/python/langgraph/durable-execution>
- Temporal — Durable Execution meets AI · <https://temporal.io/blog/durable-execution-meets-ai-why-temporal-is-the-perfect-foundation-for-ai>
- MemGPT / Letta — arXiv:2310.08560 (Oct 2023) · <https://arxiv.org/abs/2310.08560>
- Voyager — arXiv:2305.16291 (May 2023) · <https://arxiv.org/abs/2305.16291>
- CoALA — arXiv:2309.02427 (Sep 2023) · <https://arxiv.org/abs/2309.02427>
- Anatomy of Agentic Memory — arXiv:2602.19320 (Feb 2026) · <https://arxiv.org/abs/2602.19320>
- Effective context engineering — Anthropic (Sep/Oct 2025) · <https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents>
- Effective harnesses for long-running agents — Anthropic (26 Nov 2025) · <https://www.anthropic.com/engineering/effective-harnesses-for-long-running-agents>

**Termination & control**
- LangGraph overview · <https://docs.langchain.com/oss/python/langgraph/overview>
- The lethal trifecta — Simon Willison (16 Jun 2025) · <https://simonwillison.net/2025/Jun/16/the-lethal-trifecta/>
- Prompt injection series — Simon Willison (since Sep 2022) · <https://simonwillison.net/series/prompt-injection/>
- OWASP Top 10 for LLMs 2025 (PDF) · <https://owasp.org/www-project-top-10-for-large-language-model-applications/assets/PDF/OWASP-Top-10-for-LLMs-v2025.pdf>

**Code-specific systems**
- SWE-bench — arXiv:2310.06770 (Oct 2023) · <https://arxiv.org/abs/2310.06770>
- SWE-agent — arXiv:2405.15793 (May 2024) · <https://arxiv.org/abs/2405.15793>
- OpenHands — arXiv:2407.16741 (Jul 2024) · <https://arxiv.org/abs/2407.16741>
- AutoGPT — Wikipedia / GitHub (Mar 2023) · <https://en.wikipedia.org/wiki/AutoGPT>
- Introducing Devin — Cognition (Mar 2024) · <https://cognition.com/blog/introducing-devin>

---

*Note on dates: arXiv dates are first-submission (v1) unless a venue camera-ready is noted. Where a source is an engineering blog without a visible date, the date reflects the best available attribution. Two minor caveats are flagged inline: (1) the OWASP genai.owasp.org slug for LLM06 currently mismatches its title due to 2025 list reordering — cite the stable PDF; (2) the "Anatomy of Agentic Memory" survey's taxonomy is around four memory *structures*, distinct from CoALA's four memory *types* — use CoALA for the episodic-vs-procedural conceptual distinction.*
