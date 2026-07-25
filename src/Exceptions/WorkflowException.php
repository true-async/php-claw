<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * Thrown when a workflow fails to load, validate, or execute. The runner turns
 * this into an error result, the same way a ToolException is handled.
 *
 * Some of these are not failures at all. A run that stops because its budget is spent, or because
 * the supervisor said `stop`, or because a step exhausted its review rounds with nobody to escalate
 * to, has done exactly what it was told to do — the decision was taken deliberately, by a gate that
 * works. {@see $deliberate} marks those, and the run path reads it to answer one question: is this
 * the workflow's CODE being broken?
 *
 * Without the mark the answer was always yes, so the supervisor was sent to rewrite the class it had
 * itself just stopped, and a run that halted for overspending paid for a repair pass whose own model
 * call hit the same exhausted budget.
 */
final class WorkflowException extends ClawException
{
    public function __construct(
        string $message,
        public readonly bool $deliberate = false,
        public readonly bool $budget = false,
    ) {
        parent::__construct($message);
    }

    /** A run halted ON PURPOSE: the code is fine and there is nothing to repair. */
    public static function stopped(string $message): self
    {
        return new self($message, deliberate: true);
    }

    /**
     * A run halted because its TOTAL budget is spent — a deliberate, RESUMABLE stop that is not a failure.
     * The run path turns it into a WaitingHuman pause the operator settles by raising the limit, rather
     * than a failed run handed back to triage. {@see $budget} tells it apart from the other deliberate
     * stops (a supervisor `stop`, review rounds exhausted), which are genuine dead ends for the ticket.
     */
    public static function budgetSpent(string $message): self
    {
        return new self($message, deliberate: true, budget: true);
    }
}
