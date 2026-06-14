<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Chat\Approval;
use Claw\Chat\ConversationInterface;
use Claw\Chat\Status;

/**
 * Returns scripted messages then null (auto-closes), and captures replies — lets
 * Session::run() be tested without the reactor.
 */
final class FakeConversation implements ConversationInterface
{
    /** @var array<array-key, string|null> */
    private array $incoming;

    /** @var list<string> */
    public array $sent = [];

    /** @var list<Approval> Queued answers for confirm(); empty defaults to Once. */
    public array $confirmReplies = [];

    /** @var list<string> Prompts seen by confirm(). */
    public array $confirmed = [];

    public function __construct(string ...$messages)
    {
        $this->incoming = [...$messages, null];   // close after the scripted messages
    }

    public function confirm(string $prompt): Approval
    {
        $this->confirmed[] = $prompt;

        return array_shift($this->confirmReplies) ?? Approval::Once;
    }

    public function receive(): ?string
    {
        return array_shift($this->incoming);
    }

    public function send(string $text): void
    {
        $this->sent[] = $text;
    }

    public function updateStatus(?Status $status): void
    {
    }
}
