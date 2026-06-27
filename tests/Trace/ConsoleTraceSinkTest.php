<?php

declare(strict_types=1);

namespace Tests\Trace;

use Claw\Trace\ConsoleTraceSink;
use Claw\Trace\Level;
use Claw\Trace\Tracer;
use Testo\Assert;
use Testo\Test;

final class ConsoleTraceSinkTest
{
    #[Test]
    public function infoThresholdShowsProgressButNotModelChatter(): void
    {
        $out = $this->capture(Level::Info, static function (Tracer $tracer): void {
            $wf = $tracer->enterWorkflow('demo');
            $step = $tracer->enterStep('s1');
            $tracer->toolCall('read_file', ['path' => 'x']);   // Info → shown
            $tracer->prompt('hello');                          // Debug → hidden
            $tracer->exit($step);
            $tracer->exit($wf);
        });

        Assert::true(str_contains($out, 'workflow demo'));
        Assert::true(str_contains($out, 'step s1'));
        Assert::true(str_contains($out, 'tool read_file'));
        Assert::true(!str_contains($out, 'prompt'));            // model chatter filtered out
    }

    #[Test]
    public function quietThresholdShowsOnlyMilestonesAndErrors(): void
    {
        $out = $this->capture(Level::Notice, static function (Tracer $tracer): void {
            $wf = $tracer->enterWorkflow('demo');               // Notice → shown
            $step = $tracer->enterStep('s1');                   // Info → hidden
            $tracer->toolResult('bash', 'boom', true);          // Notice (error) → shown
            $tracer->exit($step);                               // inherits Info → hidden
            $tracer->exit($wf);                                 // inherits Notice → shown
        });

        Assert::true(str_contains($out, 'workflow demo'));
        Assert::true(str_contains($out, 'tool-result bash'));   // the error survives quiet
        Assert::true(!str_contains($out, 'step s1'));
    }

    /** Drive a tracer whose sole sink is a console sink at $threshold, return what it printed. */
    private function capture(Level $threshold, callable $run): string
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('cannot open memory stream');
        }

        $run(new Tracer('r1', new ConsoleTraceSink($stream, $threshold)));

        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
