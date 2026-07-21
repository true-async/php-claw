<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\BashTool;
use Claw\Tool\Secrets;
use Testo\Assert;
use Testo\Test;

final class SecretsTest
{
    /**
     * The whole point, end to end: the shell gets the value, the model gets a name, and the output
     * carries neither.
     *
     * In this system a secret that reaches a command line is not merely used, it is ARCHIVED — every
     * tool call and result is traced into the project database permanently, and the result is handed
     * back into the model's context where it can be copied into a prompt or an artifact, which are
     * archived too. So the value has to go to the shell and nowhere else.
     */
    #[Test]
    public function theShellSeesTheValueAndNothingElseDoes(): void
    {
        $dir = self::tempDir();

        try {
            self::writeSecrets($dir . '/p.secrets', "STRIPE_KEY=sk_live_9f3a2b7c1d\n");
            $secrets = Secrets::fromFile(Secrets::pathFor($dir, 'p'));
            $bash = new BashTool($dir, $secrets);

            // The command CAN use it: the shell expands it, because it is a real environment variable.
            Assert::same($bash->handle(['command' => 'test "$STRIPE_KEY" = sk_live_9f3a2b7c1d && echo matched']), 'matched');

            // And the model cannot read it back — the most direct attempt returns a placeholder.
            Assert::same($bash->handle(['command' => 'echo $STRIPE_KEY']), '[redacted STRIPE_KEY]');

            // Nor by the side door: anything that prints the environment is scrubbed the same way.
            $env = $bash->handle(['command' => 'env']);
            Assert::false(str_contains($env, 'sk_live_9f3a2b7c1d'));
            Assert::true(str_contains($env, '[redacted STRIPE_KEY]'));

            // Nor on the failure path, where an error message is the likeliest accidental leak of all.
            $failed = $bash->handle(['command' => 'echo $STRIPE_KEY >&2; exit 3']);
            Assert::true(str_starts_with($failed, '[exit 3]'));
            Assert::false(str_contains($failed, 'sk_live_9f3a2b7c1d'));
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * The model has to know a credential is reachable or it cannot use one — and must not know what it
     * is, or it could put it somewhere that gets written down. The description names, never values.
     */
    #[Test]
    public function theToolNamesTheSecretsWithoutRevealingThem(): void
    {
        $dir = self::tempDir();

        try {
            self::writeSecrets($dir . '/p.secrets', "STRIPE_KEY=sk_live_9f3a2b7c1d\nGITHUB_TOKEN=ghp_abcdefghijkl\n");
            $description = new BashTool($dir, Secrets::fromFile(Secrets::pathFor($dir, 'p')))->description();

            Assert::true(str_contains($description, '$STRIPE_KEY'));
            Assert::true(str_contains($description, '$GITHUB_TOKEN'));
            Assert::false(str_contains($description, 'sk_live_9f3a2b7c1d'));
            Assert::false(str_contains($description, 'ghp_abcdefghijkl'));

            // With none configured the tool says nothing about secrets at all — no project should read a
            // description about a feature it does not use.
            $plain = new BashTool($dir)->description();
            Assert::false(str_contains($plain, 'credentials'));
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * A short value cannot be redacted without destroying the output it appears in — "1" or "dev" would
     * match everywhere and turn every result into confetti, with the cause nearly impossible to see. It
     * is refused at load, and the refusal does not quote the value: an error report is one more place a
     * secret can end up.
     */
    #[Test]
    public function anUnusableSecretIsRefusedAtLoadAndTheRefusalDoesNotQuoteIt(): void
    {
        $dir = self::tempDir();

        try {
            self::writeSecrets($dir . '/short.secrets', "TOKEN=abc\n");
            $message = self::refusalFrom($dir . '/short.secrets');
            Assert::true(str_contains($message, 'TOKEN'));
            Assert::false(str_contains($message, 'abc'));

            self::writeSecrets($dir . '/bad.secrets', "not a name=whatever12345\n");
            Assert::true(str_contains(self::refusalFrom($dir . '/bad.secrets'), 'not a usable name'));

            self::writeSecrets($dir . '/nokv.secrets', "STRIPE_KEY\n");
            Assert::true(str_contains(self::refusalFrom($dir . '/nokv.secrets'), 'line 1'));

            // Comments and blanks are ordinary, and a missing file is simply no secrets — most projects
            // have none, and refusing to run without one would teach people to create empty ones.
            self::writeSecrets($dir . '/ok.secrets', "# a note\n\nSTRIPE_KEY=sk_live_9f3a2b7c1d\n");
            Assert::same(Secrets::fromFile($dir . '/ok.secrets')->names(), ['STRIPE_KEY']);
            Assert::same(Secrets::fromFile($dir . '/absent.secrets')->names(), []);
        } finally {
            self::rmrf($dir);
        }
    }

    /** One secret containing another must be replaced whole, not leave the shorter one's tail behind. */
    #[Test]
    public function aSecretContainingAnotherIsRedactedWhole(): void
    {
        $dir = self::tempDir();

        try {
            self::writeSecrets($dir . '/n.secrets', "SHORT=abcdefgh\nLONG=abcdefgh_and_more\n");
            $secrets = Secrets::fromFile($dir . '/n.secrets');

            $out = $secrets->redact('value: abcdefgh_and_more');

            Assert::same($out, 'value: [redacted LONG]');
            Assert::false(str_contains($out, 'abcdefgh_and_more'));
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * The path a security review found, and the reason redaction cannot live only in the shell tool.
     *
     * A secret leaves through a FILE far more easily than through a command's output, and by doing the
     * exact thing the tool invites: `git remote set-url origin "https://x:$GH_TOKEN@github.com/…"` is
     * the canonical use of a token and writes the expanded URL into `.git/config`. `gh auth login`, npm
     * and pip cache credentials under `$HOME`, which for a run IS the project folder. None of those are
     * credential-shaped names any blocklist would catch, and a later turn reading one — by the model, or
     * by a critic or judge whose job is reading files — put the raw value into the context and the
     * journal. Not an attack. A cooperative model following instructions.
     */
    #[Test]
    public function aSecretWrittenIntoAFileIsRedactedWhenTheFileIsReadBack(): void
    {
        $dir = self::tempDir();

        try {
            self::writeSecrets($dir . '/p.secrets', "GH_TOKEN=ghp_9f3a2b7c1d4e5f\n");
            $secrets = Secrets::fromFile(Secrets::pathFor($dir, 'p'));

            $project = $dir . '/project';
            mkdir($project);
            $bash = new BashTool($project, $secrets);
            $read = new \Claw\Tool\ReadFileTool(new \Claw\Tool\Workspace($project), 100_000, $secrets);

            // The command does the ordinary thing: it puts the token somewhere durable.
            $bash->handle(['command' => 'printf "url = https://x:$GH_TOKEN@github.com/a/b\n" > config.ini']);

            // The file on disk really does hold it — this is not a test of the write being prevented.
            Assert::true(str_contains((string) file_get_contents($project . '/config.ini'), 'ghp_9f3a2b7c1d4e5f'));

            // But reading it back gives the model, and the journal, a placeholder.
            $seen = $read->handle(['path' => 'config.ini']);
            Assert::false(str_contains($seen, 'ghp_9f3a2b7c1d4e5f'));
            Assert::true(str_contains($seen, '[redacted GH_TOKEN]'));
        } finally {
            self::rmrf($dir . '/project');
            self::rmrf($dir);
        }
    }

    /**
     * Write a secrets file the way it is meant to exist: 0600.
     *
     * The loader refuses anything looser, and it caught these fixtures the moment it was added — they
     * were being created at whatever the umask gave, which is exactly the mistake a person makes when
     * told "plain NAME=value" and left with an editor.
     */
    private static function writeSecrets(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0o600);
    }

    /** A secrets file every local user can read is refused: the message names the mode, never a value. */
    #[Test]
    public function aWorldReadableSecretsFileIsRefused(): void
    {
        $dir = self::tempDir();

        try {
            $path = $dir . '/loose.secrets';
            file_put_contents($path, "STRIPE_KEY=sk_live_9f3a2b7c1d\n");
            chmod($path, 0o644);

            $message = self::refusalFrom($path);
            Assert::true(str_contains($message, 'readable by other users'));
            Assert::false(str_contains($message, 'sk_live_9f3a2b7c1d'));

            chmod($path, 0o600);
            Assert::same(Secrets::fromFile($path)->names(), ['STRIPE_KEY']);
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * A secrets file inside the project is refused outright.
     *
     * The convention was "beside the state database, never inside the project", enforced by nothing —
     * and the layout that breaks it is this repository's own: the default app home is `<repo>/workspace`,
     * so pointing claw at its own folder puts the secrets file within reach of the run it hides from.
     */
    #[Test]
    public function aSecretsFileInsideTheProjectIsRefused(): void
    {
        $project = self::tempDir();
        $outside = self::tempDir();

        try {
            $threw = false;

            try {
                Secrets::assertOutside($project . '/inner/p.secrets', $project);
            } catch (ToolException $e) {
                $threw = str_contains($e->getMessage(), 'inside the project folder');
            }

            // The path does not exist yet, so nothing is inside anything — no false alarm.
            Assert::false($threw);

            mkdir($project . '/inner');
            $threw = false;

            try {
                Secrets::assertOutside($project . '/inner/p.secrets', $project);
            } catch (ToolException $e) {
                $threw = str_contains($e->getMessage(), 'inside the project folder');
            }

            Assert::true($threw);

            // The intended layout raises nothing.
            Secrets::assertOutside($outside . '/p.secrets', $project);
        } finally {
            self::rmrf($project . '/inner');
            self::rmrf($project);
            self::rmrf($outside);
        }
    }

    private static function refusalFrom(string $path): string
    {
        try {
            Secrets::fromFile($path);
        } catch (ToolException $e) {
            return $e->getMessage();
        }

        return '';
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-secrets-' . uniqid('', true);
        mkdir($dir, 0o775, true);

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}
