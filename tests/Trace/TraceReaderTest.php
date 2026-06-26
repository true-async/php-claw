<?php

declare(strict_types=1);

namespace Tests\Trace;

use Claw\Trace\Level;
use Claw\Trace\Tracer;
use Claw\Trace\TraceReader;
use Claw\Trace\TraceStore;
use Testo\Assert;
use Testo\Test;

final class TraceReaderTest
{
    #[Test]
    public function rendersATraceTreeFromTheDb(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $tracer = new Tracer('5', new TraceStore($pdo));

        $wf = $tracer->enterWorkflow('demo');
        $step = $tracer->enterStep('validate');
        $tracer->toolCall('read_file', ['path' => 'x']);
        $tracer->exit($step);
        $tracer->exit($wf);

        $reader = new TraceReader($pdo);
        Assert::same($reader->latestRunId(), '5');

        $tree = $reader->render('5');
        Assert::true(str_contains($tree, '▶ workflow demo'));
        Assert::true(str_contains($tree, '  ▶ step validate'));
        Assert::true(str_contains($tree, '    · tool read_file'));   // depth 2 under the step
        Assert::true(str_contains($tree, '◀ end'));
    }

    #[Test]
    public function recallsAStepsHistoryToolCallsAndArtifactsFromTheJournal(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $tracer = new Tracer('9', new TraceStore($pdo));

        $wf = $tracer->enterWorkflow('Solver');
        $a = $tracer->enterStep('design');
        $tracer->toolCall('read_file', ['path' => 'src/X.php']);
        $tracer->artifact('plan', 'text', 'add method Y');
        $tracer->exit($a);
        $b = $tracer->enterStep('implement');
        $tracer->toolCall('write_file', ['path' => 'src/X.php']);
        $tracer->artifact('changed', 'file', 'src/X.php');
        $tracer->exit($b);
        $tracer->exit($wf);

        $reader = new TraceReader($pdo);

        // a step's own subtree, scoped — design's tool + artifact, not implement's
        $design = $reader->stepHistory('9', 'design');
        Assert::true(str_contains($design, 'tool read_file'));
        Assert::true(str_contains($design, 'plan'));
        Assert::true(!str_contains($design, 'write_file'));

        // every call to one tool across the run
        Assert::true(str_contains($reader->toolHistory('9', 'write_file'), 'write_file'));

        // artifacts: all, then scoped to one step
        $all = $reader->artifacts('9');
        Assert::true(str_contains($all, 'plan') && str_contains($all, 'changed'));
        $implementOnly = $reader->artifacts('9', 'implement');
        Assert::true(str_contains($implementOnly, 'changed') && !str_contains($implementOnly, 'plan'));

        // the run map
        $map = $reader->describe('9');
        Assert::true(str_contains($map, 'Solver') && str_contains($map, 'design, implement'));
    }

    #[Test]
    public function renderFiltersByThreshold(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $tracer = new Tracer('7', new TraceStore($pdo));

        $wf = $tracer->enterWorkflow('demo');     // Notice
        $step = $tracer->enterStep('validate');   // Info
        $tracer->prompt('secret reasoning');      // Debug
        $tracer->exit($step);
        $tracer->exit($wf);

        $reader = new TraceReader($pdo);

        $quiet = $reader->render('7', Level::Notice);
        Assert::true(str_contains($quiet, 'workflow demo'));
        Assert::true(!str_contains($quiet, 'step validate'));
        Assert::true(!str_contains($quiet, 'prompt'));

        $all = $reader->render('7', Level::Debug);
        Assert::true(str_contains($all, 'step validate'));
        Assert::true(str_contains($all, 'prompt'));
    }
}
