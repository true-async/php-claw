<?php

declare(strict_types=1);

namespace Claw\Journal;

/**
 * One recorded action, carrying WHERE in the hierarchy it happened: the project, the issue,
 * the workflow run, and (when known) the step — so any entry can be read back by level (all
 * of a project, one issue, one run) and you can see that "tool X with params Y ran in run R,
 * under issue I, for project P". `scope` is the level the entry is primarily about; `action`
 * is a short label, `message` a human line, `context` optional structured details.
 */
final readonly class JournalEntry
{
    /** @param array<string, mixed> $context structured details */
    public function __construct(
        public JournalScope $scope,
        public string $action,
        public string $message = '',
        public array $context = [],
        public ?string $project = null,
        public ?string $issue = null,
        public ?string $workflow = null,   // the run id
        public ?int $step = null,          // step position, when at step level
        public ?int $timestamp = null,     // unix ts, stamped by the recorder
    ) {
    }
}
