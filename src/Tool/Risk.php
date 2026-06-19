<?php

declare(strict_types=1);

namespace Claw\Tool;

/**
 * Risk class of a tool, used by the permission layer to decide auto/confirm/deny.
 */
enum Risk
{
    case Safe;
    case Mutating;
    case Dangerous;
}
