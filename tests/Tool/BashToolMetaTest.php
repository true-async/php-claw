<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Tool\BashTool;
use Testo\Assert;
use Testo\Test;

/**
 * The bash tool's structured self-report: after handle(), the tool is ASKED for the metadata of
 * the execution it just performed — exit status, recognized program, its verdict line. Real
 * commands, no mocks: the report must come from the execution, not from parsing the text.
 */
final class BashToolMetaTest
{
    private function tool(): BashTool
    {
        return new BashTool(sys_get_temp_dir());
    }

    #[Test]
    public function reportsAZeroExitAsOk(): void
    {
        $tool = $this->tool();
        $tool->handle(['command' => 'true']);

        Assert::same($tool->resultMeta()?->status, 'ok');
        Assert::same($tool->resultMeta()?->producer, '');
        Assert::same($tool->resultMeta()?->summary, '');
    }

    #[Test]
    public function reportsANonZeroExitAsFailure(): void
    {
        $tool = $this->tool();
        $tool->handle(['command' => 'exit 3']);

        Assert::same($tool->resultMeta()?->status, 'fail');
    }

    #[Test]
    public function recognizesTheProgramAndLiftsItsVerdictLine(): void
    {
        $tool = $this->tool();
        // the command line names phpunit; the printed verdict is what a person would scan for
        $tool->handle(['command' => 'echo "OK (3 tests, 5 assertions)" # phpunit']);

        Assert::same($tool->resultMeta()?->status, 'ok');
        Assert::same($tool->resultMeta()?->producer, 'phpunit');
        Assert::same($tool->resultMeta()?->summary, 'OK (3 tests, 5 assertions)');
    }

    #[Test]
    public function theReportBelongsToTheLatestExecution(): void
    {
        $tool = $this->tool();
        $tool->handle(['command' => 'exit 1']);
        $tool->handle(['command' => 'true']);

        Assert::same($tool->resultMeta()?->status, 'ok');
    }
}
