<?php

declare(strict_types=1);

namespace Claw\Workflow;

/** Lifecycle of an issue: tracked above the level of a single workflow run. */
enum IssueStatus
{
    case Open;
    case InProgress;
    case WaitingHuman;
    case Done;
    case Closed;
}
