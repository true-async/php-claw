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

            use Claw\Workflow\WorkflowContext;
            use Claw\Workflow\WorkflowInterface;

            final class Clean implements WorkflowInterface
            {
                public function name(): string { return 'Clean'; }
                public function description(): string { return 'demo'; }
                public function inputSchema(): array { return ['type' => 'object']; }

                public function run(array $input, WorkflowContext $ctx): array
                {
                    $out = [];
                    foreach ($input['items'] ?? [] as $item) {
                        $out[] = $ctx->call('bash', ['command' => 'echo ' . $item]);
                    }
                    return ['lines' => $out];
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
            (new WorkflowValidator())->validate($code, $expectedClass);

            return false;
        } catch (WorkflowException) {
            return true;
        }
    }
}
