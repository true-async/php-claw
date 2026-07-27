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
            null,   // FULL palette — the repair model may read the project to find the real cause
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
        $this->back('repair', "The corrected class was rejected when it was saved: {$result}");
    }

    private function repairPrompt(): string
    {
        // A re-write after the validator rejected the save: save() reaches it via back('repair'), which
        // re-enters this step FRESH — the exchange it first ran is gone — so the model does NOT still hold
        // its prior attempt. Hand it the whole brief again (the broken source and the error persist as run
        // params) with the complaint on top; the complaint alone tells it to "fix" a class it cannot see.
        $critique = $this->critique();
        $rework = $critique === null ? '' : 'Your PREVIOUS attempt at this repair was rejected — you '
            . "cannot see it now, but here is WHY it was rejected:\n\n{$critique}\n\n"
            . 'Write a FRESH corrected class from the original broken source below, avoiding that '
            . "rejection. The complete brief follows.\n\n\n";

        $namespace = (string) $this->param('fixedNamespace');
        $class = (string) $this->param('fixedName');
        $error = (string) $this->param('error');
        $code = (string) $this->param('brokenCode');

        return $rework . <<<PROMPT
            A generated solver workflow crashed at runtime. Find and fix the cause, and return the
            corrected class.

            Runtime error:
            {$error}

            The solver's current source:
            {$code}

            Hard requirements (the code is validated before it is saved, and rejected if any are missed):
            - the file must begin with `<?php` then `declare(strict_types=1);`
            - namespace {$namespace};
            - `use Claw\\Workflow\\AiStep;`, `use Claw\\Workflow\\StepAI;` and `use Claw\\Workflow\\WorkflowAbstract;` (keep `use Claw\\Workflow\\Step;` if the broken solver has a CODE step)
            - `final class {$class} extends WorkflowAbstract` (use the NEW name {$class})
            - implement `public function name(): string`
            - KEEP the same step method names (each `#[StepAI]` or `#[Step]`) and the same typed property names
              as the broken solver: the run is mid-flight, and its saved state is restored by property name and
              resumed by step name, so renaming them loses progress
            - an AI step is a PURE `#[StepAI]` method that RETURNS `new AiStep(\$prompt, ?\$tools, ?\$agent)` — the
              base runs the one declared exchange; the method calls no model and has no side effects. A `#[Step]`
              CODE method returning void may call `\$this->tool(...)`. The model reads/writes files, runs commands
              and can ask a person INSIDE the declared exchange, through the tools you expose
            - the ONLY way to touch files or the shell is a tool the model calls; file paths are relative to the project root
            - NEVER call PHP builtins such as file_get_contents, fopen, exec, shell_exec, system, eval,
              include/require, or a dynamic `\$var(...)` call — they are forbidden and the code is rejected

            You MAY read the project's files to trace the real cause before you rewrite. Work out the fix
            now — you will be asked at the end to output the complete corrected source, so do not paste the
            whole class in this turn; that request comes separately.
            PROMPT;
    }
}
