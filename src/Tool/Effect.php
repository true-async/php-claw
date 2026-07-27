<?php

declare(strict_types=1);

namespace Claw\Tool;

/**
 * What a tool DOES to the world — so a palette can be formed by CAPABILITY, not by hand-listing names.
 *
 *  - Read  — it only observes: reads files, lists, queries, searches. Nothing it does outlives the call.
 *  - Write — it can change something that outlives the call: the project's files, external state over
 *            the network, a persisted record. A shell that can run `rm` is a Write tool.
 *
 * A tool may declare both (a shell reads AND writes; a knowledge base queries AND records). The effect
 * is what {@see Registry::exceptEffect()} filters on: a reviewer's palette is `exceptEffect(Effect::Write)`,
 * and a Write-capable tool added to the run later is kept out of it automatically, with no revisit — the
 * mistake that hand-listed name subtraction makes silently every time a tool is added.
 */
enum Effect: string
{
    case Read = 'read';
    case Write = 'write';
}
