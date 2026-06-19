<?php

declare(strict_types=1);

namespace Claw\Project;

/** Lifecycle of an issue: tracked above the level of a single workflow run. */
enum IssueStatus
{
    case Open;
    case InProgress;
    case WaitingHuman;
    case Done;
    case Closed;

    /** Resolve a case by its name (how the status round-trips through the project db). */
    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return self::Open;
    }
}
