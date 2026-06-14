<?php

declare(strict_types=1);

namespace Claw\Exec;

use Claw\Agent\ToolResultBlock;
use Claw\Store\SessionStore;
use Claw\Tool\ToolCall;

/**
 * Records every tool call — including blocked and denied ones — to the audit log.
 * Sits outermost in the chain so it captures the verdict produced by the inner
 * stages. A no-op when no store is configured (e.g. the in-memory tests).
 */
final readonly class AuditMiddleware implements MiddlewareInterface
{
    public function __construct(private ?SessionStore $store = null)
    {
    }

    public function handle(ToolCall $call, callable $next): ToolResultBlock
    {
        $result = $next($call);

        $this->store?->logToolCall($call->summary(), $result->content, $result->isError);

        return $result;
    }
}
