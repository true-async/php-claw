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
        Assert::false($this->rejected("<?php\n \$ctx->system(['x']);"));
    }

    #[Test]
    public function rejectsAWorkflowMissingNameMethod(): void
    {
        // name() is abstract; omitting it fatals (uncatchable) at class load — catch it at save.
        $base = "<?php\n namespace ClawWorkflow\\Common;\n use Claw\\Workflow\\WorkflowAbstract;\n final class W extends WorkflowAbstract { ";
        Assert::true($this->rejected($base . '}', 'ClawWorkflow\\Common\\W'));   // no name()
        Assert::false($this->rejected($base . 'public function name(): string { return \'w\'; } }', 'ClawWorkflow\\Common\\W'));
    }

    #[Test]
    public function requiresStepMethodsToBeProtected(): void
    {
        $step = static fn (string $vis): string => "<?php\n use Claw\\Workflow\\Step;\n class W { #[Step]\n {$vis} function go(): void {} }";

        Assert::true($this->rejected($step('public')));    // public leaks the step
        Assert::true($this->rejected($step('private')));   // private can't be called from the base
        Assert::true($this->rejected("<?php\n use Claw\\Workflow\\Step;\n class W { #[Step]\n function go(): void {} }"));   // no modifier = public
        Assert::false($this->rejected($step('protected')));   // the one allowed form
        // attribute arguments don't confuse the check
        Assert::false($this->rejected("<?php\n use Claw\\Workflow\\Step;\n class W { #[Step(critic: 'r')]\n protected function go(): void {} }"));
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
