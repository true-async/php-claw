<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Exceptions\WorkflowException;
use Claw\Workflow\WorkflowValidator;
use Testo\Assert;
use Testo\Test;

final class WorkflowValidatorTest
{
    #[Test]
    public function acceptsACleanWorkflow(): void
    {
        $code = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ClawWorkflow\Common;

            use Claw\Workflow\WorkflowAbstract;

            final class Clean extends WorkflowAbstract
            {
                public function name(): string { return 'Clean'; }

                public function run(): void
                {
                    foreach ((array) $this->param('items') as $item) {
                        $this->tool('bash', ['command' => 'echo ' . $item]);
                    }
                }
            }
            PHP;

        Assert::false($this->rejected($code, 'ClawWorkflow\Common\Clean'));
    }

    #[Test]
    public function rejectsDangerousCode(): void
    {
        Assert::true($this->rejected("<?php\n eval('x');"));
        Assert::true($this->rejected("<?php\n shell_exec('ls');"));
        Assert::true($this->rejected("<?php\n system('ls');"));
        Assert::true($this->rejected("<?php\n file_put_contents('/etc/x', 'y');"));
        Assert::true($this->rejected("<?php\n \$x = `ls`;"));               // backtick shell
        Assert::true($this->rejected("<?php\n \$fn = 'exec'; \$fn('ls');")); // dynamic call
        Assert::true($this->rejected("<?php\n include '/etc/passwd';"));
        Assert::true($this->rejected("<?php\n this is not php {{{"));        // syntax error
    }

    #[Test]
    public function allowsForbiddenNamesAsMethods(): void
    {
        // $ctx->system(...) is a method, not the builtin — must be allowed.
        Assert::false($this->rejected(self::shell("\$ctx->system(['x']);")));
    }

    #[Test]
    public function rejectsAWorkflowMissingNameMethod(): void
    {
        // name() is abstract; omitting it fatals (uncatchable) at class load — catch it at save.
        $base = "<?php\n declare(strict_types=1);\n namespace ClawWorkflow\\Common;\n use Claw\\Workflow\\Step;\n"
            . " use Claw\\Workflow\\WorkflowAbstract;\n final class W extends WorkflowAbstract { #[Step] protected function go(): void {} ";
        Assert::true($this->rejected($base . '}', 'ClawWorkflow\\Common\\W'));   // no name()
        Assert::false($this->rejected($base . 'public function name(): string { return \'w\'; } }', 'ClawWorkflow\\Common\\W'));
    }

    #[Test]
    public function requiresStepMethodsToBeProtected(): void
    {
        $step = static fn (string $vis): string => self::shell('', "#[Step] {$vis} function go(): void {}");

        Assert::true($this->rejected($step('public')));    // public leaks the step
        Assert::true($this->rejected($step('private')));   // private can't be called from the base
        Assert::true($this->rejected(self::shell('', '#[Step] function go(): void {}')));   // no modifier = public
        Assert::false($this->rejected($step('protected')));   // the one allowed form
        // attribute arguments don't confuse the check — and the critic it names has rules
        Assert::false($this->rejected(self::shell(
            '',
            "#[Step(critic: 'r')] protected function go(): void {}\n"
            . "protected function criticRules(): array { return ['r' => 'the rules']; }",
        )));
    }

    /**
     * The structural rules the generator's prompt has always claimed were "validated before it is saved"
     * and which nothing actually checked. Each is decidable by reading the source, so each is decided
     * here — where a rejection costs one revision round — rather than at load or mid-run.
     */
    #[Test]
    public function rejectsAWorkflowThatCouldNotRunEvenThoughItParses(): void
    {
        // No strict_types.
        Assert::true($this->rejected(str_replace(' declare(strict_types=1);', '', self::shell(''))));

        // No base class: none of ai()/tool()/step() exists, so it fatals on the first step call.
        Assert::true($this->rejected(str_replace('extends WorkflowAbstract', '', self::shell(''))));

        // Nothing to run at all — no #[Step] and no run() of its own. The default run() would find
        // nothing, the run would "succeed" having done nothing, and the ticket would be closed on it.
        Assert::true($this->rejected(self::shell('', 'protected function helper(): void {}')));

        // But driving the work from an overridden run() is a first-class shape and must be accepted.
        Assert::false($this->rejected(self::shell('', 'public function run(): void { $this->tool(\'bash\', []); }')));
    }

    /**
     * A `#[Step(critic: 'x')]` with no 'x' in criticRules() used to be caught only by a LogicException
     * at RUN time — mid-run, on the real project, after a human had approved the solver, discarding
     * every step already done. It is decidable from the source, so it is decided at save.
     */
    #[Test]
    public function rejectsACriticNameThatHasNoRules(): void
    {
        Assert::true($this->rejected(self::shell('', "#[Step(critic: 'gate')] protected function go(): void {}")));
    }

    /**
     * The mirror: rules nobody consumes are a review that silently never runs — the first live
     * solver under the cycle recipe wrote rules for every step and marked none, so it LOOKED
     * reviewed while nothing reviewed it.
     */
    #[Test]
    public function rejectsCriticRulesThatNoStepConsumes(): void
    {
        Assert::true($this->rejected(self::shell(
            '',
            "#[Step] protected function go(): void {}\n"
            . "protected function criticRules(): array { return ['orphan' => 'judged by nobody']; }",
        )));

        // consumed rules stay valid — the existing binding is untouched
        Assert::false($this->rejected(self::shell(
            '',
            "#[Step(critic: 'gate')] protected function go(): void {}\n"
            . "protected function criticRules(): array { return ['gate' => 'the rules']; }",
        )));

        Assert::true($this->rejected(self::shell(
            '',
            "#[Step(critic: 'gate')] protected function go(): void {}\n"
            . "protected function criticRules(): array { return ['other' => 'rules for something else']; }",
        )));

        Assert::false($this->rejected(self::shell(
            '',
            "#[Step(critic: 'gate')] protected function go(): void {}\n"
            . "protected function criticRules(): array { return ['gate' => 'the tests must be green']; }",
        )));
    }

    /**
     * The validator recognises #[StepAI] as a step, the same as #[Step]. It used to key off `#[Step\b`,
     * which does not match `StepAI`, so a purely declarative solver — the shape the generator now writes —
     * would have been rejected for declaring "no step", and its critic bindings would have gone unchecked.
     */
    #[Test]
    public function recognisesStepAiAsAStep(): void
    {
        // a declarative-only workflow IS a workflow
        Assert::false($this->rejected(self::shell('', "#[StepAI] protected function go(): AiStep { return new AiStep('p'); }")));

        // and its critic bindings are validated too: a name with no rules, and an orphan rule, both rejected
        Assert::true($this->rejected(self::shell('', "#[StepAI(critic: 'gate')] protected function go(): AiStep { return new AiStep('p'); }")));
        Assert::false($this->rejected(self::shell(
            '',
            "#[StepAI(critic: 'gate')] protected function go(): AiStep { return new AiStep('p'); }\n"
            . "protected function criticRules(): array { return ['gate' => 'the rules']; }",
        )));

        // a #[StepAI] method must be protected, the same as a #[Step] one
        Assert::true($this->rejected(self::shell('', "#[StepAI] public function go(): AiStep { return new AiStep('p'); }")));
    }

    /**
     * A structurally valid workflow wrapped around $body, so a case can probe ONE rule without tripping
     * the others. $extra is spliced in at class level for cases that need their own step declaration.
     */
    private static function shell(string $body, string $extra = '#[Step] protected function go(): void {}'): string
    {
        return "<?php\n declare(strict_types=1);\n namespace ClawWorkflow\\Common;\n"
            . " use Claw\\Workflow\\Step;\n use Claw\\Workflow\\WorkflowAbstract;\n"
            . " final class W extends WorkflowAbstract {\n"
            . " public function name(): string { return 'w'; }\n"
            . " {$extra}\n"
            . " protected function body(): void { {$body} }\n }";
    }

    private function rejected(string $code, ?string $expectedClass = null): bool
    {
        try {
            new WorkflowValidator()->validate($code, $expectedClass);

            return false;
        } catch (WorkflowException) {
            return true;
        }
    }
}
