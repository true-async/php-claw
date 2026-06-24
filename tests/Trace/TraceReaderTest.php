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

    #[Test]
    public function listsRunsFromTheLedgerNewestFirst(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE runs (id INTEGER PRIMARY KEY AUTOINCREMENT, issue_id INTEGER, workflow TEXT, status TEXT, created_at INTEGER)');
        $pdo->exec("INSERT INTO runs (issue_id, workflow, status, created_at) VALUES (7, 'Issue7Solver', 'done', 0)");
        $pdo->exec("INSERT INTO runs (issue_id, workflow, status, created_at) VALUES (7, 'Issue7Solver', 'failed', 0)");

        $runs = (new TraceReader($pdo))->runs();

        Assert::count($runs, 2);
        Assert::same($runs[0]['id'], '2');          // newest first
        Assert::same($runs[0]['status'], 'failed');
        Assert::same($runs[1]['issue'], '7');
        Assert::same($runs[1]['workflow'], 'Issue7Solver');
    }
}
