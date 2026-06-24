<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * A {@see SpeakerInterface} backed by an agent: each reply is one run of its own ReAct turn loop
 * over its OWN evolving history. The incoming message is appended as a user turn, the loop reasons
 * (using tools internally), and its final text is the reply. Two AgentSpeakers in a {@see Dialogue}
 * therefore hold two separate contexts — the model on each side only sees its own side of the talk.
 */
final class AgentSpeaker implements SpeakerInterface
{
    /** @var list<Message> this speaker's private conversation so far */
    private array $history;

    /** @param list<Message> $history optional seed context (e.g. a brief) */
    public function __construct(
        private readonly SpeakerRole $name,
        private readonly TurnLoopInterface $loop,
        array $history = [],
    ) {
        $this->history = $history;
    }

    public function name(): SpeakerRole
    {
        return $this->name;
    }

    public function reply(string $incoming): string
    {
        $this->history[] = Message::userText($incoming);
        $result = $this->loop->run($this->history);
        $this->history = $result->history;   // carry the turn's appended messages into the next reply

        return $result->text ?? '';
    }
}
