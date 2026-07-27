<?php

declare(strict_types=1);

namespace Claw\Tool;

/**
 * A tool that CAN write but can hand back a read-only version of itself, so a reviewer's palette
 * ({@see Registry::exceptEffect()}) need not drop it wholesale. bash is the archetype: a shell writes
 * as readily as it reads, yet a read-only shell still runs the tests a critic needs to judge. After the
 * write-capable tools are removed, a tool implementing this is re-added in its read-only form.
 */
interface ReadOnlyVariantInterface
{
    /** A variant of this tool with its write capability removed (it declares {@see Effect::Read} only). */
    public function readOnly(): ToolInterface;
}
