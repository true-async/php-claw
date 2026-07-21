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
 * Bounds a single tool run: the tool runs in a child coroutine, and if it outlives the deadline the
 * coroutine is cancelled and an error result is returned. Sits innermost (just before the terminal), so
 * a slow tool is capped but the user's approval prompt is not.
 *
 * WHAT IT DOES NOT DO, and this matters for how it is configured: it does not kill a subprocess. It
 * cancels the coroutine, and a coroutine blocked in a read does not unwind — probed by placing a
 * `catch` around that read, which logged nothing at all. So nothing inside the tool is reached, and a
 * `bash` command capped only from here goes on running for as long as the PHP process lives.
 *
 * {@see \Claw\Tool\BashTool} therefore holds its own deadline over a non-blocking read and kills what
 * it started. This is set LOOSER than that one on purpose: firing first would cancel the coroutine
 * before the tool could reach its own process handle, and re-open the leak. It is the backstop for
 * tools that cannot bound themselves, standing behind the ones that can.
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
            // Whatever stopped us — our timeout, a /stop turn cancellation, or a
            // real error — kill the tool coroutine (and its bash) before deciding.
            $coroutine->cancel();   // propagates into a running bash subprocess and kills it

            // Only the timeout is ours; a /stop cancellation or a genuine tool
            // failure propagates rather than being masked as a timeout.
            if ($e::class !== OperationCanceledException::class) {
                throw $e;
            }

            return new ToolResultBlock(
                $call->id,
                'timed out after ' . (int) ceil($this->timeoutMs / 1000) . 's',
                true,
            );
        }
    }
}
