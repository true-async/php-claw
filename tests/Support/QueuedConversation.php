<?php

declare(strict_types=1);

namespace Tests\Support;

use function Async\delay;

use Claw\Chat\Approval;
use Claw\Chat\ConversationInterface;
use Claw\Chat\Status;

/**
 * A conversation the test drives by hand: deliver() queues a message, close() ends
 * it. receive() polls (yielding to the reactor) so spawned turns run, letting tests
 * control timing — e.g. wait for a turn to be running before sending "/stop".
 */
final class QueuedConversation implements ConversationInterface
{
    /** @var list<string> */
    private array $incoming = [];

    private bool $closed = false;

    /** @var list<string> Replies captured for assertions. */
    public array $sent = [];

    public function deliver(string $message): void
    {
        $this->incoming[] = $message;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function id(): string
    {
        return 'test';
    }

    public function receive(): ?string
    {
        while ($this->incoming === [] && !$this->closed) {
            delay(5);
        }

        return $this->incoming === [] ? null : array_shift($this->incoming);
    }

    public function send(string $text): void
    {
        $this->sent[] = $text;
    }

    /**
     * @param list<string> $messages
     */
    public function showDeferred(array $messages): void
    {
    }

    public function flushDeferred(): void
    {
    }

    public function confirm(string $prompt): Approval
    {
        return Approval::No;
    }

    public function updateStatus(?Status $status): void
    {
    }
}
