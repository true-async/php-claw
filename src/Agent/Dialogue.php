<?php

declare(strict_types=1);

namespace Claw\Agent;

use function Async\await;

use Async\Channel;

use function Async\spawn;

/**
 * A bounded conversation between two {@see SpeakerInterface}s — the agent-talks-to-agent primitive
 * the supervisor uses instead of stopping to ask a human. Each speaker runs in its own coroutine
 * with its own context and they exchange replies over a pair of channels — the same inbox idiom
 * {@see \Claw\Session} uses for human↔agent, generalised so either side can be an agent, a human,
 * or a function (whoever fills the channel).
 *
 * The opener replies to $opening first, then they alternate for $maxTurns replies total. The budget
 * is split (opener gets the extra turn on an odd count) and the opener goes first, so every blocking
 * recv is always answered — no close handshake, no deadlock.
 */
final class Dialogue
{
    /**
     * @return list<array{from: string, text: string}> the replies, in spoken order
     */
    public static function between(
        SpeakerInterface $opener,
        SpeakerInterface $other,
        string $opening,
        int $maxTurns,
    ): array {
        /** @var Channel<string> $toOpener */
        $toOpener = new Channel(1);
        /** @var Channel<string> $toOther */
        $toOther = new Channel(1);

        /** @var list<array{from: string, text: string}> $transcript */
        $transcript = [];

        // Take the incoming message, reply, hand the reply to the other side — $turns times.
        $pump = static function (SpeakerInterface $speaker, Channel $in, Channel $out, int $turns) use (&$transcript): void {
            for ($i = 0; $i < $turns; $i++) {
                $reply = $speaker->reply((string) $in->recv());
                $transcript[] = ['from' => $speaker->name(), 'text' => $reply];
                $out->send($reply);
            }
        };

        $a = spawn(static function () use ($pump, $opener, $toOpener, $toOther, $maxTurns): void {
            $pump($opener, $toOpener, $toOther, intdiv($maxTurns + 1, 2));   // opener gets the extra turn
        });
        $b = spawn(static function () use ($pump, $other, $toOther, $toOpener, $maxTurns): void {
            $pump($other, $toOther, $toOpener, intdiv($maxTurns, 2));
        });

        $toOpener->send($opening);   // kick off the opener

        await($a);
        await($b);

        return $transcript;
    }
}
