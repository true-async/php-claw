<?php

declare(strict_types=1);

namespace Claw\Exec;

use Claw\Agent\ToolResultBlock;
use Claw\Tool\ToolCall;

/**
 * Composes the middleware onion around a terminal handler. call() wraps the
 * middlewares outer-to-inner; each may short-circuit (skip $next) or pass through
 * to the terminal, which resolves and runs the tool. Adding behaviour = adding a
 * middleware, not editing the loop.
 */
final readonly class ChainExecutor implements ExecutorInterface
{
    /** @var list<MiddlewareInterface> */
    private array $middlewares;

    /** @var \Closure(ToolCall): ToolResultBlock */
    private \Closure $terminal;

    /**
     * @param list<MiddlewareInterface>            $middlewares outer first
     * @param \Closure(ToolCall): ToolResultBlock  $terminal    resolves and runs the tool
     */
    public function __construct(array $middlewares, \Closure $terminal)
    {
        $this->middlewares = $middlewares;
        $this->terminal = $terminal;
    }

    public function call(ToolCall $call): ToolResultBlock
    {
        $next = $this->terminal;
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = static fn (ToolCall $c): ToolResultBlock => $middleware->handle($c, $next);
        }

        return $next($call);
    }
}
