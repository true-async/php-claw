<?php

declare(strict_types=1);

namespace Tests\Exec;

use Claw\Agent\ToolResultBlock;
use Claw\Exec\ChainExecutor;
use Claw\Exec\MiddlewareInterface;
use Claw\Tool\ToolCall;
use Testo\Assert;
use Testo\Test;

final class ChainExecutorTest
{
    #[Test]
    public function wrapsMiddlewaresOuterToInnerAroundTerminal(): void
    {
        /** @var \ArrayObject<int, string> $trace */
        $trace = new \ArrayObject();

        $tracer = static function (string $tag) use ($trace): MiddlewareInterface {
            return new class ($tag, $trace) implements MiddlewareInterface {
                /**
                 * @param \ArrayObject<int, string> $trace
                 */
                public function __construct(private string $tag, private \ArrayObject $trace)
                {
                }

                public function handle(ToolCall $call, callable $next): ToolResultBlock
                {
                    $this->trace[] = "enter:{$this->tag}";
                    $result = $next($call);
                    $this->trace[] = "exit:{$this->tag}";

                    return $result;
                }
            };
        };

        $terminal = static function (ToolCall $call) use ($trace): ToolResultBlock {
            $trace[] = 'terminal';

            return new ToolResultBlock($call->id, 'done', false);
        };

        $result = (new ChainExecutor([$tracer('A'), $tracer('B')], $terminal))
            ->call(new ToolCall('1', 'x', []));

        Assert::same($result->content, 'done');
        Assert::same((array) $trace, ['enter:A', 'enter:B', 'terminal', 'exit:B', 'exit:A']);
    }

    #[Test]
    public function aMiddlewareCanShortCircuitTheTerminal(): void
    {
        /** @var \ArrayObject<int, int> $terminalRuns */
        $terminalRuns = new \ArrayObject();

        $blocker = new class () implements MiddlewareInterface {
            public function handle(ToolCall $call, callable $next): ToolResultBlock
            {
                return new ToolResultBlock($call->id, 'blocked', true);   // never calls $next
            }
        };

        $terminal = static function (ToolCall $call) use ($terminalRuns): ToolResultBlock {
            $terminalRuns[] = 1;

            return new ToolResultBlock($call->id, 'ran', false);
        };

        $result = (new ChainExecutor([$blocker], $terminal))->call(new ToolCall('1', 'x', []));

        Assert::same($result->content, 'blocked');
        Assert::true($result->isError);
        Assert::same(count($terminalRuns), 0);
    }
}
