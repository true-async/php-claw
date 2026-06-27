<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Tool\Registry;

/**
 * The default workflow `claw run <id>` starts for an issue. It does NOT solve the issue
 * itself: its job is to WRITE another workflow — a solver tailored to this one issue —
 * and save it as the project's procedural memory through the same `define_workflow` door.
 * That generated solver is what actually does the work, on a later (human-approved) run.
 *
 * This is the roadmap's "mutation" (a workflow that creates a workflow): understand the
 * issue, draft a {@see WorkflowAbstract} subclass that solves it, then validate-and-save
 * it. The class name and namespace it must produce are run params, so the caller knows
 * exactly which class to load and run afterwards.
 */
final class GenerateIssueWorkflow extends WorkflowAbstract
{
    /**
     * The canonical issue-solver recipe — the phases every generated solver must carry out.
     * This is the single source of truth for the structure; {@see draftPrompt()} hands it to
     * the model. Where a phase needs runtime the framework does not have yet (a mid-run human
     * pause, parallel sub-workflows, automated CI/merge), the solver does the best the bash
     * tool allows; the phase ordering still shapes the generated code.
     */
    private const string RECIPE = <<<'RECIPE'
        1. Validate — read the issue and the relevant code; decide whether it is real and complete.
           Bug: reproduce it FIRST — add a failing test that captures it before any fix.
           Feature: build the map of use-cases and check it is complete, error cases included.
           If the issue is incomplete, do NOT invent scope — ASK the human for the missing cases
           with $this->ask('...'). Pin explicit acceptance criteria (the done-conditions step 5 verifies).
        2. Design — decide how to solve it and which classes/changes are needed; map the
           components, reuse what already exists, check how the change fits.
        3. Review the design against SOLID — responsibilities and dependency direction; keep any
           violation minimal and deliberate. If the decision is foundational AND non-obvious (an
           early or immature project, or it sets the base), get human approval of the design with
           $this->ask('...') before implementing; if it is obvious in a mature codebase, proceed.
        4. Implement — make the change by having the model edit the files with the tools and VERIFY
           each edit with `php -l` in the same ai() exchange; never hand-write the new source as a
           string. The model reads, writes, lints, and fixes itself before the step returns.
        5. Test & accept — add a test for EACH acceptance criterion from step 1; run the full
           quality gate via the bash tool (e.g. `composer qa`) and make it green; watch for
           regressions. If a review finding or a test fails, loop back to step 4 (or 2) until green.
           Gate this step with a critic whose rules demand the proof (lint clean, gate green).
        6. Changelog — record what changed (docs / changelog).
        7. Deliver — commit on a branch and open it for review (bash tool: git).
        RECIPE;

    private string $plan = '';
    private string $code = '';

    /** The worker tier assess() decided this task warrants — folded into the draft's step routing. */
    private string $workerTier = 'worker';
    private string $difficulty = 'moderate';

    public function name(): string
    {
        return 'generate-issue-workflow';
    }

    #[Step]
    protected function understand(): void
    {
        $this->plan = $this->ai(
            'You are planning how to solve a task by writing a workflow. Inspect the project if it '
            . 'helps (read_file, list_files), then, in a few concrete sentences: outline the steps a '
            . 'workflow should take to solve this task, AND assess whether the project is mature with '
            . 'an established architecture and whether the change is foundational — this decides '
            . "whether a human must approve the design before it is implemented:\n\n" . $this->taskSummary(),
            ['read_file', 'list_files'],
            'worker-smart',   // planning a whole workflow is heavy thinking — use the strong tier
        );
    }

    /**
     * Judge how hard the task is and pick the model tier the GENERATED solver should run its steps
     * on: a trivial fix wastes money on the strong model, a subtle change needs it. The verdict
     * ('worker' vs 'worker-smart') is folded into the draft, so the solver routes its `ai()` calls to
     * the chosen tier. Kept as its own step so the decision — and its reasoning — is visible in the trace.
     */
    #[Step]
    protected function assess(): void
    {
        $verdict = $this->ai(
            'Rate how hard this coding task is for an AI to solve correctly. Reply with EXACTLY one '
            . 'word on the first line — `simple`, `moderate`, or `complex` — then one sentence of '
            . 'reasoning. Simple = a localized, mechanical change; complex = subtle logic, wide blast '
            . "radius, or design judgement.\n\nTask:\n{$this->taskSummary()}\n\nPlan:\n{$this->plan}",
            [],
            'supervisor-smart',
        );

        // Classify on the FIRST WORD only — the one-word verdict — not the whole reply: the reasoning
        // sentence routinely names the other tiers ("not a simple change"), which would misclassify.
        $word = strtolower((string) strtok(trim($verdict), " \t\r\n"));
        $this->difficulty = match (true) {
            str_contains($word, 'complex') => 'complex',
            str_contains($word, 'simple') => 'simple',
            default => 'moderate',
        };

        // A simple task runs cheap; anything with real judgement gets the strong tier.
        $this->workerTier = $this->difficulty === 'simple' ? 'worker' : 'worker-smart';
    }

    /**
     * Write the solver, then have it reviewed by the `solverReview` critic — "will it actually solve the
     * task", not "is it valid PHP" (the validator covers that). The critic gates the step, so a rejected
     * draft RE-RUNS here (continuing this conversation, see {@see WorkflowAbstract::ai()}) and is re-judged
     * — the worker's fix can't slip through unreviewed, which is how a bad draft used to escape.
     */
    #[Step(critic: 'solverReview')]
    protected function draft(): string
    {
        // [] = the model returns the class CODE, it does not act with tools
        $this->code = $this->extractCode($this->ai($this->draftPrompt(), [], 'worker-smart'));

        // The generated class IS this step's output — record it as the artifact the critic judges. (A
        // codegen step produces no run artifacts, and that is correct; the artifact is the source itself.)
        $this->artifact('solver-class', $this->code);

        return $this->code;   // a rejection re-runs draft with the findings
    }

    #[Step]
    protected function save(): void
    {
        $this->code = $this->saveGeneratedWorkflow(
            (string) $this->param('solverName'),
            $this->code,
            fn (string $rejection): string => $this->reviseCode("The class you wrote was rejected: {$rejection}"),
        );
    }

    /**
     * The rubric the `solverReview` critic judges the {@see draft()} against: will the generated solver
     * ACTUALLY solve the task. Spelled out in full because the reviewer is judged only against this text.
     *
     * @return array<string, string>
     */
    protected function criticRules(): array
    {
        return [
            'solverReview' => "You are reviewing the GENERATED SOURCE of a solver class (the step's artifact). "
                . 'This code is NOT executed now — it RUNS LATER against the project. So do NOT reject it for '
                . "'no artifacts were produced' or 'the tests were not run': there are no run artifacts at "
                . 'generation time, and that is correct — the artifact under review IS the source itself. '
                . 'Judge ONLY whether this class, when run, will ACTUALLY solve the task below (not whether it '
                . 'is valid PHP — the validator covers that). A step body that calls $this->ai("…", [...]) or '
                . '$this->tool(…) IS real work — the model does the work inside that call, so such a step is '
                . "NOT a placeholder; 'placeholder' means ONLY a bare literal return (e.g. return 'TODO';) with "
                . 'no $this->ai()/$this->tool() call at all. REJECT only if: a step is a true placeholder (no '
                . "ai()/tool() call); a `#[Step(critic: '<name>')]` has no matching entry in criticRules(); "
                . "`done`/\$this->tool('done') is called before the deliverable is built (`done` = the whole "
                . 'task is solved, not that a step finished); or the recipe is plainly not carried out. '
                . 'Otherwise reply exactly: OK.'
                . "\n\nThe task:\n{$this->taskSummary()}",
        ];
    }

    /** The issue's title and description as a compact task brief — shared by the planning steps. */
    private function taskSummary(): string
    {
        $issue = $this->issue();

        return $issue !== null
            ? "Title: {$issue->title}\n\nDescription: {$issue->description}"
            : 'No issue was attached to this run.';
    }

    private function draftPrompt(): string
    {
        // A re-run after the critic rejected the draft: the model still holds its previous attempt in the
        // continued conversation, so don't re-state the whole brief — just hand it the findings to fix.
        $critique = $this->critique();

        if ($critique !== null) {
            return "A reviewer REJECTED the workflow you just wrote:\n\n{$critique}\n\n"
                . 'Rewrite the FULL class fixing exactly those problems, keeping the rest. Reply with only the PHP code.';
        }

        $namespace = (string) $this->param('solverNamespace');
        $class = (string) $this->param('solverName');
        $toolDocs = $this->availableTools();
        $recipe = self::RECIPE;

        return <<<PROMPT
            Write a PHP class that solves the task below by extending Claw\\Workflow\\WorkflowAbstract,
            following the standard solver recipe.

            Plan:
            {$this->plan}

            Recipe — the phases the solver must carry out, each as one or more #[Step] methods (use
            plain if/while in run() where a phase loops or branches):
            {$recipe}

            HOW A STEP ACTUALLY DOES WORK — read this twice, it is the part solvers get wrong:
            - A step does NOT do the work itself in PHP. You are writing the PLAN; the WORK is done by
              a model you drive with `\$this->ai(...)`. Inside that call the model has exactly two moves:
              call a TOOL, or `ask` a human. That is the whole vocabulary. Your step's job is to set up
              the prompt and let the model act.
            - NEVER build the change as a PHP string and write it yourself (no `\$code = "..."; \$this->tool('write_file', ...)`,
              no str_replace/preg_replace surgery on source). That is blind: it cannot see or fix its own
              mistakes, and it is exactly how solvers corrupt files. To change a file, tell the model to
              do it: `\$this->ai('Read src/X.php and add method Y; then run `php -l` on it and fix any
              error before you stop.')` — the model reads, edits, and VERIFIES with tools in ONE exchange,
              seeing the verifier's output and correcting itself inside the same `ai()` call.
            - Verification belongs INSIDE that exchange (the model runs `php -l` / the test gate via the
              bash tool and reacts), because a tool result is only visible to the model while the `ai()`
              call is still running. A separate later step that runs `php -l` and just RETURNS the error
              is useless — once a step returns, no model sees that string. The only thing that re-runs a
              step on a bad result is a CRITIC (below). So: either the model verifies-and-fixes within
              its own `ai()` exchange, or you gate the step with a critic — never a bare "verify" step.

            Hard requirements (the code is validated before it is saved, and rejected if any are missed):
            - the file must begin with the opening tag `<?php` followed by `declare(strict_types=1);`
            - namespace {$namespace};
            - `use Claw\\Workflow\\Step;` and `use Claw\\Workflow\\WorkflowAbstract;`
            - `final class {$class} extends WorkflowAbstract`
            - implement `public function name(): string`
            - keep state in plain typed properties
            - write each step as a `protected` method marked `#[Step]` (NOT public, NOT private — the base run() drives them and the code is rejected otherwise); the default run() runs them in declaration order
            - GRANULARITY — SCALE the number of steps to the task's difficulty (assessed as **{$this->difficulty}**). A SIMPLE/trivial task needs only 1–3 steps — and a SINGLE step (implement-and-verify in one) is perfectly fine for it; do NOT force the full phase-by-phase recipe onto a simple task; the extra steps cost more and add failure surface for no benefit. A MODERATE task wants a handful of focused steps. Reserve the full breakdown (design / review / implement-per-method / test / deliver) for genuinely COMPLEX work. The reason to split AT ALL: each step's `ai()` starts with a fresh, lean context (one fat step re-sends a huge growing history — expensive), and a small step is a unit a critic can check. BUT every step must be a COHERENT unit of REAL work that produces something validatable (an artifact, a passing gate) — never a step that just asks a question or restates a plan. When in doubt, FEWER steps: prefer the smallest decomposition that still lets each piece be verified. Not one giant step, and not a parade of ceremonial ones.
            - a step's OUTPUT goes into one of TWO channels — NEVER its return value (the engine ignores what a step returns): (a) `\$this->artifact('<label>', text|file: ...)` — the GLOBAL channel, visible to every later step (via recall) AND to this step's critic; (b) the handoff — the automatic baton to the NEXT step only (below). Do not stash results in fields to pass them on; use these channels.
            - PARAMS — a THIRD, optional channel for passing a CONCRETE VALUE (not content) to a SPECIFIC later step to use IN CODE: `\$this->setParam('<forStep>', '<name>', \$value)` pins it FOR a named step (the target step's METHOD name), and THAT step reads it with `\$this->param('<name>')`. It is ADDRESSED: only the named step sees it — other steps cannot read it (the target step's own critic does). Use it when a step DECIDES an exact value the code of a particular later step needs deterministically — a file path, a count, an id, a strategy flag — rather than prose for the model. DURABLE (survives a resume) and entirely OPTIONAL: most steps pass nothing this way. (artifact = content visible to everyone + the critic; handoff = a prose note for the next model; param = a concrete value addressed to ONE named step's code.)
            - to have a step reviewed automatically, mark it `#[Step(critic: '<name>')]` and have it RECORD its output as an artifact — the critic judges that artifact, not any return value — and fold `\$this->critique()` (the reviewer's guidance, null on the first run) into your prompt so a re-run fixes the findings — fitting for the SOLID-review (step 3) and test&accept (step 5) steps
            - record what a step produced with `\$this->artifact('<label>', text: '<summary>')` or `\$this->artifact('<label>', file: '<path it wrote>')`. Artifacts show in the run log and give the critic something concrete. A critic does NOT strictly require an artifact, though — it is a real reviewer AI with every tool and can verify by reading the files the step changed and running `php -l`/the tests
            - RECONCILE every step with its critic. Put a `#[Step(critic: ...)]` on a step ONLY when that step yields a JUDGEABLE RESULT the rubric can check — an artifact it recorded, or a real change the critic can inspect (a file written, tests run). Do NOT put a critic on a step that merely asks a question, restates a plan, or otherwise produces nothing to review — that is the mismatch that wedges a run. If a step needs no review, DON'T give it a critic; if it has one, make sure the step actually leaves a result. And for every critic name you use, you MUST define its rules in `criticRules()`
            - the critic is a REAL reviewer AI with every tool: it will OPEN the file artifacts, run `php -l`/the tests itself, and judge — it does not just read your summary. So make the work actually correct; a confident summary over a broken file will be caught
            - the critic name is just a key: for EVERY name you use you MUST define its actual rules by overriding `protected function criticRules(): array`, returning `['<name>' => '<the concrete criteria the reviewer checks>', ...]` — the reviewer is judged ONLY against this text, so spell the criteria out in full; a name with no rules makes the run fail
            - a critic step re-runs until it passes; after a SMALL soft cap of rework rounds (default 2) the run escalates to a supervisor — a critic is meant to bounce a step once or twice, not churn dozens of rounds. Tune it per step with `#[Step(critic: '<name>', maxRounds: <n>)]` — raise it only for a step that legitimately churns (a test gate that iterates), keep it low elsewhere
            - reach the model with `\$this->ai(string \$prompt, ?array \$tools = null, ?string \$agent = null)` and tools with `\$this->tool(string \$name, array \$params)`
            - by DEFAULT `\$this->ai(\$prompt)` exposes EVERY tool to the model — that is the norm, let a step use whatever it needs; pass an explicit list ONLY to deliberately restrict, or `[]` for a pure-reasoning call that must not act
            - you may give THIS workflow its own bespoke tools: mark a method `#[\\Claw\\Workflow\\Tool(description: '<what it does>')]` and it becomes a tool the model can call inside your `ai()` steps (named after the method in snake_case; its typed params become the input schema). Use this to let the model check and FIX its own work — e.g. a `validate()` tool that runs `php -l` / the test gate via `\$this->tool('bash', ...)` and returns OK or the error, so a step can call it, see the failure, and correct itself in the same exchange instead of failing the run
            - route a step to a specialized agent role via the 3rd arg, e.g. `\$this->ai(\$p, null, agent: 'reviewer')`; roles: worker (cheap default), worker-smart (stronger model), reviewer (SOLID/code review), supervisor (unblock/escalate), planner (validate/design)
            - this task was assessed as **{$this->difficulty}**; route the solver's own implementation/test steps that call the model to `agent: '{$this->workerTier}'` so the work runs on the right-sized model (keep reviewer/supervisor steps on their roles)
            - when you NEED a missing detail or a decision from a person (an incomplete issue, a foundational design choice), do NOT guess: call `\$this->ask(string \$question): string` and use the returned answer — behind it may be a human or a supervisor agent
            - the run is budget-limited (tokens and time); work in focused steps and do not loop or re-read pointlessly, an exhausted budget stops the run
            - the ONLY way to touch files or the shell is through `\$this->tool(\$name, \$params)`; use EXACTLY these tool names and input keys (do not invent keys):
            {$toolDocs}
            - file paths are relative to the project root, EXACTLY as list_files shows them (e.g. 'src/Calculator.php', NOT 'Calculator.php'); when unsure of a path, call list_files at run time inside a step rather than hardcoding a guess
            - `\$this->tool(...)` returns the tool's raw output as a STRING and `\$this->ai(...)` returns the model's text as a STRING — never index them like arrays (no `\$result['content']`); parse the string if you need to
            - a tool error does NOT throw — `\$this->tool(...)` returns the failure as a string starting `tool '<name>' failed: ...`; check for that and recover (e.g. a wrong path: call list_files and retry) or fold the message into the next `\$this->ai(...)` so the model fixes it, rather than blindly using a failed result
            - `done` ENDS THE WHOLE RUN, it does NOT mark a step complete. A step completes by RETURNING; calling `done` stops the entire workflow on the spot and skips every remaining step. So `done` means "the task's actual deliverable now exists and has been verified" — NEVER "this phase is finished". NEVER put `\$this->tool('done', ...)` in an early step (validate/design/assess) or in a PHP branch: that aborts the run before the implement step ever creates anything (e.g. a validate that calls `done` when "no conflict" finishes having done NOTHING). The only legitimate `done` is the model deciding, INSIDE an `ai()` exchange AFTER it has produced and verified the change, that the task is fully solved and the rest is redundant — so offer it by listing `done` among that step's tools, do not hardcode it. When unsure, just `return` and let the next step run.
            - each step's `ai()` starts fresh — it does NOT see earlier steps, and the engine carries NOTHING between steps automatically. YOU decide, per step, what prior context it needs and have it pulled in. The door is the `recall` tool the step's model can call: `recall(what='task')` re-reads the issue brief, `what='workflow'` lists the steps so far, `what='step', name='design'` returns a sibling step's history, `what='artifacts', name='design'` its artifacts, `what='tool', name='bash'` a tool's calls. So when a step builds on an earlier one, say so in its prompt (e.g. "first call recall(what='artifacts', name='implement') to see what was changed, then ...") — do not assume the earlier work is visible, and do not re-derive what a prior step already produced
            - THE BATON IS AUTOMATIC: after each step the engine EXPLICITLY asks the model — continuing that step's OWN ai() conversation — to form a handoff (a summary of what the step did + the findings the next step must watch for), and feeds it into the next step's context as "the previous step handed this to you". You do NOT write handoff code, and it is NOT your return value — it is formed from the step's ai() work. So make each step do its work through `\$this->ai(...)`/tools and record key outputs as artifacts; the next step can rely on the incoming handoff being present (and can pull any earlier artifact with recall)
            - NEVER call PHP builtins such as file_get_contents, fopen, exec, shell_exec, system, eval, include/require, or a dynamic `\$var(...)` call — they are forbidden and the code will be rejected

            Return ONLY the PHP source — no prose, no markdown fences.
            PROMPT;
    }

    /**
     * The solver's tools, each with its REAL input schema pulled from the registry — so the model
     * uses the exact param keys (e.g. read_file needs `path`, not `file`) instead of guessing.
     */
    private function availableTools(): string
    {
        $tools = $this->param('solverTools');
        $names = \is_array($tools) ? array_map(strval(...), $tools) : ['read_file', 'write_file', 'list_files', 'bash'];

        $registry = $this->find(EnvKey::Registry);

        if (!$registry instanceof Registry) {
            return implode(', ', $names);
        }

        $docs = [];

        foreach ($names as $name) {
            if (!$registry->has($name)) {
                continue;
            }

            $tool = $registry->get($name);
            $schema = json_encode($tool->inputSchema(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $docs[] = "  - {$tool->name()}: {$tool->description()}\n    input: {$schema}";
        }

        return $docs === [] ? implode(', ', $names) : implode("\n", $docs);
    }

    /**
     * One strong-tier revision pass: fold the rejection reason back into the original draft
     * constraints and re-extract the corrected source. Shared by {@see review()} and {@see save()}.
     */
    private function reviseCode(string $reason): string
    {
        return $this->extractCode($this->ai(
            "{$reason}\n\n"
            . "Return ONLY the corrected PHP source. The constraints are unchanged:\n\n"
            . $this->draftPrompt() . "\n\nThe code you produced was:\n\n" . $this->code,
            [],
            'worker-smart',
        ));
    }
}
