<?php

declare(strict_types=1);

namespace Claw\Workflow;

/** Lifecycle state of a single step, persisted so a run can be resumed. */
enum StepStatus
{
    case Pending;
    case Running;
    case WaitingHuman;
    case Done;
    case Aborted;
}
