<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * A project's credentials, held where the model cannot see them and the journal cannot record them.
 *
 * The problem this solves is specific to a system that writes everything down. A run's every tool call
 * and result is traced into the project database, permanently — so a secret that reaches a command line
 * is not merely "used", it is archived. And a secret the model can read is a secret it can put in a
 * prompt, an artifact or a handoff, each of which is archived too.
 *
 * So the value goes to the SHELL and nowhere else. {@see BashTool} passes these as environment variables
 * to the child process, which means:
 *
 *  - the model writes `curl -H "Authorization: Bearer $STRIPE_KEY" …` and never learns what that is;
 *  - the command TRACED is the one the model wrote, `$STRIPE_KEY` unexpanded, because nothing rewrites
 *    it — the shell does the expansion, inside a process whose output we control;
 *  - {@see redact()} takes the values back out of that output before anyone sees or stores it, so
 *    `echo $STRIPE_KEY` returns a placeholder rather than the key.
 *
 * WHAT THIS IS NOT. It is not a boundary against a command written to defeat it: `bash` is unrestricted,
 * so it can read the secrets file by absolute path, and `echo $KEY | base64` defeats a substring scrub.
 * The guarantee is narrower and worth stating exactly — a secret's VALUE never enters the model's
 * context and never enters the journal — which covers how this goes wrong in practice: a curious model,
 * a verbose tool, an error message that prints its configuration.
 */
final readonly class Secrets
{
    /**
     * Below this many characters a value is refused rather than held.
     *
     * Redaction is a substring replacement over a command's whole output. A secret of "1" or "dev" would
     * match everywhere and turn every result into confetti — the tool would be unusable and the reason
     * would be hard to see. A credential short enough to hit this is not a credential.
     */
    private const int MIN_LENGTH = 8;

    /** @param array<string, string> $values NAME => value, names already validated */
    private function __construct(private array $values)
    {
    }

    /** No secrets at all — the ordinary case, and what a project gets until someone gives it some. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Read a project's secrets file: plain `NAME=value` lines, `#` comments, blanks ignored.
     *
     * A missing file is no secrets rather than an error: most projects have none, and a system that
     * refused to run without a file nobody needs would teach people to create empty ones.
     *
     * The file lives beside the project's state database in the app home, NOT inside the project. Inside
     * would put it within reach of the very run it is hiding from, and within reach of the project's own
     * git — a credential committed by accident is the failure this whole path exists to avoid.
     *
     * @throws ToolException on a malformed name or an unusably short value — both are silent misfires
     *                       otherwise: the model would be told a secret exists and get an empty string
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return self::none();
        }

        self::assertPrivate($path);

        $values = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $number => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $at = strpos($line, '=');

            if ($at === false) {
                throw new ToolException('secrets: line ' . ($number + 1) . ' is not NAME=value');
            }

            $name = trim(substr($line, 0, $at));
            $value = trim(substr($line, $at + 1), " \t\"'");

            // The message never quotes the VALUE — an error report is one more place a secret can end up.
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1) {
                throw new ToolException("secrets: '{$name}' is not a usable name (A-Z, digits and _ only)");
            }

            if (\strlen($value) < self::MIN_LENGTH) {
                throw new ToolException(
                    "secrets: {$name} is shorter than " . self::MIN_LENGTH . ' characters, which cannot be '
                    . 'redacted from output without destroying it — remove it or use the real credential',
                );
            }

            $values[$name] = $value;
        }

        return new self($values);
    }

    /**
     * Where a project keeps them: beside its state database, never inside the project folder.
     *
     * "Never inside" was a convention with nothing enforcing it, and the layout that breaks it is not
     * hypothetical — this repository dogfoods itself, and its app home is `<repo>/workspace`. Point claw
     * at its own folder and the secrets file lands INSIDE the workspace, where `read_file` and `bash`
     * both reach it. {@see assertOutside()} is what enforces it, and the run path calls it before
     * loading anything.
     */
    public static function pathFor(string $projectsDir, string $projectKey): string
    {
        return $projectsDir . '/' . $projectKey . '.secrets';
    }

    /**
     * Refuse a secrets file that sits inside the project a run works in.
     *
     * @throws ToolException
     */
    public static function assertOutside(string $path, string $projectPath): void
    {
        $dir = realpath(\dirname($path));
        $project = realpath($projectPath);

        if ($dir === false || $project === false) {
            return;   // one of them does not exist yet; nothing to be inside of
        }

        if ($dir === $project || str_starts_with($dir, $project . \DIRECTORY_SEPARATOR)) {
            throw new ToolException(
                "secrets: {$path} is inside the project folder, where the run can read it. Move the app "
                . 'home (CLAW_WORKSPACE) outside the project, or remove the secrets file.',
            );
        }
    }

    /**
     * Refuse a secrets file every local user can read.
     *
     * The instruction is "plain NAME=value", so people create it with an editor and get whatever their
     * umask gives — commonly 0644. A file named after a mangled path, sitting in a folder full of `.db`
     * files, does not announce that it wants 0600. Saying so at load costs one line and one message;
     * discovering it later costs a credential.
     *
     * @throws ToolException
     */
    private static function assertPrivate(string $path): void
    {
        $mode = @fileperms($path);

        if ($mode === false || ($mode & 0o077) === 0) {
            return;
        }

        throw new ToolException(
            'secrets: ' . $path . ' is readable by other users (mode '
            . substr(sprintf('%o', $mode), -4) . '). Run `chmod 600` on it — it holds credentials.',
        );
    }

    /**
     * The names a model may use, so the tool can tell it what exists without telling it what they are.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->values);
    }

    /**
     * The environment the child process gets. Empty when there are none, so nothing changes for anyone.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return $this->values;
    }

    /**
     * Take every secret value back out of a command's output.
     *
     * This is the half that makes the rest hold. Passing values as environment variables keeps them out
     * of the command — but the model chooses the command, and `echo $KEY`, `env`, or a `curl -v` that
     * prints its own request headers put the value straight into the output, which is returned to the
     * model and written to the journal. Longest first, so a secret that contains another is replaced
     * whole rather than leaving a tail behind.
     */
    public function redact(string $output): string
    {
        if ($this->values === []) {
            return $output;
        }

        $ordered = $this->values;
        uasort($ordered, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($ordered as $name => $value) {
            $output = str_replace($value, "[redacted {$name}]", $output);
        }

        return $output;
    }
}
