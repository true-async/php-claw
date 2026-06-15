<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Lifecycle of a task inside a step, persisted like a step's status. On resume,
 * Done tasks are skipped and only unfinished ones are re-run. We deliberately do
 * not journal effects before they happen: the "effect happened but status not yet
 * written" window stays best-effort.
 */
enum TaskStatus
{
    case Pending;
    case Done;
    case Failed;
}
