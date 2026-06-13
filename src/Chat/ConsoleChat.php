<?php

declare(strict_types=1);

namespace Claw\Chat;

/**
 * Terminal gateway: a single conversation on stdin/stdout. No token required —
 * useful for development and the tutorial.
 */
final class ConsoleChat implements ChatInterface
{
    public function accept(): ConversationInterface
    {
        return new AsyncConsoleConversation();
    }
}
