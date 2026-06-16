<?php

declare(strict_types=1);

namespace Claw\Journal;

/**
 * One recorded action. Bound to a scope (project / issue / workflow / step / task)
 * and the id of that subject, so the journal can be read by level. Carries a short
 * action label, a human message, and optional structured context.
 */
final readonly class JournalEntry
{
    /** @param array<string, mixed> $context structured details */
    public function __construct(
        public JournalScope $scope,
        public string $subjectId,
        public string $action,
        public string $message = '',
        public array $context = [],
        public ?int $timestamp = null,   // unix ts, stamped by the recorder
    ) {
    }
}
