<?php

declare(strict_types=1);

namespace Claw\Permission;

/**
 * What the permission layer decided about a tool call.
 *
 *   Allow   — run it, no questions.
 *   Confirm — ask the human first.
 *   Deny    — never run it; tell the model why.
 */
enum Decision
{
    case Allow;
    case Confirm;
    case Deny;
}
