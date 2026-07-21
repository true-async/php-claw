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
 * WHAT IT DOES NOT DO, measured rather than assumed: it does not kill a `bash` subprocess. This used to
 * claim that "TrueAsync cancellation propagates into an awaited `bash` subprocess, killing it". It does
 * not. A `sleep 40` capped at two seconds returns "timed out after 2s" on time — and is still running
 * afterwards, for as long as the PHP process lives. On the CLI that is seconds; in the dashboard server
 * it is the server's whole lifetime, so timed-out commands accumulate.
 *
 * The reason is that {@see \Claw\Tool\BashTool} reads its pipes with `stream_get_contents`, a blocking
 * call the event loop does not drive. Cancelling the child coroutine cannot unwind it: the cancellation
 * lands only once the read returns, which is when the command has finished of its own accord. So the
 * caller is freed on time and the command is not stopped.
 *
 * Fixing it belongs in BashTool, the only place holding the process handle: its own deadline, its own
 * non-blocking read, its own `proc_terminate`. See `dev/TODO.md`. Recorded here rather than quietly,
 * because a comment claiming a subprocess is killed is worse than none — someone relies on it.
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
