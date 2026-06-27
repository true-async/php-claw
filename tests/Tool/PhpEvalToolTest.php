<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\PhpEvalTool;
use Claw\Tool\Risk;
use Testo\Assert;
use Testo\Test;

final class PhpEvalToolTest
{
    #[Test]
    public function evaluatesSingleExpression(): void
    {
        $tool = new PhpEvalTool();

        Assert::same($tool->risk(), Risk::Dangerous);
        Assert::same($tool->handle(['code' => "strtoupper('hi')"]), 'HI');
        Assert::same($tool->handle(['code' => '2 ** 10']), '1024');
        Assert::same($tool->handle(['code' => "strlen('abc');"]), '3');   // trailing ; tolerated
    }

    #[Test]
    public function reportsErrorsAndMissingCode(): void
    {
        $tool = new PhpEvalTool();

        $this->assertToolError(fn () => $tool->handle([]));                          // missing code
        $this->assertToolError(fn () => $tool->handle(['code' => 'no_such_fn()']));  // runtime error
    }

    private function assertToolError(callable $fn): void
    {
        $threw = false;

        try {
            $fn();
        } catch (ToolException $e) {
            $threw = true;
        }

        Assert::true($threw);
    }
}
