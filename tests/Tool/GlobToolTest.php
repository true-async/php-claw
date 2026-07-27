<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\Effect;
use Claw\Tool\GlobTool;
use Claw\Tool\Workspace;
use Testo\Assert;
use Testo\Test;

final class GlobToolTest
{
    #[Test]
    public function starStaysWithinASegment(): void
    {
        $ws = $this->workspace();
        mkdir($ws->root() . '/src');
        file_put_contents($ws->root() . '/src/A.php', 'x');
        file_put_contents($ws->root() . '/src/B.php', 'y');
        file_put_contents($ws->root() . '/README.md', 'z');

        $out = new GlobTool($ws)->handle(['pattern' => 'src/*.php']);

        Assert::true(str_contains($out, 'src/A.php'));
        Assert::true(str_contains($out, 'src/B.php'));
        Assert::false(str_contains($out, 'README.md'));

        $this->cleanup($ws->root());
    }

    /** "**\/" matches zero OR more leading directories, so a top-level file matches too. */
    #[Test]
    public function doubleStarCrossesDirectoriesAndMatchesZero(): void
    {
        $ws = $this->workspace();
        mkdir($ws->root() . '/a');
        mkdir($ws->root() . '/a/b');
        file_put_contents($ws->root() . '/a/b/Deep.php', 'x');
        file_put_contents($ws->root() . '/top.php', 'y');
        file_put_contents($ws->root() . '/note.md', 'z');

        $out = new GlobTool($ws)->handle(['pattern' => '**/*.php']);

        Assert::true(str_contains($out, 'a/b/Deep.php'));
        Assert::true(str_contains($out, 'top.php'));
        Assert::false(str_contains($out, 'note.md'));

        $this->cleanup($ws->root());
    }

    #[Test]
    public function noMatchSaysSoAndItReadsOnly(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/a.php', 'x');

        Assert::same(new GlobTool($ws)->handle(['pattern' => '*.rs']), 'no files match *.rs');
        Assert::same(new GlobTool($ws)->effects(), [Effect::Read]);

        $this->cleanup($ws->root());
    }

    #[Test]
    public function anEmptyPatternIsAToolError(): void
    {
        $ws = $this->workspace();
        $threw = false;

        try {
            new GlobTool($ws)->handle(['pattern' => '']);
        } catch (ToolException) {
            $threw = true;
        }

        Assert::true($threw);

        $this->cleanup($ws->root());
    }

    private function workspace(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_glob_' . bin2hex(random_bytes(5));
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
