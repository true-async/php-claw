<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\BashTool;
use Claw\Tool\DiffTool;
use Claw\Tool\Workspace;
use Testo\Assert;
use Testo\Test;

final class DiffToolTest
{
    #[Test]
    public function showsTrackedChangesAndUntrackedFiles(): void
    {
        $ws = $this->gitRepo();
        file_put_contents($ws->root() . '/a.txt', "one\nTWO\n");        // modify a committed file
        file_put_contents($ws->root() . '/new.txt', "brand new\n");     // add an untracked file

        $out = new DiffTool($ws)->handle([]);

        Assert::true(str_contains($out, 'TWO'));         // the tracked change appears in the diff
        Assert::true(str_contains($out, 'new.txt'));     // the untracked file is listed

        $this->cleanup($ws->root());
    }

    #[Test]
    public function aCleanTreeReportsNoChanges(): void
    {
        $ws = $this->gitRepo();

        Assert::same(new DiffTool($ws)->handle([]), '(no changes in the working tree)');

        $this->cleanup($ws->root());
    }

    #[Test]
    public function aNonGitProjectIsAToolError(): void
    {
        $dir = sys_get_temp_dir() . '/claw_diff_' . bin2hex(random_bytes(5));
        mkdir($dir);
        $ws = new Workspace($dir);

        $threw = false;

        try {
            new DiffTool($ws)->handle([]);
        } catch (ToolException $e) {
            $threw = str_contains($e->getMessage(), 'not a git repository');
        }

        Assert::true($threw);

        $this->cleanup($dir);
    }

    /** A temp workspace that is a git repo with one committed file. */
    private function gitRepo(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_diff_' . bin2hex(random_bytes(5));
        mkdir($dir);
        $bash = new BashTool($dir);
        $bash->handle(['command' => 'git init -q && git config user.email t@example.com && git config user.name tester']);
        file_put_contents($dir . '/a.txt', "one\ntwo\n");
        $bash->handle(['command' => 'git add a.txt && git commit -qm init']);

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
