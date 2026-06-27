<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\Workspace;
use Testo\Assert;
use Testo\Test;

final class WorkspaceTest
{
    #[Test]
    public function resolvesPathsInsideTheRoot(): void
    {
        $workspace = $this->workspace();
        $root = $workspace->root();
        file_put_contents($root . '/a.txt', 'x');
        mkdir($root . '/sub');
        file_put_contents($root . '/sub/b.txt', 'y');

        // resolve* returns realpath() output, which is OS-native (\ on Windows),
        // so build the expected paths with DIRECTORY_SEPARATOR rather than '/'.
        $ds = DIRECTORY_SEPARATOR;
        Assert::same($workspace->resolveExisting('a.txt'), $root . $ds . 'a.txt');
        Assert::same($workspace->resolveExisting('sub/b.txt'), $root . $ds . 'sub' . $ds . 'b.txt');
        Assert::same($workspace->resolveExisting('sub/../a.txt'), $root . $ds . 'a.txt'); // .. that stays inside is fine
        Assert::same($workspace->resolveForWrite('new.txt'), $root . $ds . 'new.txt');

        $this->cleanup($root);
    }

    #[Test]
    public function rejectsEscapesAndBadPaths(): void
    {
        $workspace = $this->workspace();

        $this->assertRejected(fn () => $workspace->resolveExisting('../etc/passwd'));
        $this->assertRejected(fn () => $workspace->resolveExisting('/etc/passwd'));
        $this->assertRejected(fn () => $workspace->resolveExisting('missing.txt'));
        $this->assertRejected(fn () => $workspace->resolveExisting(''));
        $this->assertRejected(fn () => $workspace->resolveForWrite('../escape.txt'));

        $this->cleanup($workspace->root());
    }

    private function workspace(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_ws_' . bin2hex(random_bytes(5));
        mkdir($dir);

        return new Workspace($dir);
    }

    private function assertRejected(callable $fn): void
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
