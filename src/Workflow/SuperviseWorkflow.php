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
    /** One bounded repair: on a validator reject at save, back() re-writes once, then a second failure throws. */
    private bool $repairAttempted = false;

    public function name(): string
    {
        return 'supervise-run';
    }

    /**
     * A pure {@see StepAI}: declare the repair exchange (on the supervisor role), the base runs it. The
     * corrected source reaches {@see save()} as an addressed `code` param — the declarative model carries
     * nothing in fields.
     */
    #[StepAI]
    protected function repair(): AiStep
    {
        return new AiStep(
            $this->repairPrompt(),
            [],
            'supervisor',
            params: [new ParamRequest(
                forStep: 'save',
                name: 'code',
                instruction: 'Output the complete corrected PHP source, exactly and nothing else — '
                    . 'no prose, no markdown fences.',
            )],
        );
    }

    /**
     * Save the corrected solver through `define_workflow` (which validates, then stores it). On a validator
     * reject it is re-written ONCE: back() into {@see repair()} hands it the complaint via critique() and it
     * rewrites; a second reject surfaces to the run path. A CODE step, so it never calls the model itself.
     */
    #[Step]
    protected function save(): void
    {
        $code = $this->extractCode((string) $this->param('code'));
        $result = $this->tool('define_workflow', [
            'name' => (string) $this->param('fixedName'),
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
        $this->back('repair', "The corrected class was rejected when it was saved: {$result}\n\n"
            . 'Rewrite the FULL class fixing exactly that.');
    }

    private function repairPrompt(): string
    {
        // A re-write after the validator rejected the save: the model still holds its previous attempt in
        // the continued conversation, so hand it only the complaint to fix (via critique(), set by back()).
        $critique = $this->critique();

        if ($critique !== null) {
            return "The corrected class was rejected:\n\n{$critique}\n\n"
                . 'Rewrite the FULL class fixing exactly that. Reply with only the corrected PHP source.';
        }

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
            - reach the model with `\$this->ai(string \$prompt, ?array \$tools = null, ?string \$agent = null)`
              (\$tools defaults to ALL tools; pass a list to restrict or `[]` for none), tools with
              `\$this->tool(string \$name, array \$params)`, and a person or supervisor with
              `\$this->ask(string \$question): string`; all of them return STRINGS
            - the ONLY way to touch files or the shell is `\$this->tool(...)`; file paths are relative to the project root
            - NEVER call PHP builtins such as file_get_contents, fopen, exec, shell_exec, system, eval,
              include/require, or a dynamic `\$var(...)` call — they are forbidden and the code is rejected

            Return ONLY the corrected PHP source — no prose, no markdown fences.
            PROMPT;
    }
}
