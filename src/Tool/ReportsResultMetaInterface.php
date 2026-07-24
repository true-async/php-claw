<?php

declare(strict_types=1);

namespace Claw\Tool;

/**
 * A tool that can be ASKED about the execution it just performed. The executor's terminal calls
 * {@see resultMeta()} immediately after {@see ToolInterface::handle()}, in the same call frame,
 * and attaches the answer to the result envelope — so any consumer downstream (the artifact
 * recorder, the dashboard) reads structured facts instead of re-parsing the tool's text.
 */
interface ReportsResultMetaInterface
{
    /** The metadata of the most recent handle() call, or null if it reported nothing. */
    public function resultMeta(): ?ToolResultMeta;
}
