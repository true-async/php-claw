<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Tool\AgentToolInterface;

/** A stub agent-as-tool: a StubTool that is also an agent, so it resolves on the agent path. */
final class StubAgentTool extends StubTool implements AgentToolInterface
{
}
