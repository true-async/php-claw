<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\ListFilesTool;
use Claw\Tool\Risk;
use Claw\Tool\Workspace;
use Testo\Assert;
use Testo\Test;

final class ListFilesToolTest
{
    #[Test]
    public function listsEntriesWithTypeAndSize(): void
    {
        $workspace = $this->workspace();
        file_put_contents($workspace->root() . '/a.txt', 'hello');   // 5 bytes
        mkdir($workspace->root() . '/sub');

        $tool = new ListFilesTool($workspace);
        $out = $tool->handle([]);   // defaults to "."

        Assert::same($tool->risk(), Risk::Safe);
        Assert::true(str_contains($out, 'a.txt'));
        Assert::true(str_contains($out, '5 bytes'));
        Assert::true(str_contains($out, 'sub/'));

        $this->cleanup($workspace->root());
    }

    #[Test]
    public function listsNamedSubdirectory(): void
    {
        $workspace = $this->workspace();
        mkdir($workspace->root() . '/sub');
        file_put_contents($workspace->root() . '/sub/inner.txt', 'x');

        $out = (new ListFilesTool($workspace))->handle(['path' => 'sub']);

        Assert::true(str_contains($out, 'inner.txt'));

        $this->cleanup($workspace->root());
    }

    #[Test]
    public function rejectsTraversalAndNonDirectory(): void
    {
        $workspace = $this->workspace();
        file_put_contents($workspace->root() . '/a.txt', 'x');
        $tool = new ListFilesTool($workspace);

        $this->assertToolError(fn () => $tool->handle(['path' => '../../etc']));
        $this->assertToolError(fn () => $tool->handle(['path' => 'a.txt']));   // a file, not a dir

        $this->cleanup($workspace->root());
    }

    private function workspace(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_ls_' . bin2hex(random_bytes(5));
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
