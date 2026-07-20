<?php

declare(strict_types=1);

namespace Claw\Project;

use Claw\Exceptions\ClawException;

/**
 * How a {@see Strategy} chosen for an issue ended. A domain state, so it lives next to the other
 * lifecycle enums ({@see IssueStatus}, {@see RunStatus}) rather than as string constants on the
 * SQLite class — a consumer naming this state must not have to import the storage implementation.
 */
enum StrategyOutcome: string
{
    /** Chosen and not yet contradicted — the verdict currently in force. */
    case Pending = 'pending';

    /** The work could not be carried out this way; the next verdict must escalate past it. */
    case Failed = 'failed';

    /**
     * Resolve a persisted value. Unlike {@see Strategy::parse()}, which reads what a MODEL supplied
     * and can be corrected by it, this reads our own rows: an unknown value is corrupt data, so it
     * fails loud rather than being coerced into a state the issue was never in.
     *
     * @throws ClawException
     */
    public static function stored(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new ClawException("corrupt strategy outcome '{$value}' in the issue ledger");
    }
}
