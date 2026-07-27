<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Show WHAT changed in the working tree — the git diff of tracked files against the last commit, plus the
 * new untracked files. Its whole point is the reviewer's question: a critic and a supervisor keep
 * re-reading files to work out what a step did, when the change itself is one cheap, precise artifact.
 * READ-only: it shows changes, it never makes them.
 */
final readonly class DiffTool implements ToolInterface
{
    public function __construct(
        private Workspace $workspace,
        private ?Secrets $secrets = null,
        private int $timeoutMs = 0,
    ) {
    }

    public function name(): string
    {
        return 'diff';
    }

    public function description(): string
    {
        return 'Show what has changed in the working tree: the diff of tracked files against the last '
            . 'commit (git diff HEAD) plus any new untracked files. Optional "path" narrows it to a file '
            . 'or directory. READ-only — use it to see WHAT changed instead of re-reading whole files.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative file or directory to limit the diff to'],
            ],
        ];
    }

    public function effects(): array
    {
        return [Effect::Read];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $path = trim((string) ($input['path'] ?? ''));

        if (str_contains($path, '..')) {
            throw new ToolException('diff: "path" must stay inside the workspace');
        }

        $arg = $path === '' ? '' : ' -- ' . escapeshellarg($path);
        $shell = new BashTool($this->workspace->root(), $this->secrets, $this->timeoutMs);

        $diff = $shell->handle(['command' => "git --no-pager diff HEAD{$arg}"]);

        // Case-insensitive: `git diff` warns "Not a git repository" (capital N), other commands say
        // "fatal: not a git repository" — match either.
        if (stripos($diff, 'not a git repository') !== false) {
            throw new ToolException('diff: this project is not a git repository, so there is no diff to show');
        }

        $untracked = trim($shell->handle(['command' => "git ls-files --others --exclude-standard{$arg}"]));
        $parts = [];

        if (trim($diff) !== '' && $diff !== '(no output)') {
            $parts[] = $diff;
        }

        if ($untracked !== '' && $untracked !== '(no output)') {
            $parts[] = "--- new untracked files ---\n{$untracked}";
        }

        return $parts === [] ? '(no changes in the working tree)' : implode("\n\n", $parts);
    }
}
