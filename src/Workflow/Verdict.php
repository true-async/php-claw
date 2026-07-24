<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * A critic's structured verdict on a step's work. Three outcomes, not two: with only OK-or-prose,
 * "I could not check this" had nowhere to go but the reject lane, where it drove a rework of work
 * nobody had actually faulted. A reject carries findings that must name what rule was broken and
 * what was observed (the verdict tool refuses one without them); a cannot-verify carries why
 * checking failed, and the step loop puts it to the supervisor as a verification failure to settle —
 * or, with no one on the channel, re-runs the step on the reason, since recording the missing
 * evidence is the one fix the step itself can make.
 */
final class Verdict
{
    public const string ACCEPT = 'accept';

    public const string REJECT = 'reject';

    public const string CANNOT_VERIFY = 'cannot_verify';

    private function __construct(
        public readonly string $decision,   // one of the three constants
        public readonly string $findings,   // '' on accept; reject: rule + fact; cannot_verify: the reason
    ) {
    }

    public static function accept(): self
    {
        return new self(self::ACCEPT, '');
    }

    public static function reject(string $findings): self
    {
        return new self(self::REJECT, $findings);
    }

    public static function cannotVerify(string $reason): self
    {
        return new self(self::CANNOT_VERIFY, $reason);
    }

    public function passes(): bool
    {
        return $this->decision === self::ACCEPT;
    }
}
