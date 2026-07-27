<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\LintTool;
use Claw\Tool\Workspace;
use Testo\Assert;
use Testo\Test;

final class LintToolTest
{
    #[Test]
    public function passesAValidPhpFile(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/ok.php', "<?php\n\$x = 1 + 2;\n");

        Assert::true(str_contains(new LintTool($ws)->handle(['path' => 'ok.php']), 'No syntax errors detected'));

        $this->cleanup($ws->root());
    }

    #[Test]
    public function flagsABrokenPhpFile(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/bad.php', "<?php\n\$x = ;\n");

        Assert::true(str_contains(strtolower(new LintTool($ws)->handle(['path' => 'bad.php'])), 'error'));

        $this->cleanup($ws->root());
    }

    #[Test]
    public function refusesANonPhpPath(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/readme.md', "# hi\n");

        $threw = false;

        try {
            new LintTool($ws)->handle(['path' => 'readme.md']);
        } catch (ToolException $e) {
            $threw = str_contains($e->getMessage(), 'handles .php files');
        }

        Assert::true($threw);

        $this->cleanup($ws->root());
    }

    #[Test]
    public function withoutAnAnalyserItSaysWhatToDo(): void
    {
        $ws = $this->workspace();   // no phpstan, no composer analyse script

        $threw = false;

        try {
            new LintTool($ws)->handle([]);
        } catch (ToolException $e) {
            $threw = str_contains($e->getMessage(), 'no project analyser found');
        }

        Assert::true($threw);

        $this->cleanup($ws->root());
    }

    private function workspace(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_lint_' . bin2hex(random_bytes(5));
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
