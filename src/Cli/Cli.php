<?php

declare(strict_types=1);

namespace Claw\Cli;

/**
 * The command-line front door: parse argv, pick the mode, dispatch.
 *
 * One binary, two modes:
 *   - workflow (default): project/issue setup and per-issue solver runs —
 *     `claw -c`, `claw -i`, `claw run`, `claw log`. See {@see WorkflowMode}.
 *   - session (`--session` / `-s`): the original interactive chat. See {@see SessionMode}.
 *
 * Each mode loads its own {@see \Claw\Config} lazily — the setup commands (`-c`/`-i`/`log`)
 * touch only local state and must run without an API key.
 */
final class Cli
{
    /** @param string $root the install root: holds .env, CLAUDE.md, vendor/ and the workspace. */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param list<string> $argv the process argv, already string-normalized (argv[0] is the script name)
     */
    public function run(array $argv): int
    {
        $args = \array_slice($argv, 1);

        if (\in_array('--session', $args, true) || \in_array('-s', $args, true)) {
            return new SessionMode($this->root)->run();
        }

        return new WorkflowMode($this->root)->run($args);
    }

    /**
     * The first argument that is not an option flag, or null if there is none.
     *
     * @param list<string> $args
     */
    public static function firstPositional(array $args): ?string
    {
        foreach ($args as $arg) {
            if ($arg !== '' && $arg[0] !== '-') {
                return $arg;
            }
        }

        return null;
    }
}
