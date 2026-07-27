<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Tool\ReadFileTool;
use Claw\Tool\Workspace;
use Testo\Assert;
use Testo\Test;

final class ReadFileToolTest
{
    #[Test]
    public function readsAWindowWithLineNumbers(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/f.txt', "l1\nl2\nl3\nl4\nl5\n");

        $out = new ReadFileTool($ws)->handle(['path' => 'f.txt', 'offset' => 2, 'limit' => 2]);

        Assert::true(str_contains($out, '2→l2'));
        Assert::true(str_contains($out, '3→l3'));
        Assert::false(str_contains($out, 'l1'));
        Assert::false(str_contains($out, 'l4'));

        $this->cleanup($ws->root());
    }

    /** A plain read stays RAW — no line numbers — so its text can be copied verbatim into edit. */
    #[Test]
    public function aPlainReadStaysRaw(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/f.txt', "hello\nworld\n");

        Assert::same(new ReadFileTool($ws)->handle(['path' => 'f.txt']), "hello\nworld\n");

        $this->cleanup($ws->root());
    }

    #[Test]
    public function anOffsetPastTheEndSaysSo(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/f.txt', "only one line\n");

        Assert::true(str_contains(new ReadFileTool($ws)->handle(['path' => 'f.txt', 'offset' => 99]), 'no line 99'));

        $this->cleanup($ws->root());
    }

    private function workspace(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_read_' . bin2hex(random_bytes(5));
        mkdir($dir);

        return new Workspace($dir);
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
