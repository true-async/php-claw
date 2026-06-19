<?php

declare(strict_types=1);

namespace Claw\Tool;

/**
 * An agent exposed as a callable building block. It is a {@see ToolInterface} — invoked
 * with input, returns text — but a DISTINCT type, so the system can tell an agent apart
 * from a plain (deterministic) tool: resolve it through its own path ({@see Registry::agents()}),
 * journal its invocation as an agent action rather than a tool call, and budget its tokens.
 *
 * The marker carries no extra methods; "this is an agent" is the whole point.
 */
interface AgentToolInterface extends ToolInterface
{
}
