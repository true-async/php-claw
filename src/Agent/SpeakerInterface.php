<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * A participant in a {@see Dialogue} or an ask channel: something that, given an incoming message,
 * produces a reply. The source of the next message is interchangeable — another agent
 * ({@see AgentSpeaker}), a human typing ({@see ConsoleSpeaker}), a chain of these
 * ({@see EscalatingSpeaker}), or a plain function.
 */
interface SpeakerInterface
{
    /** Which participant this is — a typed role, not a free string (e.g. {@see SpeakerRole::Human}). */
    public function name(): SpeakerRole;

    /**
     * React to one incoming message and return this speaker's reply, or null to "pass it up" —
     * escalate to the next speaker in a chain (see {@see EscalatingSpeaker}). A terminal speaker
     * (a person) always answers, so it never returns null.
     */
    public function reply(string $incoming): ?string;
}
