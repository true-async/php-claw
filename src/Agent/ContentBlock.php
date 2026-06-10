<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * Marker for a piece of message content. Provider-neutral; the agent
 * implementation maps each concrete block to its wire format.
 */
interface ContentBlock
{
}
