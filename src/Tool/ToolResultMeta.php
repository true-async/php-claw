<?php

declare(strict_types=1);

namespace Claw\Tool;

/**
 * A tool's own structured report about the execution it just performed — the machine-readable
 * companion of the text it returned. This is how an artifact gets graded without anyone parsing
 * the artifact: the tool KNOWS its exit code and what program it ran, so the tool declares it.
 *
 * `status` is factual (the exit), never a judgement. `producer` names the recognized program
 * ('phpunit', 'php-lint', …) so a viewer can badge the output; `summary` is the producer's own
 * verdict line, lifted by the tool that ran it — the one party entitled to read its output.
 */
final readonly class ToolResultMeta
{
    public function __construct(
        public string $status,          // 'ok' | 'fail'
        public string $producer = '',   // recognized program id, e.g. 'phpunit'; '' when unknown
        public string $summary = '',    // the producer's verdict line, e.g. 'OK (3 tests, 5 assertions)'
    ) {
    }
}
