<?php

declare(strict_types=1);

namespace Claw\Run;

use Claw\Agent\ConsoleSpeaker;
use Claw\Agent\SpeakerInterface;
use Claw\Trace\ConsoleTraceSink;
use Claw\Trace\Level;
use Claw\Trace\Tracer;
use Claw\Trace\TraceSinkInterface;

/**
 * The console front-end of a run (`claw run`): the human answers on the terminal, the generated solver is
 * shown for a y/N confirm before it runs, progress prints to STDOUT/STDERR, and the live trace is the
 * indented stderr tree.
 */
final readonly class ConsoleRunFrontend implements RunFrontendInterface
{
    public function __construct(private ?Level $verbosity = null)
    {
    }

    public function human(Tracer $tracer): SpeakerInterface
    {
        return new ConsoleSpeaker(STDIN, STDOUT);
    }

    public function approveSolver(string $solverPath, string $solverCode): bool
    {
        fwrite(STDOUT, "\n--- {$solverPath} ---\n{$solverCode}\n--- end ---\n\n");

        return $this->confirm('Run this workflow now?');
    }

    public function report(string $message, bool $isError): void
    {
        $isError ? fwrite(STDERR, "claw run: {$message}\n") : fwrite(STDOUT, "{$message}\n");
    }

    public function liveSink(): TraceSinkInterface
    {
        return new ConsoleTraceSink(STDERR, $this->verbosity ?? Level::Info);
    }

    /** Ask a yes/no question on the console; true only on an explicit yes. */
    private function confirm(string $question): bool
    {
        fwrite(STDOUT, "{$question} [y/N] ");
        $line = fgets(STDIN);

        return $line !== false && \in_array(strtolower(trim($line)), ['y', 'yes'], true);
    }
}
