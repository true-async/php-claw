<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\AgentSpeaker;
use Claw\Agent\Dialogue;
use Claw\Agent\Message;
use Claw\Agent\Role;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Claw\Agent\TextBlock;
use Claw\Agent\TurnLoopInterface;
use Claw\Agent\TurnResult;
use Claw\Agent\Usage;
use Testo\Assert;
use Testo\Test;

final class DialogueTest
{
    #[Test]
    public function alternatesCarryingEachReplyToTheOther(): void
    {
        $worker = new ScriptedSpeaker(SpeakerRole::Worker, ['w1', 'w2']);
        $super = new ScriptedSpeaker(SpeakerRole::Supervisor, ['s1', 's2']);

        $transcript = Dialogue::between($worker, $super, 'help', 4);

        Assert::same(
            array_map(static fn (array $t): string => $t['from'] . ':' . $t['text'], $transcript),
            ['worker:w1', 'supervisor:s1', 'worker:w2', 'supervisor:s2'],
        );
        Assert::same($worker->heard, ['help', 's1']);   // each side hears the other's prior reply
        Assert::same($super->heard, ['w1', 'w2']);
    }

    #[Test]
    public function splitsAnOddBudgetGivingTheOpenerTheExtraTurn(): void
    {
        $a = new ScriptedSpeaker(SpeakerRole::Worker, ['a1', 'a2']);
        $b = new ScriptedSpeaker(SpeakerRole::Supervisor, ['b1']);

        $transcript = Dialogue::between($a, $b, 'go', 3);

        Assert::same(array_map(static fn (array $t): string => $t['from'], $transcript), ['worker', 'supervisor', 'worker']);
    }

    #[Test]
    public function agentSpeakerRunsItsLoopAndKeepsItsOwnGrowingContext(): void
    {
        $loop = new class () implements TurnLoopInterface {
            public int $lastHistoryLen = 0;

            public function run(array $history): TurnResult
            {
                $this->lastHistoryLen = \count($history);

                return new TurnResult([...$history, new Message(Role::Assistant, [new TextBlock('ack')])], 'ack', new Usage());
            }
        };
        $speaker = new AgentSpeaker(SpeakerRole::Worker, $loop);

        Assert::same($speaker->reply('hello'), 'ack');
        Assert::same($loop->lastHistoryLen, 1);   // just the incoming user message

        Assert::same($speaker->reply('again'), 'ack');
        Assert::same($loop->lastHistoryLen, 3);   // prior user+assistant carried over, plus the new user
    }
}

final class ScriptedSpeaker implements SpeakerInterface
{
    /** @var list<string> messages this speaker was given, in order */
    public array $heard = [];

    private int $i = 0;

    /** @param list<string> $replies canned replies, returned in order */
    public function __construct(
        private readonly SpeakerRole $name,
        private readonly array $replies,
    ) {
    }

    public function name(): SpeakerRole
    {
        return $this->name;
    }

    public function reply(string $incoming): string
    {
        $this->heard[] = $incoming;

        return $this->replies[$this->i++] ?? '';
    }
}
