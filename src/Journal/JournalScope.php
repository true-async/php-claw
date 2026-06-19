<?php

declare(strict_types=1);

namespace Claw\Journal;

/**
 * The level an entry belongs to, so the journal can be sliced and read per scope:
 * a whole project, one issue, a workflow run, a step, or a task.
 */
enum JournalScope
{
    case Project;
    case Issue;
    case Workflow;
    case Step;
    case Task;
}
