<?php

declare(strict_types=1);

namespace Claw\Workflow;

/** Status of a whole workflow run, used by the recovery scan at startup. */
enum WorkflowStatus
{
    case Running;       // resume automatically on restart
    case WaitingHuman;  // do not resume; restore the wait
    case Done;
    case Aborted;
}
