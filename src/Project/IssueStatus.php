<?php

declare(strict_types=1);

namespace Claw\Project;

use Claw\Exceptions\ClawException;

/** Lifecycle of an issue: tracked above the level of a single workflow run. */
enum IssueStatus
{
    case Open;
    case InProgress;
    case WaitingHuman;
    case Done;
    case Closed;

    /**
     * Resolve a case by its name (how the status round-trips through the project db). An unrecognized
     * name is corrupt/foreign persisted data — fail loud rather than silently re-Open the issue (which
     * would let a finished issue be re-run).
     *
     * @throws ClawException
     */
    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        throw new ClawException("unknown issue status '{$name}'");
    }
}
