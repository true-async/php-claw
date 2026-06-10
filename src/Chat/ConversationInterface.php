<?php

declare(strict_types=1);

namespace Claw\Chat;

/**
 * One ongoing dialogue with one human, already bound to that chat — so no
 * chatId is needed. The Session talks only to this. Like a socket connection
 * returned by accept(): recv / send.
 */
interface ConversationInterface
{
    /** Next message from this chat, or null when the conversation is closed. May await. */
    public function receive(): ?string;

    public function send(string $text): void;
}
