<?php

declare(strict_types=1);

namespace Claw\Exec;

use function Async\await;

use Async\OperationCanceledException;

use function Async\spawn;
use function Async\timeout;

use Claw\Agent\ToolResultBlock;
use Claw\Tool\ToolCall;

/**
 * Bounds a single tool run. The tool runs in a child coroutine; if it outlives
 * the deadline the coroutine is cancelled — and TrueAsync cancellation propagates
 * into an awaited `bash` subprocess, killing it — and an error result is returned.
 *
 * Sits innermost (just before the terminal), so a slow tool is capped but the
 * user's approval prompt is not.
 */
final readonly class TimeoutMiddleware implements MiddlewareInterface
{
    public function __construct(private int $timeoutMs)
    {
    }

    public function handle(ToolCall $call, callable $next): ToolResultBlock
    {
        $coroutine = spawn(static fn (): ToolResultBlock => $next($call));

        try {
            return await($coroutine, timeout($this->timeoutMs));
        } catch (\Throwable $e) {
            // Only the timeout cancellation is ours; a genuine tool failure
            // propagates rather than being masked as a timeout.
            if ($e::class !== OperationCanceledException::class) {
                throw $e;
            }

            $coroutine->cancel();   // propagates into a running bash subprocess and kills it

            return new ToolResultBlock(
                $call->id,
                'timed out after ' . (int) ceil($this->timeoutMs / 1000) . 's',
                true,
            );
        }
    }
}
