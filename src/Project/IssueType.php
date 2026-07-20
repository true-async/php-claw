<?php

declare(strict_types=1);

namespace Claw\Project;

use Claw\Exceptions\ClawException;

/**
 * What KIND of work a ticket is — the shape of the job, not how much machinery it deserves.
 *
 * It is deliberately separate from {@see Strategy}, and the two answer different questions. The type
 * is a property of the TICKET and does not change: a bug is still a bug after the cheap attempt at it
 * failed. The strategy is the verdict on one ATTEMPT, and a retry is required to change it — which is
 * why the verdicts live in their own ledger with an escalation rule while this is a single column.
 *
 * The cases are the ones whose PROCEDURE differs. That is the whole test for adding another: a bug is
 * its own type because the work has a fixed shape (reproduce, fail a test, fix, watch it go green)
 * that no other kind shares, and {@see Refactor} is separate from {@see Feature} because it has no
 * acceptance criteria at all — its gate is that the existing tests still pass. Two kinds of work that
 * would be done the same way do not earn two types.
 *
 * A ticket has no type until the ProjectManager gives it one: creation stays instant and cannot fail
 * on a model call, exactly as it carries no strategy until it is triaged.
 */
enum IssueType: string
{
    /** Something is broken. The procedure is fixed: reproduce it, pin it with a failing test, fix, go green. */
    case Bug = 'bug';

    /** New behaviour someone asked for. The only type whose procedure genuinely varies with the task. */
    case Feature = 'feature';

    /** Change the shape of the code, not what it does. No acceptance criteria — the existing tests are the gate. */
    case Refactor = 'refactor';

    /** Decide how something should be built. Produces a document and a decision, and commits no code. */
    case Design = 'design';

    /** Find something out — read the code, the docs, the web — and report. Changes nothing. */
    case Research = 'research';

    /** Mechanical upkeep with a rigid procedure: a dependency bump, a codemod, a rename, docs, coverage. */
    case Chore = 'chore';

    /**
     * Parse a type a model supplied. Unknown input is an error rather than a silent default, for the
     * reason {@see Strategy::parse()} gives: quietly resolving a typo would hide the classification
     * this type exists to make visible, and the thrower comes back as a tool result the model corrects.
     *
     * @throws ClawException
     */
    public static function parse(string $value): self
    {
        $type = self::tryFrom(strtolower(trim($value)));

        if ($type === null) {
            throw new ClawException(
                "unknown issue type '{$value}', expected one of: " . implode(', ', array_column(self::cases(), 'value')),
            );
        }

        return $type;
    }

    /**
     * Resolve a persisted value, treating the empty string as "not yet typed" — how an issue opened
     * before triage, or before this column existed, reads back. Anything else unrecognized is corrupt
     * data and fails loud rather than being coerced into some default kind of work.
     *
     * @throws ClawException
     */
    public static function stored(string $value): ?self
    {
        if ($value === '') {
            return null;
        }

        return self::tryFrom($value)
            ?? throw new ClawException("corrupt issue type '{$value}' in the issue ledger");
    }
}
