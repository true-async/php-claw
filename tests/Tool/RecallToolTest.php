<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Tool\RecallTool;
use Claw\Tool\Risk;
use Claw\Trace\Tracer;
use Claw\Trace\TraceReader;
use Claw\Trace\TraceStore;
use Testo\Assert;
use Testo\Test;

final class RecallToolTest
{
    #[Test]
    public function recallsTheTaskBriefAndStepHistoryForItsRun(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $tracer = new Tracer('3', new TraceStore($pdo));

        $wf = $tracer->enterWorkflow('Solver');
        $step = $tracer->enterStep('design');
        $tracer->artifact('plan', 'text', 'add subtract()');
        $tracer->exit($step);
        $tracer->exit($wf);

        $tool = new RecallTool(new TraceReader($pdo), '3', "Title: Fix add\n\nDescription: it subtracts");

        Assert::same($tool->name(), 'recall');
        Assert::same($tool->risk(), Risk::Safe);

        // the original task brief
        Assert::true(str_contains($tool->handle(['what' => 'task']), 'Fix add'));

        // a sibling step's recorded artifacts
        Assert::true(str_contains($tool->handle(['what' => 'step', 'name' => 'design']), 'plan'));

        // the run map lists the step
        Assert::true(str_contains($tool->handle(['what' => 'workflow']), 'design'));
    }

    #[Test]
    public function missingNameForAStepRecallIsAnError(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        new TraceStore($pdo);   // ensure the table exists
        $tool = new RecallTool(new TraceReader($pdo), '1');

        $threw = false;
        try {
            $tool->handle(['what' => 'step']);   // no name
        } catch (\Claw\Exceptions\ToolException) {
            $threw = true;
        }

        Assert::true($threw);
    }
}
