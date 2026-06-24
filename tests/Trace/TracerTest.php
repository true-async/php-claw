<?php

declare(strict_types=1);

namespace Tests\Trace;

use Claw\Trace\ArrayTraceSink;
use Claw\Trace\Level;
use Claw\Trace\Tracer;
use Claw\Trace\TraceStore;
use Testo\Assert;
use Testo\Test;

final class TracerTest
{
    #[Test]
    public function buildsAHierarchyOfSpansAndEvents(): void
    {
        $sink = new ArrayTraceSink();
        $tracer = new Tracer('r1', $sink);

        $wf = $tracer->enterWorkflow('demo');
        $step = $tracer->enterStep('s1');
        $tracer->prompt('hello', ['read_file']);
        $tracer->exit($step);
        $tracer->exit($wf);

        $r = $sink->records;
        Assert::same(count($r), 5);

        // workflow span at the root
        Assert::same($r[0]->phase(), 'enter');
        Assert::same($r[0]->event()->type, 'workflow');
        Assert::null($r[0]->parentId());
        Assert::same($r[0]->depth(), 0);
        Assert::same($r[0]->runId(), 'r1');

        // step span nested under the workflow
        Assert::same($r[1]->event()->type, 'step');
        Assert::same($r[1]->parentId(), $r[0]->id());
        Assert::same($r[1]->depth(), 1);

        // prompt event nested under the step
        Assert::same($r[2]->phase(), 'event');
        Assert::same($r[2]->event()->type, 'prompt');
        Assert::same($r[2]->parentId(), $r[1]->id());
        Assert::same($r[2]->depth(), 2);

        // exits unwind to matching depths
        Assert::same($r[3]->phase(), 'exit');
        Assert::same($r[3]->depth(), 1);
        Assert::same($r[4]->phase(), 'exit');
        Assert::same($r[4]->depth(), 0);
    }

    #[Test]
    public function eventsCarryLevelsAndAnExitInheritsItsOpenLevel(): void
    {
        $sink = new ArrayTraceSink();
        $tracer = new Tracer('r1', $sink);

        $wf = $tracer->enterWorkflow('demo');   // Notice
        $ai = $tracer->enterAi('worker', 'm');  // Debug
        $tracer->exit($ai);                     // close inherits the ai's Debug
        $tracer->exit($wf);                     // close inherits the workflow's Notice

        $r = $sink->records;
        Assert::same($r[0]->event()->level, Level::Notice);   // workflow enter
        Assert::same($r[1]->event()->level, Level::Debug);    // ai enter
        Assert::same($r[2]->event()->level, Level::Debug);    // ai exit inherited
        Assert::same($r[3]->event()->level, Level::Notice);   // workflow exit inherited
    }

    #[Test]
    public function traceStorePersistsEveryRecord(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $tracer = new Tracer('r9', new TraceStore($pdo));

        $wf = $tracer->enterWorkflow('demo');
        $tracer->reply('done', [], 10, 5);
        $tracer->exit($wf);

        $stmt = $pdo->query('SELECT run_id, phase, type, data FROM trace ORDER BY seq');
        if ($stmt === false) {
            throw new \RuntimeException('trace query failed');
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Assert::same(count($rows), 3);
        Assert::same($rows[0]['run_id'], 'r9');
        Assert::same($rows[0]['type'], 'workflow');
        Assert::same($rows[1]['type'], 'reply');
        Assert::true(str_contains($rows[1]['data'], '"in":10'));   // usage persisted
        Assert::same($rows[2]['phase'], 'exit');
    }
}
