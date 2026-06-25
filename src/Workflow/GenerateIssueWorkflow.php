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
        4. Implement — make the change, component by component.
        5. Test & accept — add a test for EACH acceptance criterion from step 1; run the full
           quality gate via the bash tool (e.g. `composer qa`) and make it green; watch for
           regressions. If a review finding or a test fails, loop back to step 4 (or 2) until green.
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
    public function understand(): void
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
    public function assess(): void
    {
        $verdict = strtolower(trim($this->ai(
            'Rate how hard this coding task is for an AI to solve correctly. Reply with EXACTLY one '
            . 'word on the first line — `simple`, `moderate`, or `complex` — then one sentence of '
            . 'reasoning. Simple = a localized, mechanical change; complex = subtle logic, wide blast '
            . "radius, or design judgement.\n\nTask:\n{$this->taskSummary()}\n\nPlan:\n{$this->plan}",
            [],
            'supervisor-smart',
        )));

        $this->difficulty = str_contains($verdict, 'complex') ? 'complex'
            : (str_contains($verdict, 'simple') ? 'simple' : 'moderate');

        // A simple task runs cheap; anything with real judgement gets the strong tier.
        $this->workerTier = $this->difficulty === 'simple' ? 'worker' : 'worker-smart';
    }

    #[Step]
    public function draft(): void
    {
        $this->code = $this->extractCode($this->ai($this->draftPrompt(), agent: 'worker-smart'));
    }

    /**
     * The supervisor reviews the finished solver before it is saved: not "is it valid PHP" (the
     * validator does that) but "will it actually work" — does every step do real work via tools/ai
     * rather than return a placeholder, is each critic name backed by criticRules(), is the task
     * truly solved. On a rejection, one strong-tier revision pass folds the findings back in.
     */
    #[Step]
    public function review(): void
    {
        $verdict = trim($this->ai(
            'You are a senior engineer reviewing a GENERATED solver workflow before it is allowed to '
            . 'run. Judge whether it will actually solve the task — not its syntax. Reject it if any '
            . 'step just returns a placeholder string instead of doing real work via $this->tool()/'
            . "\$this->ai(), if a `#[Step(critic: '<name>')]` has no matching entry in criticRules(), "
            . "or if the recipe is not genuinely carried out.\n\n"
            . "If it is genuinely ready to run, reply with exactly: OK\n"
            . "Otherwise reply with the concrete problems that must be fixed.\n\n"
            . "The task:\n{$this->taskSummary()}\n\nThe workflow code:\n{$this->code}",
            [],
            'supervisor-smart',
        ));

        if (strtoupper(trim($verdict)) === 'OK') {
            return;
        }

        $this->code = $this->extractCode($this->ai(
            "A senior reviewer rejected the workflow you wrote. Problems to fix:\n{$verdict}\n\n"
            . "Return ONLY the corrected PHP source. The constraints are unchanged:\n\n"
            . $this->draftPrompt() . "\n\nThe code you produced was:\n\n" . $this->code,
            agent: 'worker-smart',
        ));
    }

    #[Step]
    public function save(): void
    {
        $name = (string) $this->param('solverName');

        try {
            $this->tool('define_workflow', ['name' => $name, 'code' => $this->code, 'shared' => true]);

            return;
        } catch (WorkflowException $e) {
            // One repair pass: hand the validator's complaint back to the model and retry once.
            $this->code = $this->extractCode($this->ai(
                "The workflow class you wrote was rejected: {$e->getMessage()}\n\n"
                . "Return ONLY the corrected PHP source. The constraints are unchanged:\n\n"
                . $this->draftPrompt() . "\n\nThe code you produced was:\n\n" . $this->code,
                agent: 'worker-smart',
            ));
        }

        // A second failure surfaces to the run-path, which marks the run failed.
        $this->tool('define_workflow', ['name' => $name, 'code' => $this->code, 'shared' => true]);
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

            Hard requirements (the code is validated before it is saved, and rejected if any are missed):
            - the file must begin with the opening tag `<?php` followed by `declare(strict_types=1);`
            - namespace {$namespace};
            - `use Claw\\Workflow\\Step;` and `use Claw\\Workflow\\WorkflowAbstract;`
            - `final class {$class} extends WorkflowAbstract`
            - implement `public function name(): string`
            - keep state in plain typed properties
            - write each step as a method marked `#[Step]`; the default run() drives them in declaration order
            - to have a step's result reviewed automatically, mark it `#[Step(critic: '<name>')]`, make that method RETURN its result as a string, and fold `\$this->critique()` (the reviewer's guidance, null on the first run) into your prompt so a re-run fixes the findings — fitting for the SOLID-review (step 3) and test&accept (step 5) steps
            - the critic name is just a key: for EVERY name you use you MUST define its actual rules by overriding `protected function criticRules(): array`, returning `['<name>' => '<the concrete criteria the reviewer checks>', ...]` — the reviewer is judged ONLY against this text, so spell the criteria out in full; a name with no rules makes the run fail
            - reach the model with `\$this->ai(string \$prompt, array \$tools, ?string \$agent = null)` and tools with `\$this->tool(string \$name, array \$params)`
            - route a step to a specialized agent role via the 3rd arg, e.g. `\$this->ai(\$p, \$tools, agent: 'reviewer')`; roles: worker (cheap default), worker-smart (stronger model), reviewer (SOLID/code review), supervisor (unblock/escalate), planner (validate/design)
            - this task was assessed as **{$this->difficulty}**; route the solver's own implementation/test steps that call the model to `agent: '{$this->workerTier}'` so the work runs on the right-sized model (keep reviewer/supervisor steps on their roles)
            - when you NEED a missing detail or a decision from a person (an incomplete issue, a foundational design choice), do NOT guess: call `\$this->ask(string \$question): string` and use the returned answer — behind it may be a human or a supervisor agent
            - the run is budget-limited (tokens and time); work in focused steps and do not loop or re-read pointlessly, an exhausted budget stops the run
            - the ONLY way to touch files or the shell is through `\$this->tool(\$name, \$params)`; use EXACTLY these tool names and input keys (do not invent keys):
            {$toolDocs}
            - file paths are relative to the project root, EXACTLY as list_files shows them (e.g. 'src/Calculator.php', NOT 'Calculator.php'); when unsure of a path, call list_files at run time inside a step rather than hardcoding a guess
            - `\$this->tool(...)` returns the tool's raw output as a STRING and `\$this->ai(...)` returns the model's text as a STRING — never index them like arrays (no `\$result['content']`); parse the string if you need to
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

    /** Strip a ``` ... ``` fence if the model wrapped the code in one. */
    private function extractCode(string $text): string
    {
        $text = trim($text);
        if (preg_match('/```(?:php)?\s*(.+?)\s*```/s', $text, $m) === 1) {
            return trim($m[1]);
        }

        return $text;
    }
}
