<?php

declare(strict_types=1);

namespace Tests\Permission;

use Claw\Permission\Decision;
use Claw\Permission\Policy;
use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;
use Testo\Assert;
use Testo\Test;

final class PolicyTest
{
    #[Test]
    public function safeToolIsAllowed(): void
    {
        $verdict = (new Policy())->check($this->tool('read_file', Risk::Safe), []);

        Assert::same($verdict->decision, Decision::Allow);
    }

    #[Test]
    public function mutatingToolNeedsConfirmation(): void
    {
        $verdict = (new Policy())->check($this->tool('bash', Risk::Mutating), ['command' => 'ls']);

        Assert::same($verdict->decision, Decision::Confirm);
    }

    #[Test]
    public function dangerousToolIsDenied(): void
    {
        $verdict = (new Policy())->check($this->tool('php_eval', Risk::Dangerous), []);

        Assert::same($verdict->decision, Decision::Deny);
    }

    #[Test]
    public function denylistOverridesRisk(): void
    {
        // A Mutating tool would normally only need confirmation, but a hard rule wins.
        $verdict = (new Policy())->check(
            $this->tool('bash', Risk::Mutating),
            ['command' => 'sudo rm -rf / --no-preserve-root'],
        );

        Assert::same($verdict->decision, Decision::Deny);
    }

    private function tool(string $name, Risk $risk): ToolInterface
    {
        return new class ($name, $risk) implements ToolInterface {
            public function __construct(
                private string $toolName,
                private Risk $toolRisk,
            ) {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return '';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function risk(): Risk
            {
                return $this->toolRisk;
            }

            public function handle(array $input): string
            {
                return '';
            }
        };
    }
}
