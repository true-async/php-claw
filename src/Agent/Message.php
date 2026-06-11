<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * One conversation turn: a role plus a list of content blocks.
 */
final readonly class Message
{
    /**
     * @param list<ContentBlockInterface> $content
     */
    public function __construct(
        public Role  $role,
        public array $content,
    ) {
    }

    public static function userText(string $text): self
    {
        return new self(Role::User, [new TextBlock($text)]);
    }
}
