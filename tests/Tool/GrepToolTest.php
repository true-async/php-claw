<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\Effect;
use Claw\Tool\GrepTool;
use Claw\Tool\Workspace;
use Testo\Assert;
use Testo\Test;

final class GrepToolTest
{
    #[Test]
    public function findsMatchingLinesAsPathLineText(): void
    {
        $ws = $this->workspace();
        mkdir($ws->root() . '/src');
        file_put_contents($ws->root() . '/src/A.php', "<?php\nfunction foo() {}\n");
        file_put_contents($ws->root() . '/src/B.php', "<?php\nclass Bar {}\n");

        $out = new GrepTool($ws)->handle(['pattern' => 'function foo']);

        Assert::true(str_contains($out, 'src/A.php:2:'));
        Assert::true(str_contains($out, 'function foo'));
        Assert::false(str_contains($out, 'B.php'));

        $this->cleanup($ws->root());
    }

    #[Test]
    public function theGlobFilterLimitsWhichFilesAreSearched(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/keep.php', "needle\n");
        file_put_contents($ws->root() . '/skip.txt', "needle\n");

        $out = new GrepTool($ws)->handle(['pattern' => 'needle', 'glob' => '*.php']);

        Assert::true(str_contains($out, 'keep.php'));
        Assert::false(str_contains($out, 'skip.txt'));

        $this->cleanup($ws->root());
    }

    /** A search must never read a credential file — the workspace secret guard applies to grep too. */
    #[Test]
    public function aSecretFileIsNeverSearched(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/.env', "SECRET=needle\n");

        $out = new GrepTool($ws)->handle(['pattern' => 'needle']);

        Assert::same($out, 'no matches for /needle/');

        $this->cleanup($ws->root());
    }

    /** The dependency tree swamps real code and is skipped, like every mature grep. */
    #[Test]
    public function theDependencyTreeIsSkipped(): void
    {
        $ws = $this->workspace();
        mkdir($ws->root() . '/vendor');
        file_put_contents($ws->root() . '/vendor/dep.php', "needle\n");
        file_put_contents($ws->root() . '/app.php', "needle\n");

        $out = new GrepTool($ws)->handle(['pattern' => 'needle']);

        Assert::true(str_contains($out, 'app.php'));
        Assert::false(str_contains($out, 'vendor'));

        $this->cleanup($ws->root());
    }

    #[Test]
    public function anInvalidRegexIsAToolErrorAndItReadsOnly(): void
    {
        $ws = $this->workspace();
        $threw = false;

        try {
            new GrepTool($ws)->handle(['pattern' => '(unclosed']);
        } catch (ToolException $e) {
            $threw = str_contains($e->getMessage(), 'valid regular expression');
        }

        Assert::true($threw);
        Assert::same(new GrepTool($ws)->effects(), [Effect::Read]);

        $this->cleanup($ws->root());
    }

    private function workspace(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_grep_' . bin2hex(random_bytes(5));
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
