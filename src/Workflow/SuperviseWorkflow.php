<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Exceptions\WorkflowException;

/**
 * The supervisor's run-level CODE REPAIR: when a generated solver crashes at runtime, this takes its
 * source and the error and writes a FIXED version under a NEW class name (via the supervisor role and
 * the same `define_workflow` door). The run-path then resumes the same runId with the new class — its
 * durable snapshot restores the finished steps' state and re-runs only the failing tail. A new name is
 * used because PHP cannot reload a class already loaded in-process.
 *
 * This is the cheapest rung of the supervisor ladder (fix the code). Deeper, step-level unblocking
 * (guide / escalate to a human) is the ask channel's {@see \Claw\Agent\EscalatingSpeaker}, reached
 * from inside the solver via `$this->ask()`.
 */
final class SuperviseWorkflow extends WorkflowAbstract
{
    private string $fixed = '';

    public function name(): string
    {
        return 'supervise-run';
    }

    #[Step]
    public function repair(): void
    {
        $this->fixed = $this->extractCode($this->ai($this->repairPrompt(), [], 'supervisor'));
    }

    #[Step]
    public function save(): void
    {
        $name = (string) $this->param('fixedName');

        try {
            $this->tool('define_workflow', ['name' => $name, 'code' => $this->fixed, 'shared' => true]);

            return;
        } catch (WorkflowException $e) {
            // One repair pass: hand the validator's complaint back to the supervisor and retry once.
            $this->fixed = $this->extractCode($this->ai(
                "The corrected class was rejected: {$e->getMessage()}\n\n"
                . "Return ONLY the corrected PHP source. The constraints are unchanged:\n\n"
                . $this->repairPrompt() . "\n\nThe code you produced was:\n\n" . $this->fixed,
                [],
                'supervisor',
            ));
        }

        // A second failure surfaces to the run-path, which gives up on this repair attempt.
        $this->tool('define_workflow', ['name' => $name, 'code' => $this->fixed, 'shared' => true]);
    }

    private function repairPrompt(): string
    {
        $namespace = (string) $this->param('fixedNamespace');
        $class = (string) $this->param('fixedName');
        $error = (string) $this->param('error');
        $code = (string) $this->param('brokenCode');

        return <<<PROMPT
            A generated solver workflow crashed at runtime. Find and fix the cause, and return the
            corrected class.

            Runtime error:
            {$error}

            The solver's current source:
            {$code}

            Hard requirements (the code is validated before it is saved, and rejected if any are missed):
            - the file must begin with `<?php` then `declare(strict_types=1);`
            - namespace {$namespace};
            - `use Claw\\Workflow\\Step;` and `use Claw\\Workflow\\WorkflowAbstract;`
            - `final class {$class} extends WorkflowAbstract` (use the NEW name {$class})
            - implement `public function name(): string`
            - KEEP the same `#[Step]` method names and the same typed property names as the broken
              solver: the run is mid-flight, and its saved state is restored by property name and
              resumed by step name, so renaming them loses progress
            - reach the model with `\$this->ai(string \$prompt, array \$tools, ?string \$agent = null)`,
              tools with `\$this->tool(string \$name, array \$params)`, and a person or supervisor with
              `\$this->ask(string \$question): string`; all of them return STRINGS
            - the ONLY way to touch files or the shell is `\$this->tool(...)`; file paths are relative to the project root
            - NEVER call PHP builtins such as file_get_contents, fopen, exec, shell_exec, system, eval,
              include/require, or a dynamic `\$var(...)` call — they are forbidden and the code is rejected

            Return ONLY the corrected PHP source — no prose, no markdown fences.
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
