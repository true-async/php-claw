<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\RunTestsTool;
use Claw\Tool\Workspace;
use Testo\Assert;
use Testo\Test;

final class RunTestsToolTest
{
    #[Test]
    public function errorsWhenNoRunnerIsFound(): void
    {
        $ws = $this->workspace();   // empty dir — no composer test, no phpunit

        $threw = false;

        try {
            new RunTestsTool($ws)->handle([]);
        } catch (ToolException $e) {
            $threw = str_contains($e->getMessage(), 'could not find a test runner');
        }

        Assert::true($threw);

        $this->cleanup($ws->root());
    }

    /** The discovery order picks the composer "test" script when the project declares one. */
    #[Test]
    public function discoversTheComposerTestScript(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->root() . '/composer.json', '{"scripts":{"test":"phpunit"}}');

        // It RUNS `composer test` (which may then fail if composer/phpunit are absent — that is the
        // suite's problem, not discovery's); we assert only that discovery chose the right command.
        Assert::true(str_contains(new RunTestsTool($ws)->handle([]), 'ran `composer test`'));

        $this->cleanup($ws->root());
    }

    private function workspace(): Workspace
    {
        $dir = sys_get_temp_dir() . '/claw_tests_' . bin2hex(random_bytes(5));
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
