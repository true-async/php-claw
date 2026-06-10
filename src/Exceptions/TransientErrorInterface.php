<?php

declare(strict_types=1);

namespace Claw\Exceptions;

/**
 * Marks an agent error as transient — worth retrying. Permanent errors (auth,
 * bad request) do not implement it.
 */
interface TransientErrorInterface
{
}
