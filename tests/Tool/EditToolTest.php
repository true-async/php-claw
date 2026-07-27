<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\EditTool;
use Claw\Tool\Effect;
use Claw\Tool\Workspace;
use Testo\Assert;
use Testo\Test;

final class EditToolTest
{
    #[Test]
    public function replacesAUniqueSubstring(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/calc.php', "<?php\nfunction subtract(\$a, \$b) { return \$a + \$b; }\n");

        $out = new EditTool($ws)->handle([
            'path' => 'calc.php',
            'old_string' => 'return $a + $b;',
            'new_string' => 'return $a - $b;',
        ]);

        Assert::true(str_contains($out, 'edited calc.php'));
        Assert::true(str_contains((string) file_get_contents($ws->root() . '/calc.php'), 'return $a - $b;'));

        $this->cleanup($ws->root());
    }

    #[Test]
    public function anAmbiguousMatchIsRefusedUnlessReplaceAll(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/a.txt', "x\nx\nx\n");

        $threw = false;

        try {
            new EditTool($ws)->handle(['path' => 'a.txt', 'old_string' => 'x', 'new_string' => 'y']);
        } catch (ToolException $e) {
            $threw = str_contains($e->getMessage(), 'ambiguous') && str_contains($e->getMessage(), '3 times');
        }

        Assert::true($threw);
        Assert::same((string) file_get_contents($ws->root() . '/a.txt'), "x\nx\nx\n");   // unchanged

        // replace_all makes it explicit and allowed
        new EditTool($ws)->handle(['path' => 'a.txt', 'old_string' => 'x', 'new_string' => 'y', 'replace_all' => true]);
        Assert::same((string) file_get_contents($ws->root() . '/a.txt'), "y\ny\ny\n");

        $this->cleanup($ws->root());
    }

    #[Test]
    public function aMissingMatchIsAToolError(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/a.txt', "hello\n");

        $threw = false;

        try {
            new EditTool($ws)->handle(['path' => 'a.txt', 'old_string' => 'goodbye', 'new_string' => 'x']);
        } catch (ToolException $e) {
            $threw = str_contains($e->getMessage(), 'not found');
        }

        Assert::true($threw);

        $this->cleanup($ws->root());
    }

    /** Many edits, across two files, land as ONE change. */
    #[Test]
    public function manyEditsAcrossFilesApplyTogether(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/a.php', "alpha\nkeep\n");
        file_put_contents($ws->root() . '/b.php', "beta\n");

        $out = new EditTool($ws)->handle(['edits' => [
            ['path' => 'a.php', 'old_string' => 'alpha', 'new_string' => 'ALPHA'],
            ['path' => 'a.php', 'old_string' => 'keep', 'new_string' => 'KEEP'],
            ['path' => 'b.php', 'old_string' => 'beta', 'new_string' => 'BETA'],
        ]]);

        Assert::true(str_contains($out, '3 edits across 2 files'));
        Assert::same((string) file_get_contents($ws->root() . '/a.php'), "ALPHA\nKEEP\n");
        Assert::same((string) file_get_contents($ws->root() . '/b.php'), "BETA\n");

        $this->cleanup($ws->root());
    }

    /** All-or-nothing: one bad edit in the batch writes NONE of them. */
    #[Test]
    public function aFailedEditInABatchWritesNothing(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/a.php', "alpha\n");
        file_put_contents($ws->root() . '/b.php', "beta\n");

        $threw = false;

        try {
            new EditTool($ws)->handle(['edits' => [
                ['path' => 'a.php', 'old_string' => 'alpha', 'new_string' => 'ALPHA'],
                ['path' => 'b.php', 'old_string' => 'NOPE', 'new_string' => 'x'],   // no match
            ]]);
        } catch (ToolException) {
            $threw = true;
        }

        Assert::true($threw);
        Assert::same((string) file_get_contents($ws->root() . '/a.php'), "alpha\n");   // NOT written
        Assert::same((string) file_get_contents($ws->root() . '/b.php'), "beta\n");

        $this->cleanup($ws->root());
    }

    #[Test]
    public function itWritesAndIsMutating(): void
    {
        $ws = $this->workspace();

        Assert::same(new EditTool($ws)->effects(), [Effect::Write]);

        $this->cleanup($ws->root());
    }

    private function workspace(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_edit_' . bin2hex(random_bytes(5));
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
