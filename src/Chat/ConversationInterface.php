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
    /** A stable identifier for this conversation, used to key its persistence (the store file). */
    public function id(): string;

    /** Next message from this chat, or null when the conversation is closed. May await. */
    public function receive(): ?string;

    public function send(string $text): void;

    /**
     * Register a handler invoked when the user asks to interrupt the running turn
     * (e.g. sends "/stop"). The command is consumed by the channel, not delivered
     * as a normal message.
     */
    public function onInterrupt(callable $handler): void;

    /**
     * Ask the human to approve an action. May await. Used by the permission
     * layer before running a Mutating tool. A closed conversation (EOF) is
     * treated as a refusal (Approval::No).
     */
    public function confirm(string $prompt): Approval;

    /**
     * Show a transient status line (typing indicator, tool call, token usage).
     * Pass null to clear it. The status must never interleave with send() output —
     * implementations are responsible for clearing before writing permanent lines.
     */
    public function updateStatus(?Status $status): void;
}
