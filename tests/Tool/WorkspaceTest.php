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

    /**
     * A credential file is refused, because in this system reading one also WRITES IT DOWN: every tool
     * result is traced verbatim into the project database, which lives as long as the project does.
     *
     * Found by audit rather than by theory. The queue entry worried about "a key placed into a prompt by
     * a step"; the real path needed no step to misbehave at all — `read_file` on a project's own `.env`
     * returned it, and the trace kept it. Including, when claw is pointed at its own folder, the actual
     * API key it runs on.
     */
    #[Test]
    public function aCredentialFileIsRefusedButItsTemplateIsNot(): void
    {
        $workspace = $this->workspace();
        $root = $workspace->root();

        foreach (['.env', '.env.local', '.npmrc', '.netrc', 'credentials.json', 'id_rsa', 'server.pem', 'app.key'] as $name) {
            file_put_contents("{$root}/{$name}", 'SECRET=sk-live-abc123');
            $this->assertRejected(fn () => $workspace->resolveExisting($name));
        }

        // Templates exist to be read: committed on purpose, and often the only written record of the
        // configuration's shape. Refusing them would block the legitimate reason to look.
        foreach (['.env.example', '.env.dist', '.env.sample', '.env.template'] as $name) {
            file_put_contents("{$root}/{$name}", 'API_KEY=');
            Assert::true(str_ends_with($workspace->resolveExisting($name), $name));
        }

        // Everything else is untouched by this.
        file_put_contents("{$root}/config.php", '<?php return [];');
        Assert::true(str_ends_with($workspace->resolveExisting('config.php'), 'config.php'));

        // Writing is NOT guarded: this is about not copying a secret into the journal, and a step that
        // writes a `.env` is doing setup, not leaking. The refusal message says why, so a model treats
        // it as a rule and stops rather than trying five spellings of the same path.
        Assert::true(str_ends_with($workspace->resolveForWrite('.env'), '.env'));

        $this->cleanup($root);
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
