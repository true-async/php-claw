<?php

declare(strict_types=1);

namespace Claw\Permission;

use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;

/**
 * The gatekeeper: plain, deterministic code that decides whether a tool call may
 * run — deliberately NOT an AI, so it can't be talked out of a rule by prompt
 * injection. Ordered checks, first decisive wins:
 *
 *   1. Denylist — hard rules (rm -rf /, fork bombs, …) → always Deny.
 *   2. Risk     — Safe → Allow, Mutating → Confirm (ask the human), Dangerous → Deny.
 *
 * Persisted allow/deny rules and an audit trail are a later layer (SQLite).
 */
final class Policy
{
    /** Hard-blocked command substrings (matched case-insensitively). */
    private const array DENYLIST = [
        'rm -rf /',
        'rm -rf /*',
        ':(){',          // fork bomb
        'mkfs',
        'dd if=',
        '> /dev/sd',
    ];

    /**
     * @param array<string, mixed> $input
     */
    public function check(ToolInterface $tool, array $input): Verdict
    {
        $command = isset($input['command']) && \is_string($input['command']) ? $input['command'] : '';
        if ($command !== '' && $this->isDenied($command)) {
            return Verdict::deny('command matches a hard-blocked pattern');
        }

        return match ($tool->risk()) {
            Risk::Safe      => Verdict::allow(),
            Risk::Mutating  => Verdict::confirm(),
            Risk::Dangerous => Verdict::deny('dangerous tools are disabled'),
        };
    }

    private function isDenied(string $command): bool
    {
        $haystack = strtolower($command);
        foreach (self::DENYLIST as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
