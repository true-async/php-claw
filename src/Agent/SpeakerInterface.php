<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * A participant in a {@see Dialogue}: something that, given an incoming message, produces a reply.
 * The point of the abstraction is that the source of the next message is interchangeable — it could
 * be another agent ({@see AgentSpeaker}), a human typing, or a plain function. A dialogue connects
 * two of these over channels; each keeps its OWN context, so two talking agents are two histories.
 */
interface SpeakerInterface
{
    /** Which participant this is — a typed role, not a free string (e.g. {@see SpeakerRole::Human}). */
    public function name(): SpeakerRole;

    /** React to one incoming message and return this speaker's reply. */
    public function reply(string $incoming): string;
}
