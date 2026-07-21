<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Confines file/bash tools to one directory. Resolves workspace-relative paths
 * and rejects absolute paths and `..` escapes (checked after realpath, so any
 * traversal that lands outside the root is caught), and refuses the files that
 * hold credentials — see {@see SECRET_FILES}.
 */
final readonly class Workspace
{
    /**
     * Files whose ordinary purpose is to hold a credential. Reading one is refused, because in this
     * system reading it also WRITES IT DOWN: every tool result is traced verbatim into the project
     * database, which lives as long as the project does. A run that opened a `.env` to see how the app
     * is configured would leave the key in a file nobody thinks of as secret, forever, and nothing about
     * that is visible at the moment it happens.
     *
     * Checked against the basename, case-insensitively, as exact names and as suffix patterns. Example
     * and template files are deliberately still readable: `.env.example` is committed on purpose and is
     * often the only place the shape of the configuration is written down.
     *
     * HOW FAR THIS GOES, precisely, so nobody mistakes it for a boundary. It guards the file tools. It
     * does NOT guard `bash`, which runs with the workspace as its working directory and can `cat .env`
     * like anything else — and no scan of a shell command could reliably say otherwise. What this closes
     * is the ACCIDENTAL path: a model opening `.env` because it wants to understand the configuration,
     * which is the way this actually happens. A run pointed at a folder whose secrets matter is still a
     * run pointed at a folder whose secrets matter.
     *
     * @var list<string>
     */
    private const array SECRET_FILES = [
        '.env', '.envrc', '.netrc', '.pgpass', '.htpasswd', '.npmrc', '.pypirc', '.dockercfg',
        'credentials', 'credentials.json', 'secrets.json', 'id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519',
    ];

    /** Extensions that carry a private key whatever the file is called. */
    private const array SECRET_EXTENSIONS = ['pem', 'key', 'p12', 'pfx', 'jks', 'keystore'];

    /** Names that look like a secret file but exist to be read — a template, not a credential. */
    private const array ALLOWED_EXAMPLES = ['.env.example', '.env.sample', '.env.dist', '.env.template'];

    private string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);

        if ($real === false) {
            throw new ToolException("Workspace directory does not exist: {$root}");
        }

        $this->root = $real;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Resolve an existing file inside the workspace (for reads). */
    public function resolveExisting(string $path): string
    {
        $real = realpath($this->join($path));

        if ($real === false) {
            throw new ToolException("No such file: {$path}");
        }

        $this->assertInside($real);
        $this->assertNotASecret($real);

        return $real;
    }

    /**
     * Refuse a file whose job is to hold a credential.
     *
     * The refusal says what it is, so a model reads it as a rule rather than a glitch and moves on
     * instead of trying five spellings of the same path.
     *
     * @throws ToolException
     */
    private function assertNotASecret(string $real): void
    {
        $name = strtolower(basename($real));

        if (\in_array($name, self::ALLOWED_EXAMPLES, true)) {
            return;
        }

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $secret = \in_array($name, self::SECRET_FILES, true)
            || \in_array($extension, self::SECRET_EXTENSIONS, true)
            || str_starts_with($name, '.env.');

        if ($secret) {
            throw new ToolException(
                "Refusing to read {$name}: it is the kind of file that holds credentials, and everything "
                . 'a tool returns is written into this project\'s journal permanently. If you need to know '
                . 'how the project is configured, read its example/template file or its documentation.',
            );
        }
    }

    /** Resolve a path for writing; the file may not exist but its parent must. */
    public function resolveForWrite(string $path): string
    {
        $full = $this->join($path);

        $parent = realpath(\dirname($full));

        if ($parent === false) {
            throw new ToolException("Directory does not exist for: {$path}");
        }

        $this->assertInside($parent);

        return $parent . DIRECTORY_SEPARATOR . basename($full);
    }

    private function join(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1) {
            throw new ToolException("Path must be workspace-relative: {$path}");
        }

        return $this->root . DIRECTORY_SEPARATOR . $path;
    }

    private function assertInside(string $real): void
    {
        if ($real !== $this->root && !str_starts_with($real, $this->root . DIRECTORY_SEPARATOR)) {
            throw new ToolException("Path escapes the workspace: {$real}");
        }
    }
}
