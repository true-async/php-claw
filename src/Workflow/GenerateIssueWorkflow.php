<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Exceptions\WorkflowException;
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
     * The MODEL of how a workflow works + the PRINCIPLE for choosing steps — NOT a fixed list of phases.
     * A step is a unit of focused attention (a fresh context); the generator decides how many steps the
     * task needs and uses the fewest (often one). {@see draftPrompt()} hands this to the model. This
     * replaced a prescriptive 7-phase recipe, which made the model stamp out a ceremonial step per phase
     * (validate/design/…) even for a one-file change — the dominant source of wasted steps and cost.
     */
    private const string RECIPE = <<<'RECIPE'
        WHAT A WORKFLOW IS. A workflow is a SCRIPT that drives several AGENT WORK CYCLES. Each step
        runs one full agent sitting — a model with tools that reads, writes files, runs commands until
        that cycle's job is done. A workflow is NOT the algorithm of the solution written as phases:
        never split by what the CODE will do (parse the input / sort the numbers / print the result —
        that is the agent's business inside one sitting). Split by CYCLES OF DEVELOPMENT, the way a
        person works: write the code; write the tests; verify the whole. If your steps read like a
        description of the solution instead of a description of the WORK, you are splitting wrong.

        HOW TO DECIDE THE STEPS — read this carefully; it is the part solvers get wrong.

        WHAT A STEP IS FOR. A step is NOT a ritual phase. It is a unit of FOCUSED ATTENTION: each step's
        model call starts with a FRESH context and does NOT carry the whole prior history (which would
        bloat and rot). So you split the work into separate steps ONLY when giving a part its own fresh
        context — or its own critic — actually buys something. Between steps you carry only what matters:
          - artifact — a named result, visible to every later step and to a critic;
          - handoff — a short note to the VERY NEXT step;
          - param — a concrete value a later step reads in CODE.
        A step may carry a critic (a review sub-step) that judges its result. A critic can execute
        ONLY the commands the step itself recorded as evidence (the artifact tool's `command`
        channel) — it cannot compose its own; so a step whose rubric turns on a command's outcome
        (tests, lint) MUST record that run as evidence, or its critic cannot verify it. Critic
        discipline, by cycle: a WRITE-CODE cycle's critic READS the code and judges it as code — it
        never runs the tests (they may not exist yet, and a failed run there proves nothing). Running
        and judging the tests belongs to the FINAL verify cycle — one critic at the end proves the
        whole green. Use a critic ONLY where a result genuinely needs that independent check, never
        on every step.

        THE PRINCIPLE — the FEWEST steps, no wasted motion:
          1. First decide whether the task needs splitting AT ALL. Most small tasks do NOT: a simple task
             is ONE step that makes the change and verifies it (read what's needed, write the code, run
             `php -l` and the tests, fix until green). One step is the DEFAULT, not a shortcoming.
          2. Add a further step ONLY when a part is distinct enough that its own fresh context or its own
             critic earns the cost — e.g. a real design decision before a large change, or a test gate that
             must be proven green. When in doubt: fewer steps.
          3. The concerns to COVER (this is NOT a list of steps): understand what's asked, make the change,
             prove it works (lint/tests green), record/deliver if it matters. On a simple task ALL of these
             live in the single implement-and-verify step — do not spread them into separate steps.
          4. Every step must do REAL work and leave a result. No ceremonial steps (a "validate" that only
             restates the task, a "design" that only says "implement the methods"), and no step doing
             another step's job (a design step must NEVER write the code — that is implement's job).
          5. A step is NOT free. Its own fresh context plus the handoff it forms cost on the order of
             ~3000 tokens BEFORE it does any real work (a critic adds several thousand more). So split a
             part into its own step ONLY if that part is worth at least ~3000 tokens of real work; if it
             is smaller, FOLD it into a neighbouring step — the boundary would cost more than the work it
             isolates. This is the concrete test for "is this step worth it".
        RECIPE;

    /** One bounded repair: on a validator reject at save, back() re-drafts once, then a second failure throws. */
    private bool $repairAttempted = false;

    public function name(): string
    {
        return 'generate-issue-workflow';
    }

    /**
     * A pure {@see StepAI}: it DECLARES the planning exchange, the base runs it. The plan reaches
     * {@see draft()} as an addressed `plan` param (and {@see assess()}, the next step, through the handoff)
     * — the declarative model carries nothing in fields, so what a later step needs is passed on a channel.
     */
    #[StepAI]
    protected function understand(): AiStep
    {
        return new AiStep(
            'You are planning how to solve a task by writing a workflow. Inspect the project if it '
            . 'helps (read_file, list_files), then, in a few concrete sentences: outline the steps a '
            . 'workflow should take to solve this task, AND assess whether the project is mature with '
            . 'an established architecture and whether the change is foundational — this decides '
            . "whether a human must approve the design before it is implemented:\n\n" . $this->taskSummary(),
            ['read_file', 'list_files'],
            'worker-smart',   // planning a whole workflow is heavy thinking — use the strong tier
            params: [new ParamRequest(
                forStep: 'draft',
                name: 'plan',
                instruction: 'Restate your plan as a few concrete sentences: the steps a workflow should take '
                    . 'to solve this task, and whether a human must approve the design before it is implemented.',
            )],
        );
    }

    /**
     * Judge how hard the task is; the one-word verdict reaches {@see draft()} as an addressed `difficulty`
     * param, which routes both the drafting tier and the tier the GENERATED solver runs its own steps on —
     * a trivial fix wastes money on the strong model, a subtle change needs it. Its own step so the decision
     * and its reasoning stay visible in the trace; the plan it judges against arrives through the handoff.
     *
     * The verdict is a param, not a parsed first line: extraction asks for exactly the bare word, so the
     * reasoning sentence can never be mistaken for it. {@see draftPrompt()} maps anything unreadable to
     * `moderate` — the middle, which cannot be wrong in an expensive direction.
     */
    #[StepAI]
    protected function assess(): AiStep
    {
        return new AiStep(
            'Rate how hard this coding task is for an AI to solve correctly — simple, moderate or complex — '
            . 'and give one sentence of reasoning. Simple = a localized, mechanical change; complex = subtle '
            . "logic, wide blast radius, or design judgement.\n\nTask:\n{$this->taskSummary()}",
            [],
            'supervisor-smart',
            params: [new ParamRequest(
                forStep: 'draft',
                name: 'difficulty',
                instruction: 'One bare word and nothing else — simple, moderate or complex. '
                    . 'No label, no punctuation, no backticks.',
            )],
        );
    }

    /**
     * Declare the drafting exchange under the `solverReview` critic — "will it actually solve the task",
     * not "is it valid PHP" (the validator covers that). A pure {@see StepAI} cannot record an artifact
     * itself, so the model RECORDS the solver source by calling the `artifact` tool; the critic judges that
     * artifact, and the source reaches {@see save()} as an addressed `code` param. On a critic reject the
     * base re-runs the exchange on the supervisor's guidance, so a bad draft cannot slip through unreviewed.
     */
    #[StepAI(critic: 'solverReview')]
    protected function draft(): AiStep
    {
        return new AiStep(
            $this->draftPrompt(),
            ['artifact'],   // the only move the drafter needs: record the source it writes
            'worker-smart',
            params: [new ParamRequest(
                forStep: 'save',
                name: 'code',
                instruction: 'Output the complete PHP source of the solver class you recorded with the '
                    . 'artifact tool — exactly and nothing else, no prose and no markdown fences.',
            )],
        );
    }

    /**
     * Save the drafted solver through `define_workflow` (which validates, then stores it). On a validator
     * reject the source is re-drafted ONCE: back() into {@see draft()} hands it the complaint via critique()
     * and it rewrites; a second reject surfaces to the run path. A CODE step, so it never calls the model
     * itself — the repair is a real re-draft, not a hidden ai() call inside save.
     */
    #[Step]
    protected function save(): void
    {
        $code = $this->extractCode((string) $this->param('code'));
        $result = $this->tool('define_workflow', [
            'name' => (string) $this->param('solverName'),
            'code' => $code,
            'shared' => true,
        ]);

        if (str_contains($result, self::WORKFLOW_SAVED_MARKER)) {
            return;
        }

        if ($this->repairAttempted) {
            throw new WorkflowException($result);   // a second failure surfaces to the run path
        }

        $this->repairAttempted = true;
        $this->back('draft', "The generated class was rejected when it was saved: {$result}\n\n"
            . 'Rewrite the FULL class fixing exactly that, and record it again with the artifact tool.');
    }

    /**
     * The rubric the `solverReview` critic judges the {@see draft()} against: will the generated solver
     * ACTUALLY solve the task. Spelled out in full because the reviewer is judged only against this text.
     *
     * @return array<string, string>
     */
    protected function criticRules(): array
    {
        $recipe = self::RECIPE;
        $plan = (string) $this->param('plan');

        return [
            'solverReview' => "You are reviewing the GENERATED SOURCE of a solver class (the step's artifact). "
                . 'This code is NOT executed now — it RUNS LATER against the project. So do NOT reject it for '
                . "'no artifacts were produced' or 'the tests were not run': there are no run artifacts at "
                . 'generation time, and that is correct — the artifact under review IS the source itself. The '
                . 'project as it stands is not evidence about work that was never supposed to have happened '
                . "yet, so a red test suite is not this class's fault.\n\n"
                . 'The ONE question: when this class runs, will it ACTUALLY solve the task below? Not whether '
                . 'it is valid PHP, and not whether it is written the way you would have written it — the '
                . "validator covers the first and the second is not your call.\n\n"
                . "What is NOT a defect, because reviewers keep calling it one:\n"
                . '- A step body that calls $this->ai("…") or $this->tool(…) IS real work. The model does the '
                . "work inside that call. A `placeholder` means a bare literal return with no such call.\n"
                . "- Few steps. One step that implements and verifies is the intended shape for a small task.\n"
                . "- Boilerplate: namespace, strict_types, the class declaration. Rejected at save if wrong.\n\n"
                . "REJECT when any of these hold — and say which:\n"
                . '- a step is too SIMPLE to earn its own existence. This is a judgement, not a count: read '
                . 'each step and ask "if it does this little, is it a step at all, or ceremony?". A step '
                . 'whose prompt carves out only a sliver — a lookup, a restatement, one trivial action a '
                . 'neighbour would absorb without noticing — should be FOLDED into that neighbour. A step '
                . 'must justify its own fresh context; a workflow that spends one on a triviality is badly '
                . "split. (Having FEW steps is not the fault — a lone meaty step is ideal; a thin step is);\n"
                . "- a step is a true placeholder: no ai()/tool() call at all;\n"
                . '- the class builds the change as a PHP string and writes it (str_replace/preg_replace '
                . 'surgery on source, a heredoc of the new file). It cannot see or fix its own mistakes '
                . "that way, and it is how solvers corrupt files — the work must go through the model;\n"
                . "- a step's prompt is too vague to act on: it restates the task, or names no file, no "
                . "command and no criterion, so whatever the model does cannot be wrong;\n"
                . '- the class does not address the task: it works on the wrong file or component, or solves '
                . "something adjacent to what was asked;\n"
                . '- a step whose result a critic must judge does not record it — no artifact, and for a '
                . "claim a command settles, no evidence;\n"
                . "- the plan below describes work this class simply does not do.\n\n"
                . "Here is what the author was working from, so you judge against the same thing.\n\n"
                . "The task:\n{$this->taskSummary()}\n\n"
                . "The plan:\n{$plan}\n\n"
                . "The rules it was told to follow when choosing steps:\n{$recipe}",
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
                . 'Rewrite the FULL class fixing exactly those problems, keeping the rest, and record the '
                . 'corrected source again with the artifact tool.';
        }

        // The plan and the difficulty arrive as addressed params (understand() and assess() set them);
        // an unreadable difficulty maps to `moderate` — the middle, which cannot be wrong in an expensive
        // direction — so the drafter and the generated solver are always routed to a real tier.
        $plan = (string) $this->param('plan');
        $difficulty = strtolower(trim((string) $this->param('difficulty')));
        $difficulty = \in_array($difficulty, ['simple', 'complex'], true) ? $difficulty : 'moderate';
        $workerTier = $difficulty === 'simple' ? 'worker' : 'worker-smart';

        $namespace = (string) $this->param('solverNamespace');
        $class = (string) $this->param('solverName');
        $toolDocs = $this->availableTools();
        $recipe = self::RECIPE;

        // The TICKET ITSELF, verbatim. It used to be absent: this prompt said "solves the task below"
        // and the task was not below — the only thing describing it was $this->plan, understand()'s
        // paraphrase. Every detail the plan happened to drop (an exact error message, a file name, an
        // acceptance criterion) was unrecoverable here, and the solver's step prompts baked in a
        // paraphrase of a paraphrase. Worse, the critic judging this draft DOES get the real ticket
        // (see criticRules()), so the reviewer was better informed than the author — a reliable way to
        // manufacture rejection rounds neither party could resolve.
        $task = $this->taskSummary();

        // The DOMAIN half, when the ProjectManager chose an `approach` off the shelf. The constant recipe
        // above says what a step is and how few to use — mechanics, the same for every ticket. This says
        // what work of THIS KIND has to accomplish and where its branches are. Keeping them apart is the
        // point: general machinery does not belong in a per-type text, and a per-type procedure written
        // into the machinery is how the old fixed seven-phase recipe came to stamp ceremony onto
        // one-file changes.
        $chosen = trim((string) $this->param('recipe'));
        $approach = $chosen === '' ? '' : <<<APPROACH


            THE APPROACH TO FOLLOW — a written strategy a person put on the shelf for work of this kind,
            chosen for this ticket. It says WHAT has to be achieved and where the decisions are; you
            still decide how many steps that costs, by the rules above.
            {$chosen}
            APPROACH;

        return <<<PROMPT
            Write a PHP class that solves the task below by extending Claw\\Workflow\\WorkflowAbstract.
            You DECIDE how many steps it needs — use the FEWEST the task actually requires (often just one).

            THE TASK — this is the ticket the solver you write must solve. The plan below is one reading
            of it; where they differ, this is what was actually asked for:
            {$task}

            Plan:
            {$plan}

            How to decide the steps — this is the MODEL of how a workflow works and the principle for
            choosing steps, NOT a list of steps to stamp out (a step is one or more #[Step] methods; use
            plain if/while in run() where the flow loops or branches):
            {$recipe}
            {$approach}

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

            Hard requirements. Most are checked mechanically when the class is saved and cost you a
            rejection round if missed — the opening tag and `declare(strict_types=1)`, the namespace and
            class name, `extends WorkflowAbstract`, at least one `#[Step]`, that steps are `protected`,
            that every critic name has rules, and the forbidden builtins. The rest are not checked by
            anything, which makes them the ones to read twice:
            - the file must begin with the opening tag `<?php` followed by `declare(strict_types=1);`
            - namespace {$namespace};
            - `use Claw\\Workflow\\Step;` and `use Claw\\Workflow\\WorkflowAbstract;`
            - `final class {$class} extends WorkflowAbstract`
            - implement `public function name(): string`
            - keep state in plain typed properties
            - write each step as a `protected` method marked `#[Step]` (NOT public, NOT private — the base run() drives them and the code is rejected otherwise); the default run() runs them in declaration order
            - GRANULARITY — SCALE the number of steps to the task's difficulty (assessed as **{$difficulty}**). A SIMPLE/trivial task needs only 1–3 steps — and a SINGLE step (implement-and-verify in one) is perfectly fine for it; do NOT force the full phase-by-phase recipe onto a simple task; the extra steps cost more and add failure surface for no benefit. A MODERATE task wants a handful of focused steps. Reserve the full breakdown (design / review / implement-per-method / test / deliver) for genuinely COMPLEX work. The reason to split AT ALL: each step's `ai()` starts with a fresh, lean context (one fat step re-sends a huge growing history — expensive), and a small step is a unit a critic can check. BUT every step must be a COHERENT unit of REAL work that produces something validatable (an artifact, a passing gate) — never a step that just asks a question or restates a plan. When in doubt, FEWER steps: prefer the smallest decomposition that still lets each piece be verified. Not one giant step, and not a parade of ceremonial ones.
            - a step's OUTPUT goes into one of TWO channels — NEVER its return value (the engine ignores what a step returns): (a) `\$this->artifact('<label>', text|file: ...)` — the GLOBAL channel, visible to every later step (via recall) AND to this step's critic; (b) the handoff — the automatic baton to the NEXT step only (below). Do not stash results in fields to pass them on; use these channels.
            - PARAMS — a THIRD, optional channel for passing a CONCRETE VALUE (not content) to a SPECIFIC later step to use IN CODE: `\$this->setParam('<forStep>', '<name>', \$value)` pins it FOR a named step (the target step's METHOD name), and THAT step reads it with `\$this->param('<name>')`. It is ADDRESSED: only the named step sees it — other steps cannot read it (the target step's own critic does). Use it when a step DECIDES an exact value the code of a particular later step needs deterministically — a file path, a count, an id, a strategy flag — rather than prose for the model. DURABLE (survives a resume) and entirely OPTIONAL: most steps pass nothing this way. (artifact = content visible to everyone + the critic; handoff = a prose note for the next model; param = a concrete value addressed to ONE named step's code.)
            - to have a step reviewed automatically, mark it `#[Step(critic: '<name>')]` and have it RECORD its output as an artifact — the critic judges that artifact, not any return value — and fold `\$this->critique()` (the reviewer's guidance, null on the first run) into your prompt so a re-run fixes the findings — fitting for steps like a SOLID/design review or a test-and-accept gate
            - record what a step produced with `\$this->artifact('<label>', text: '<summary>')` or `\$this->artifact('<label>', file: '<path it wrote>')`. Artifacts show in the run log and give the critic something concrete. A critic does NOT strictly require an artifact, though — it is a real reviewer AI with every tool and can verify by reading the files the step changed and running `php -l`/the tests
            - EVIDENCE — when a step's critic checks a FACT a command can settle (the tests pass, the lint is clean, a build succeeds), that step MUST record the command's own output, not a sentence about it. A `text:` artifact is the step's CLAIM and can say anything — one such step recorded "All tests passed." while the suite was erroring and the issue was closed on it. Evidence is the verbatim output and cannot be composed. There are two ways to record it, and the FIRST is usually right: (a) tell the model to call the `artifact` TOOL with `command` set to the command — it runs it there and then and keeps what it printed, so the model both verifies and records inside the same exchange, which is where verification belongs; (b) from the step's own PHP, `\$out = \$this->tool('bash', ['command' => 'vendor/bin/phpunit']); \$this->artifact('tests', evidence: \$out, from: 'vendor/bin/phpunit');` — use this when the step's code, not the model, decides what gets run. Either way a `note`/`text:` alongside is your reading of that output; it is kept separate and shown as a claim about it
            - RECONCILE every step with its critic. Put a `#[Step(critic: ...)]` on a step ONLY when that step yields a JUDGEABLE RESULT the rubric can check — an artifact it recorded, or a real change the critic can inspect (a file written, tests run). Do NOT put a critic on a step that merely asks a question, restates a plan, or otherwise produces nothing to review — that is the mismatch that wedges a run. If a step needs no review, DON'T give it a critic; if it has one, make sure the step actually leaves a result. And for every critic name you use, you MUST define its rules in `criticRules()`
            - the critic is a REAL reviewer AI with every tool: it will OPEN the file artifacts, run `php -l`/the tests itself, and judge — it does not just read your summary. So make the work actually correct; a confident summary over a broken file will be caught
            - the critic name is just a key: for EVERY name you use you MUST define its actual rules by overriding `protected function criticRules(): array`, returning `['<name>' => '<the concrete criteria the reviewer checks>', ...]` — the reviewer is judged ONLY against this text, so spell the criteria out in full; a name with no rules makes the run fail. THE REVERSE IS ALSO REJECTED at save: a criticRules() entry no `#[Step(critic: ...)]` consumes is a review that silently never runs — the first live solver under this recipe shipped exactly that (rules for every step, zero critic markers, zero review). The binding, in full — the attribute names the key, the key holds the rules:
              `#[Step(critic: 'testsGreen', maxRounds: 3)]`
              `protected function verify(): void { \$this->ai('Run the suite and record it via the artifact tool with command: vendor/bin/phpunit'); }`
              `protected function criticRules(): array { return ['testsGreen' => 'the recorded phpunit evidence shows OK (all tests pass); reject on any failure or on a claim without the evidence artifact']; }`
            - a critic step re-runs until it passes; after a SMALL soft cap of rework rounds (default 2) the run escalates to a supervisor — a critic is meant to bounce a step once or twice, not churn dozens of rounds. Tune it per step with `#[Step(critic: '<name>', maxRounds: <n>)]` — raise it only for a step that legitimately churns (a test gate that iterates), keep it low elsewhere
            - reach the model with `\$this->ai(string \$prompt, ?array \$tools = null, ?string \$agent = null)` and tools with `\$this->tool(string \$name, array \$params)`
            - by DEFAULT `\$this->ai(\$prompt)` exposes EVERY tool to the model — that is the norm, let a step use whatever it needs; pass an explicit list ONLY to deliberately restrict, or `[]` for a pure-reasoning call that must not act
            - you may give THIS workflow its own bespoke tools: mark a method `#[\\Claw\\Workflow\\Tool(description: '<what it does>')]` and it becomes a tool the model can call inside your `ai()` steps (named after the method in snake_case; its typed params become the input schema). Use this to let the model check and FIX its own work — e.g. a `validate()` tool that runs `php -l` / the test gate via `\$this->tool('bash', ...)` and returns OK or the error, so a step can call it, see the failure, and correct itself in the same exchange instead of failing the run
            - route a step to a specialized agent role via the 3rd arg, e.g. `\$this->ai(\$p, null, agent: 'reviewer')`; roles: worker (cheap default), worker-smart (stronger model), reviewer (SOLID/code review), supervisor (unblock/escalate), planner (validate/design)
            - this task was assessed as **{$difficulty}**; route the solver's own implementation/test steps that call the model to `agent: '{$workerTier}'` so the work runs on the right-sized model (keep reviewer/supervisor steps on their roles)
            - when you NEED a missing detail or a decision from a person (an incomplete issue, a foundational design choice), do NOT guess: call `\$this->ask(string \$question): string` and use the returned answer — behind it may be a human or a supervisor agent
            - the run is budget-limited (tokens and time); work in focused steps and do not loop or re-read pointlessly, an exhausted budget stops the run
            - the ONLY way to touch files or the shell is through `\$this->tool(\$name, \$params)`; use EXACTLY these tool names and input keys (do not invent keys):
            {$toolDocs}
            - file paths are relative to the project root, EXACTLY as list_files shows them (e.g. 'src/Calculator.php', NOT 'Calculator.php'); when unsure of a path, call list_files at run time inside a step rather than hardcoding a guess
            - `\$this->tool(...)` returns the tool's raw output as a STRING and `\$this->ai(...)` returns the model's text as a STRING — never index them like arrays (no `\$result['content']`); parse the string if you need to
            - a tool error does NOT throw — `\$this->tool(...)` returns the failure as a string starting `tool '<name>' failed: ...`; check for that and recover (e.g. a wrong path: call list_files and retry) or fold the message into the next `\$this->ai(...)` so the model fixes it, rather than blindly using a failed result
            - a step completes by RETURNING. There is no tool that ends the run early and no way for a step to declare the whole task solved: the steps YOU write are the steps that run, so plan the ones the task needs and no more. Whether the work actually solved the ticket is settled after the run, against the project, by someone who was not doing the work
            - each step's `ai()` starts fresh — it does NOT see earlier steps, and the engine carries NOTHING between steps automatically. YOU decide, per step, what prior context it needs and have it pulled in. The door is the `recall` tool the step's model can call: `recall(what='task')` re-reads the issue brief, `what='workflow'` lists the steps so far, `what='step', name='design'` returns a sibling step's history, `what='artifacts', name='design'` its artifacts, `what='tool', name='bash'` a tool's calls. So when a step builds on an earlier one, say so in its prompt (e.g. "first call recall(what='artifacts', name='implement') to see what was changed, then ...") — do not assume the earlier work is visible, and do not re-derive what a prior step already produced
            - THE BATON IS AUTOMATIC: after each step the engine EXPLICITLY asks the model — continuing that step's OWN ai() conversation — to form a handoff (a summary of what the step did + the findings the next step must watch for), and feeds it into the next step's context as "the previous step handed this to you". You do NOT write handoff code, and it is NOT your return value — it is formed from the step's ai() work. So make each step do its work through `\$this->ai(...)`/tools and record key outputs as artifacts; the next step can rely on the incoming handoff being present (and can pull any earlier artifact with recall)
            - NEVER call PHP builtins such as file_get_contents, fopen, exec, shell_exec, system, eval, include/require, or a dynamic `\$var(...)` call — they are forbidden and the code will be rejected

            Write the complete class, then RECORD it by calling the artifact tool with label 'solver-class'
            and the full PHP source as its text (no prose, no markdown fences). The tool call is how you hand
            the class on — do not just reply with it as text.
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
}
