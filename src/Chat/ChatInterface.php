<?php

declare(strict_types=1);

namespace Claw\Chat;

/**
 * A messaging gateway (Telegram now, WhatsApp later). Abstraction over the
 * messenger: it answers "a new chat started" and hands back a Conversation to
 * talk to that one chat. Demultiplexing many chats over one connection is the
 * gateway's internal concern.
 */
interface ChatInterface
{
    /** Await the next new conversation. One Session is spawned per Conversation. */
    public function accept(): ConversationInterface;
}
