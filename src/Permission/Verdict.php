<?php

declare(strict_types=1);

namespace Claw\Permission;

/**
 * The permission layer's answer for one tool call: a Decision plus, for a Deny,
 * a short reason the model can read in the tool_result.
 */
final readonly class Verdict
{
    public function __construct(
        public Decision $decision,
        public string $reason = '',
    ) {
    }

    public static function allow(): self
    {
        return new self(Decision::Allow);
    }

    public static function confirm(): self
    {
        return new self(Decision::Confirm);
    }

    public static function deny(string $reason): self
    {
        return new self(Decision::Deny, $reason);
    }
}
