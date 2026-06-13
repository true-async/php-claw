<?php

declare(strict_types=1);

namespace Tests\Support;

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

    public function __construct(string ...$messages)
    {
        $this->incoming = [...$messages, null];   // close after the scripted messages
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
