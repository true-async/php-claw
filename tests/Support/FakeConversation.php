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

    public function id(): string
    {
        return 'test';
    }

    public function confirm(string $prompt): Approval
    {
        $this->confirmed[] = $prompt;

        return array_shift($this->confirmReplies) ?? Approval::Once;
    }

    public function receive(): ?string
    {
        // Yield so a turn spawned by the previous message can run before we hand
        // over the next one — mirroring a real channel where input arrives between
        // turns rather than all at once.
        \Async\delay(1);

        return array_shift($this->incoming);
    }

    public function send(string $text): void
    {
        $this->sent[] = $text;
    }

    /** @var list<string> The deferred messages shown so far (captured for tests). */
    public array $deferred = [];

    public function showDeferred(string $message): void
    {
        $this->deferred[] = $message;
    }

    public function flushDeferred(): void
    {
        $this->deferred = [];
    }

    public function updateStatus(?Status $status): void
    {
    }
}
