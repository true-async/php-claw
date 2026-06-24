<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\EscalatingSpeaker;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Testo\Assert;
use Testo\Test;

final class EscalatingSpeakerTest
{
    #[Test]
    public function escalatesPastTiersThatPassUntilOneAnswers(): void
    {
        $supervisor = new FixedSpeaker(SpeakerRole::Supervisor, null);   // passes it up
        $human = new FixedSpeaker(SpeakerRole::Human, 'do it this way');

        $chain = new EscalatingSpeaker($supervisor, $human);

        Assert::same($chain->reply('stuck, help?'), 'do it this way');
        Assert::same($supervisor->asked, 1);   // tried first
        Assert::same($human->asked, 1);         // then escalated to
    }

    #[Test]
    public function stopsAtTheFirstTierThatAnswers(): void
    {
        $supervisor = new FixedSpeaker(SpeakerRole::Supervisor, 'handled');
        $human = new FixedSpeaker(SpeakerRole::Human, 'never reached');

        $chain = new EscalatingSpeaker($supervisor, $human);

        Assert::same($chain->reply('stuck?'), 'handled');
        Assert::same($human->asked, 0);   // not escalated — the supervisor handled it
    }

    #[Test]
    public function returnsNullWhenEveryTierPassesUp(): void
    {
        $chain = new EscalatingSpeaker(
            new FixedSpeaker(SpeakerRole::Supervisor, null),
            new FixedSpeaker(SpeakerRole::Planner, null),
        );

        Assert::null($chain->reply('anyone?'));
    }

    #[Test]
    public function nameIsTheFrontOfTheChain(): void
    {
        $chain = new EscalatingSpeaker(
            new FixedSpeaker(SpeakerRole::Supervisor, 'x'),
            new FixedSpeaker(SpeakerRole::Human, 'y'),
        );

        Assert::same($chain->name(), SpeakerRole::Supervisor);
    }
}

/** A speaker that always gives the same answer (or null = passes up), counting how often it was asked. */
final class FixedSpeaker implements SpeakerInterface
{
    public int $asked = 0;

    public function __construct(
        private readonly SpeakerRole $role,
        private readonly ?string $answer,
    ) {
    }

    public function name(): SpeakerRole
    {
        return $this->role;
    }

    public function reply(string $incoming): ?string
    {
        $this->asked++;

        return $this->answer;
    }
}
