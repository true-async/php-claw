<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\Effect;
use Claw\Tool\FindDefinitionTool;
use Claw\Tool\Workspace;
use Testo\Assert;
use Testo\Test;

final class FindDefinitionToolTest
{
    #[Test]
    public function findsAClassDefinitionAndNotItsUses(): void
    {
        $ws = $this->workspace();
        mkdir($ws->root() . '/src');
        file_put_contents($ws->root() . '/src/Calculator.php', "<?php\n\nfinal class Calculator\n{\n}\n");
        file_put_contents($ws->root() . '/src/App.php', "<?php\n\$c = new Calculator();\n");   // a USE, not a def

        $out = new FindDefinitionTool($ws)->handle(['name' => 'Calculator']);

        Assert::true(str_contains($out, 'src/Calculator.php:3:'));
        Assert::true(str_contains($out, 'class Calculator'));
        Assert::false(str_contains($out, 'App.php'));   // the `new Calculator()` use is not a definition

        $this->cleanup($ws->root());
    }

    #[Test]
    public function findsAMethodDefinition(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/C.php', "<?php\nclass C {\n    public function subtract(\$a, \$b) { return \$a - \$b; }\n}\n");

        $out = new FindDefinitionTool($ws)->handle(['name' => 'subtract']);

        Assert::true(str_contains($out, 'C.php:3:'));
        Assert::true(str_contains($out, 'function subtract'));

        $this->cleanup($ws->root());
    }

    #[Test]
    public function reportsWhenNothingIsDefined(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/a.php', "<?php\necho 'hi';\n");

        Assert::same(new FindDefinitionTool($ws)->handle(['name' => 'Nowhere']), 'no definition of Nowhere found');
        Assert::same(new FindDefinitionTool($ws)->effects(), [Effect::Read]);

        $this->cleanup($ws->root());
    }

    #[Test]
    public function rejectsANameThatIsNotASymbol(): void
    {
        $ws = $this->workspace();
        $threw = false;

        try {
            new FindDefinitionTool($ws)->handle(['name' => 'not a name!']);
        } catch (ToolException $e) {
            $threw = str_contains($e->getMessage(), 'not a PHP symbol name');
        }

        Assert::true($threw);

        $this->cleanup($ws->root());
    }

    private function workspace(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_def_' . bin2hex(random_bytes(5));
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
