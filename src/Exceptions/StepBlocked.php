<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * A worker reporting that it cannot carry its step any further, and why.
 *
 * Not an error and not a verdict — a report about the worker's OWN state, which is the one thing in
 * this system nobody else can establish. That is what separates it from the deleted `done`: whether a
 * task is solved is a fact about the project, checkable by someone else, so the worker was the wrong
 * source for it; whether the worker is stuck is not visible from outside at all.
 *
 * It ends the STEP, never the run. The reason travels to the step's critic, which decides whether the
 * blocker is real — and the code decides what happens next. The signal proposes; it does not dispose.
 */
final class StepBlocked extends ClawException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason === '' ? 'the step reported it cannot continue' : $reason);
    }
}
