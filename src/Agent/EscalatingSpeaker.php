<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * A {@see SpeakerInterface} that is itself a chain of speakers, ordered cheapest/first to highest
 * authority last — e.g. [supervisor-agent, human]. reply() asks each in turn: the first to return a
 * non-null answer wins; a null means "pass it up", so it moves to the next tier. If every tier
 * passes, the chain returns null too.
 *
 * The escalation ladder lives here, in plain code — whoever asks (the turn loop, {@see
 * \Claw\Workflow\WorkflowAbstract::ask()}) sees one ordinary speaker and never knows there were tiers.
 */
final class EscalatingSpeaker implements SpeakerInterface
{
    /** @var list<SpeakerInterface> the tiers, asked in order (first = entry, last = highest authority) */
    private array $tiers;

    public function __construct(SpeakerInterface $first, SpeakerInterface ...$rest)
    {
        $this->tiers = array_values([$first, ...$rest]);
    }

    /** The chain's front — the tier asked first. */
    public function name(): SpeakerRole
    {
        return $this->tiers[0]->name();
    }

    public function reply(string $incoming): ?string
    {
        foreach ($this->tiers as $tier) {
            $answer = $tier->reply($incoming);
            if ($answer !== null) {
                return $answer;   // this tier handled it
            }
            // null -> pass it up to the next tier
        }

        return null;   // every tier escalated; no one answered
    }
}
