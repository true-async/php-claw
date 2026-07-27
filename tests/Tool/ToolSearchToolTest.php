<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Tool\DeferredToolInterface;
use Claw\Tool\Effect;
use Claw\Tool\Registry;
use Claw\Tool\Risk;
use Claw\Tool\ToolInterface;
use Claw\Tool\ToolSearchTool;
use Testo\Assert;
use Testo\Test;

final class ToolSearchToolTest
{
    /** BM25 lets the distinctive terms of a query outweigh the word both tools happen to share. */
    #[Test]
    public function bm25RanksTheDistinctiveMatchFirst(): void
    {
        $palette = new Registry();
        $palette->add($this->deferredTool('schedule', ['schedule', 'later', 'cron', 'run'], 'Run this later on a schedule'));
        $palette->add($this->deferredTool('shell', ['run', 'shell', 'command'], 'Run a command now'));

        $ranked = new ToolSearchTool($palette)->matches('schedule this to run later');

        Assert::same($ranked[0] ?? null, 'schedule');   // schedule/later beat the shared "run"
        Assert::true(\in_array('shell', $ranked, true)); // shell still hits (on "run") — just ranked lower
    }

    #[Test]
    public function aQueryThatHitsNothingReturnsEmpty(): void
    {
        $palette = new Registry();
        $palette->add($this->deferredTool('schedule', ['schedule', 'cron'], 'Run this later'));

        Assert::same(new ToolSearchTool($palette)->matches('quantum entanglement'), []);
    }

    /**
     * @param list<string> $tags
     */
    private function deferredTool(string $name, array $tags, string $description): ToolInterface
    {
        return new class ($name, $tags, $description) implements ToolInterface, DeferredToolInterface {
            /** @param list<string> $tags */
            public function __construct(
                private readonly string $toolName,
                private readonly array $tags,
                private readonly string $desc,
            ) {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return $this->desc;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function searchTags(): array
            {
                return $this->tags;
            }

            public function effects(): array
            {
                return [Effect::Read];
            }

            public function risk(): Risk
            {
                return Risk::Safe;
            }

            public function handle(array $input): string
            {
                return 'ok';
            }
        };
    }
}
