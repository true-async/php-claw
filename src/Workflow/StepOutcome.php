<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * The verdict of a step: what to do next. The critic suggests it via a score, but
 * the workflow's policy code turns the score into one of these.
 */
enum StepOutcome
{
    case Proceed;     // move on to the next step
    case Abort;       // stop the whole workflow
    case AwaitHuman;  // pause and ask a human
    case Retry;       // run this step again
}
