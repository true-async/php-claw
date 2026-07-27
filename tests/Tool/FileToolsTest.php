<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\ReadFileTool;
use Claw\Tool\Risk;
use Claw\Tool\Workspace;
use Claw\Tool\WriteFileTool;
use Testo\Assert;
use Testo\Test;

final class FileToolsTest
{
    #[Test]
    public function writeThenRead(): void
    {
        $workspace = $this->workspace();
        $write = new WriteFileTool($workspace);
        $read = new ReadFileTool($workspace);

        $result = $write->handle(['path' => 'note.txt', 'content' => 'hello']);
        Assert::true(str_contains($result, '5 bytes'));
        Assert::same($read->handle(['path' => 'note.txt']), 'hello');

        Assert::same($read->risk(), Risk::Safe);
        Assert::same($write->risk(), Risk::Mutating);

        $this->cleanup($workspace->root());
    }

    #[Test]
    public function readTruncatesLargeFiles(): void
    {
        $workspace = $this->workspace();
        file_put_contents($workspace->root() . '/big.txt', str_repeat('a', 50));

        $read = new ReadFileTool($workspace, 10);
        $out = $read->handle(['path' => 'big.txt']);

        Assert::same(substr($out, 0, 10), str_repeat('a', 10));
        Assert::true(str_contains($out, '[truncated'));   // truncation is signalled (now with a windowing hint)

        $this->cleanup($workspace->root());
    }

    #[Test]
    public function rejectsTraversalAndMissingPath(): void
    {
        $workspace = $this->workspace();
        $read = new ReadFileTool($workspace);

        $this->assertToolError(fn () => $read->handle(['path' => '../../etc/passwd']));
        $this->assertToolError(fn () => $read->handle([]));

        $this->cleanup($workspace->root());
    }

    private function workspace(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_ws_' . bin2hex(random_bytes(5));
        mkdir($dir);

        return new Workspace($dir);
    }

    private function assertToolError(callable $fn): void
    {
        $threw = false;

        try {
            $fn();
        } catch (ToolException $e) {
            $threw = true;
        }

        Assert::true($threw);
    }

    private function cleanup(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = "{$dir}/{$entry}";
            is_dir($path) ? $this->cleanup($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
