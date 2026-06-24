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
