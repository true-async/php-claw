<?php

declare(strict_types=1);

namespace Claw\Journal;

/**
 * Records actions and history at every level — project, issue, workflow, step,
 * task. Passed into the WorkflowContext so any layer can append to it, and read
 * back per scope. The tool-call audit trail feeds into the same journal.
 */
interface JournalInterface
{
    public function record(JournalEntry $entry): void;

    /**
     * Read the journal for one subject at a given level, oldest first.
     *
     * @return list<JournalEntry>
     */
    public function entries(JournalScope $scope, string $subjectId): array;
}
