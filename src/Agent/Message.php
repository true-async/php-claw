<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * One conversation turn: a role plus a list of content blocks.
 */
final class Message
{
    /**
     * @param list<ContentBlock> $content
     */
    public function __construct(
        public readonly Role $role,
        public readonly array $content,
    ) {
    }

    public static function userText(string $text): self
    {
        return new self(Role::User, [new TextBlock($text)]);
    }
}
