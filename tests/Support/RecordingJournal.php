<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Journal\JournalEntry;
use Claw\Journal\JournalInterface;
use Claw\Journal\JournalScope;

/** A fake journal that keeps every recorded entry for inspection. */
final class RecordingJournal implements JournalInterface
{
    /** @var list<JournalEntry> */
    public array $recorded = [];

    public function record(JournalEntry $entry): void
    {
        $this->recorded[] = $entry;
    }

    /** @return list<JournalEntry> */
    public function entries(JournalScope $scope, string $subjectId): array
    {
        $out = [];
        foreach ($this->recorded as $entry) {
            $id = match ($scope) {
                JournalScope::Project => $entry->project,
                JournalScope::Issue => $entry->issue,
                JournalScope::Workflow => $entry->workflow,
                JournalScope::Step => $entry->step === null ? null : (string) $entry->step,
                JournalScope::Task => null,
            };
            if ($id === $subjectId) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
