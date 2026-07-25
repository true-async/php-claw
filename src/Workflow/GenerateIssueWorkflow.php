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
                . '- A #[StepAI] that returns a real AiStep (a prompt, with tools), or a #[Step] that calls '
                . 'tools, IS real work — the model does the work inside the declared exchange. A `placeholder` '
                . "is a step that declares nothing to do.\n"
                . "- Few steps. One step that implements and verifies is the intended shape for a small task.\n"
                . "- Boilerplate: namespace, strict_types, the class declaration. Rejected at save if wrong.\n\n"
                . "REJECT when any of these hold — and say which:\n"
                . '- a step is too SIMPLE to earn its own existence. This is a judgement, not a count: read '
                . 'each step and ask "if it does this little, is it a step at all, or ceremony?". A step '
                . 'whose prompt carves out only a sliver — a lookup, a restatement, one trivial action a '
                . 'neighbour would absorb without noticing — should be FOLDED into that neighbour. A step '
                . 'must justify its own fresh context; a workflow that spends one on a triviality is badly '
                . "split. (Having FEW steps is not the fault — a lone meaty step is ideal; a thin step is);\n"
                . "- a step is a true placeholder: a #[StepAI] returning an empty or trivial AiStep, or a #[Step] that does nothing;\n"
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
            choosing steps, NOT a list of steps to stamp out (a step is a `#[StepAI]` method returning an
            `AiStep`, or a `#[Step]` CODE method; use plain if/while in run() where the flow loops or
            branches):
            {$recipe}
            {$approach}

            THE TWO KINDS OF STEP — read this twice, it is the part solvers get wrong:
            - An AI step does the real thinking and editing work — but you do NOT do that work in PHP. You
              write a PURE method marked `#[StepAI]` that RETURNS a declaration of ONE model exchange —
              `return new AiStep(\$prompt, \$tools, \$agent);` — and the base runs, records and (after a
              crash) resumes it. The method body has NO side effects: it just builds the prompt and returns.
              EXACTLY ONE exchange per #[StepAI]; interleaved computation goes in a neighbouring CODE step.
            - A CODE step is deterministic glue: a `protected` method marked `#[Step]` returning void that
              reads params and calls tools (`\$this->tool(...)`) — no model call. Use it only for real
              mechanical work (e.g. save a value the AI produced). Most solvers need NONE.
            - Inside the exchange an #[StepAI] declares, the model does everything through the tools you
              exposed: it reads and writes files, runs commands, records artifacts, and can pause to ask a
              person. Your method's whole job is the prompt and the tool list.
            - NEVER build the change as a PHP string and write it yourself (no `\$code = "..."; ... write_file`,
              no str_replace/preg_replace surgery on source). That is blind and corrupts files. To change a
              file, tell the MODEL to in the prompt: "Read src/X.php, add method Y, then run `php -l` and fix
              any error before you stop." — the model reads, edits and VERIFIES with tools in the ONE exchange,
              seeing the verifier's output and correcting itself before it returns.
            - Verification belongs INSIDE that one exchange (the model runs `php -l` / the tests via the bash
              tool and reacts) — a tool result is only visible while the exchange runs. A SEPARATE later step
              that just runs a check and stops is useless; the only thing that re-runs a step on a bad result
              is a CRITIC (below). So: either the model verifies-and-fixes within its own exchange, or you
              gate the step with a critic — never a bare "verify" step.

            THE SHAPE OF A STEP, EXACTLY — copy this shape; the body is one `return new AiStep(...);` and
            nothing else (no code, no side effects, and PHP heredocs/quotes only — never Python triple quotes):

                #[StepAI]
                protected function implement(): AiStep
                {
                    return new AiStep(
                        'Read src/Foo.php, add the method the ticket asks for, then run `php -l` on the file '
                        . 'and fix any error before you stop. Record the finished file with the artifact tool.',
                        ['read_file', 'write_file', 'bash', 'artifact'],
                        '{$workerTier}',
                    );
                }

            A CODE step is `protected function <name>(): void` marked `#[Step]` and calls `\$this->tool(...)`.

            Hard requirements. Most are checked mechanically when the class is saved and cost you a
            rejection round if missed — the opening tag and `declare(strict_types=1)`, the namespace and
            class name, `extends WorkflowAbstract`, at least one step, that steps are `protected`,
            that every critic name has rules, and the forbidden builtins. The rest are not checked by
            anything, which makes them the ones to read twice:
            - the file must begin with the opening tag `<?php` followed by `declare(strict_types=1);`
            - namespace {$namespace};
            - `use Claw\\Workflow\\AiStep;`, `use Claw\\Workflow\\StepAI;` and `use Claw\\Workflow\\WorkflowAbstract;` (add `use Claw\\Workflow\\Step;` if you write a CODE step, `use Claw\\Workflow\\ParamRequest;` if you pass a param)
            - `final class {$class} extends WorkflowAbstract`
            - implement `public function name(): string`
            - keep state in plain typed properties (it is snapshotted for resume) — but hand a later step what it needs on a CHANNEL below, do not rely on a field of prose
            - write each AI step as a `protected` method marked `#[StepAI]` returning an `AiStep` (NOT public, NOT private — the base run() drives them and the code is rejected otherwise); the default run() runs the steps in declaration order
            - the #[StepAI] method is PURE: build the prompt from the ticket, params and issue and RETURN `new AiStep(\$prompt, \$tools, \$agent, params: [...])`. Do NOT call the model, write files or record artifacts in the body — those happen inside the exchange the base runs from your declaration
            - GRANULARITY — SCALE the number of steps to the task's difficulty (assessed as **{$difficulty}**). A SIMPLE/trivial task needs only 1–3 steps — and a SINGLE step (implement-and-verify in one) is perfectly fine for it; do NOT force the full phase-by-phase recipe onto a simple task; the extra steps cost more and add failure surface for no benefit. A MODERATE task wants a handful of focused steps. Reserve the full breakdown (design / review / implement-per-method / test / deliver) for genuinely COMPLEX work. The reason to split AT ALL: each step's exchange starts with a fresh, lean context (one fat step re-sends a huge growing history — expensive), and a small step is a unit a critic can check. BUT every step must be a COHERENT unit of REAL work that produces something validatable (an artifact, a passing gate) — never a step that just asks a question or restates a plan. When in doubt, FEWER steps: prefer the smallest decomposition that still lets each piece be verified. Not one giant step, and not a parade of ceremonial ones.
            - a step's OUTPUT rides one of THREE channels — NEVER a field of prose and NEVER a return value: (a) ARTIFACT — the GLOBAL channel, visible to every later step (via recall) AND to this step's critic. A #[StepAI] cannot record one itself (it is pure), so tell the MODEL to call the `artifact` tool in the prompt and put `'artifact'` in the step's tool list; (b) HANDOFF — the automatic prose baton to the VERY NEXT step (below), formed for you; (c) PARAM — an addressed concrete value for ONE named later step's CODE.
            - PARAMS — declare them ON the AiStep, do not set them yourself: pass `params: [new ParamRequest(forStep: '<method>', name: '<key>', instruction: '<ask the model for exactly that value>')]`. After the step's exchange settles the base asks the model for that value and pins it FOR `<method>`, which reads it with `\$this->param('<key>')`. Use it for a concrete value a later CODE step needs deterministically — a path, a count, an id, a produced source — not prose. DURABLE (survives a resume) and OPTIONAL; most steps pass nothing this way.
            - to have a step reviewed automatically, mark it `#[StepAI(critic: '<name>')]` and have its MODEL record the result as an artifact (via the `artifact` tool) — the critic judges that artifact. On a reject the base re-runs the exchange on the supervisor's guidance; fold `\$this->critique()` (null on the first run) into your prompt IF you want the re-run to see the findings verbatim — fitting for a SOLID/design review or a test-and-accept gate
            - the artifact the critic judges is recorded BY THE MODEL, in the exchange: tell it to call the `artifact` tool (and expose `'artifact'` in the step's tools). A critic does NOT strictly require one — it is a real reviewer AI with every tool and can verify by reading the files the step changed and running `php -l`/the tests
            - EVIDENCE — when a step's critic checks a FACT a command can settle (the tests pass, the lint is clean), the model MUST record the command's own output, not a sentence: tell it to call the `artifact` tool with `command` set to the command — it runs there and then and keeps the verbatim output with its real exit status, verifying and recording in the one exchange. A `text` artifact is a CLAIM and can say anything — one such step recorded "All tests passed." while the suite was erroring and the issue was closed on it
            - RECONCILE every step with its critic. Put `#[StepAI(critic: ...)]` on a step ONLY when its model records a JUDGEABLE artifact the rubric can check. Do NOT gate a step that produces nothing to review — that mismatch wedges a run. And for every critic name you use, you MUST define its rules in `criticRules()`
            - the critic is a REAL reviewer AI with every tool: it will OPEN the file artifacts, run `php -l`/the tests itself, and judge — it does not just read your summary. So make the work actually correct; a confident summary over a broken file will be caught
            - the critic name is just a key: for EVERY name you use you MUST define its actual rules by overriding `protected function criticRules(): array`, returning `['<name>' => '<the concrete criteria the reviewer checks>', ...]` — the reviewer is judged ONLY against this text, so spell the criteria out in full; a name with no rules makes the run fail. THE REVERSE IS ALSO REJECTED at save: a criticRules() entry no `#[StepAI(critic: ...)]` consumes is a review that silently never runs. The binding, in full — the attribute names the key, the key holds the rules:
              `#[StepAI(critic: 'testsGreen', maxRounds: 3)]`
              `protected function verify(): AiStep { return new AiStep('Run the suite and record it via the artifact tool with command: vendor/bin/phpunit', ['artifact']); }`
              `protected function criticRules(): array { return ['testsGreen' => 'the recorded phpunit evidence shows OK (all tests pass); reject on any failure or on a claim without the evidence artifact']; }`
            - a critic step re-runs until it passes; after a SMALL soft cap of rework rounds (default 2) the run escalates to a supervisor — a critic is meant to bounce a step once or twice, not churn dozens of rounds. Tune it with `#[StepAI(critic: '<name>', maxRounds: <n>)]` — raise it only for a step that legitimately churns, keep it low elsewhere
            - DECLARE the exchange, do not call the model: `new AiStep(string \$prompt, ?array \$tools = null, ?string \$agent = null, array \$params = [])`. `\$tools = null` exposes EVERY tool (the norm); a list restricts (e.g. `['artifact']`); `[]` is pure reasoning that cannot act. Reach tools from a CODE step with `\$this->tool(string \$name, array \$params)`
            - you may give THIS workflow its own bespoke tools: mark a method `#[\\Claw\\Workflow\\Tool(description: '<what it does>')]` and it becomes a tool the model can call inside your steps (named after the method in snake_case; its typed params become the input schema). Use it to let the model check and FIX its own work in the same exchange — e.g. a `validate()` tool that runs `php -l` / the tests via `\$this->tool('bash', ...)` and returns OK or the error
            - route a step to a specialized agent role via the 3rd arg, e.g. `new AiStep(\$p, null, 'reviewer')`; roles: worker (cheap default), worker-smart (stronger model), reviewer (SOLID/code review), supervisor (unblock/escalate), planner (validate/design)
            - this task was assessed as **{$difficulty}**; route the solver's own implementation/test steps to `agent: '{$workerTier}'` so the work runs on the right-sized model (keep reviewer/supervisor steps on their roles)
            - when a step's model NEEDS a missing detail or a decision from a person, it should ASK inside the exchange rather than guess — it can pause for a person mid-exchange; you do not write ask code
            - the run is budget-limited (tokens and time); work in focused steps and do not loop or re-read pointlessly, an exhausted budget stops the run
            - the ONLY way to touch files or the shell is through tools the model calls; use EXACTLY these tool names and input keys (do not invent keys):
            {$toolDocs}
            - file paths are relative to the project root, EXACTLY as list_files shows them (e.g. 'src/Calculator.php', NOT 'Calculator.php'); when unsure of a path, tell the model to call list_files rather than hardcoding a guess
            - a tool error does NOT throw — a tool returns the failure as a string starting `tool '<name>' failed: ...`; the model should read it and recover (e.g. a wrong path: call list_files and retry) rather than blindly using a failed result
            - a step completes on its own; there is no tool that ends the run early and no way to declare the whole task solved: the steps YOU write are the steps that run, so plan the ones the task needs and no more. Whether the work actually solved the ticket is settled after the run, against the project, by someone who was not doing the work
            - each step's exchange starts fresh — it does NOT see earlier steps, and nothing is carried between steps automatically except the previous step's handoff. When a step builds on an earlier one, tell its model to pull what it needs with the `recall` tool: `recall(what='task')` the issue brief, `what='workflow'` the steps so far, `what='step', name='design'` a sibling's history, `what='artifacts', name='design'` its artifacts, `what='tool', name='bash'` a tool's calls. Do not assume earlier work is visible
            - THE HANDOFF IS AUTOMATIC: after each step the base asks the model — continuing that step's own exchange — to summarise what it did for the next step, and feeds it in as "the previous step handed this to you". You do NOT write handoff code. So make each step do its real work in its exchange and record key outputs as artifacts; the next step gets the handoff and can recall any earlier artifact
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
