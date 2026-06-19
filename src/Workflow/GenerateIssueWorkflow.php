<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Exceptions\WorkflowException;

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
    private string $plan = '';
    private string $code = '';

    public function name(): string
    {
        return 'generate-issue-workflow';
    }

    #[Step]
    public function understand(): void
    {
        $issue = $this->issue();
        $task = $issue !== null
            ? "Title: {$issue->title}\n\nDescription: {$issue->description}"
            : 'No issue was attached to this run.';

        $this->plan = $this->ai(
            'You are planning how to solve a task by writing a workflow. Inspect the project if it '
            . 'helps (read_file, list_files), then outline, in a few concrete sentences, the steps a '
            . "workflow should take to solve this task:\n\n" . $task,
            ['read_file', 'list_files'],
        );
    }

    #[Step]
    public function draft(): void
    {
        $this->code = $this->extractCode($this->ai($this->draftPrompt()));
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
            ));
        }

        // A second failure surfaces to the run-path, which marks the run failed.
        $this->tool('define_workflow', ['name' => $name, 'code' => $this->code, 'shared' => true]);
    }

    private function draftPrompt(): string
    {
        $namespace = (string) $this->param('solverNamespace');
        $class = (string) $this->param('solverName');
        $tools = $this->param('solverTools');
        $toolList = \is_array($tools) ? implode(', ', array_map(strval(...), $tools)) : 'read_file, write_file, list_files, bash';

        return <<<PROMPT
            Write a PHP class that solves the task below by extending Claw\\Workflow\\WorkflowAbstract.

            Plan:
            {$this->plan}

            Hard requirements (the code is validated before it is saved, and rejected if any are missed):
            - the file must begin with the opening tag `<?php` followed by `declare(strict_types=1);`
            - namespace {$namespace};
            - `use Claw\\Workflow\\Step;` and `use Claw\\Workflow\\WorkflowAbstract;`
            - `final class {$class} extends WorkflowAbstract`
            - implement `public function name(): string`
            - keep state in plain typed properties
            - write each step as a method marked `#[Step]`; the default run() drives them in declaration order
            - reach the model with `\$this->ai(string \$prompt, array \$tools)` and tools with `\$this->tool(string \$name, array \$params)`
            - the ONLY way to touch files or the shell is through `\$this->tool(...)`; available tools: {$toolList}
            - NEVER call PHP builtins such as file_get_contents, fopen, exec, shell_exec, system, eval, include/require, or a dynamic `\$var(...)` call — they are forbidden and the code will be rejected

            Return ONLY the PHP source — no prose, no markdown fences.
            PROMPT;
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
