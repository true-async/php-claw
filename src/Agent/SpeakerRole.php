<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * Who a {@see SpeakerInterface} is — the catalog of participant roles, so a speaker's identity is a
 * typed value, not a magic string scattered through transcripts and traces. The agent roles
 * (worker / reviewer / supervisor / planner) match the strings used for {@see \Claw\Config::$agents}
 * and the `agent:` argument of `ai()`, so the same vocabulary can later back those too; Human is the
 * person on the other end of an ask channel.
 */
enum SpeakerRole: string
{
    case Worker = 'worker';
    case Reviewer = 'reviewer';
    case Supervisor = 'supervisor';
    case Planner = 'planner';
    case Human = 'human';
}
