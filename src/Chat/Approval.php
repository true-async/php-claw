<?php

declare(strict_types=1);

namespace Claw\Chat;

/**
 * The human's answer to a confirm() prompt:
 *
 *   Once   — allow this one time.
 *   Always — allow and remember it (the permission layer persists a rule).
 *   No     — refuse.
 */
enum Approval
{
    case Once;
    case Always;
    case No;

    /** Map a typed line ("y" / "a" / anything else) to an answer. */
    public static function fromInput(string $line): self
    {
        return match (strtolower(trim($line))) {
            'y', 'yes'    => self::Once,
            'a', 'always' => self::Always,
            default       => self::No,
        };
    }
}
