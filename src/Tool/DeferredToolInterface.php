<?php

declare(strict_types=1);

namespace Claw\Tool;

/**
 * Marks a tool as DEFERRED: an occasional capability whose full input schema is withheld from the model's
 * context until it is asked for. A deferred tool is named in the briefing but is NOT callable until the
 * model reaches it with `search_tools`, which matches a query against these tags, the name and the
 * description and loads the winners. A tool that does NOT implement this is always fully present — the
 * common ones (read/write files, run commands, record artifacts) never wait to be loaded.
 *
 * Implementing this interface is the ONLY thing that makes a tool deferred; adding a new occasional tool
 * to the shelf is "implement DeferredToolInterface and name your tags", nothing more.
 *
 * @see \Claw\Tool\ToolSearchTool
 * @see \Claw\Agent\DefaultTurnLoop
 */
interface DeferredToolInterface
{
    /**
     * Search tags — the words a model's INTENT would use to reach for this tool ("network", "http",
     * "fetch" for an HTTP tool). Matched alongside the tool's name and description by {@see ToolSearchTool}.
     * Named searchTags rather than tags because a tool may already have a `tags()` of its own domain
     * (KnowledgeTool lists note tags).
     *
     * @return list<string>
     */
    public function searchTags(): array;
}
