<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Search file CONTENTS across the workspace by regular expression — the "where is this used / defined"
 * tool. It exists as its own tool, not as `grep` shelled through {@see BashTool}, for the reasons every
 * mature coding agent ships one: it is READ-only by construction (so a reviewer can have it without a
 * shell), its output is BOUNDED (a broad match cannot dump the repo into the model's context), it skips
 * the dependency and VCS trees that swamp real code, and it goes through the {@see Workspace} guards so a
 * search can never read a credential file the way a raw `grep -r` would.
 *
 * Returns `path:line: text`, capped. The model uses it to LOCATE before it reads — far cheaper than
 * opening files to look.
 */
final readonly class GrepTool implements ToolInterface
{
    /** Trees that dwarf the real code and are never what a search is after. */
    private const array SKIP_DIRS = ['.git', '.svn', '.hg', 'vendor', 'node_modules', 'dist', 'build', '.idea'];

    /** Past this a file is treated as data, not code — reading it into memory to grep is not worth it. */
    private const int MAX_FILE_BYTES = 2_000_000;

    public function __construct(
        private Workspace $workspace,
        private int $maxMatches = 200,
    ) {
    }

    public function name(): string
    {
        return 'grep';
    }

    public function description(): string
    {
        return 'Search file CONTENTS across the workspace for a regular expression (PCRE syntax, no '
            . 'delimiters). Returns matching lines as "path:line: text", capped. Optional: "path" '
            . '(workspace-relative directory to search, default "."), "glob" (only files whose name '
            . 'matches, e.g. "*.php"), "ignore_case". Use this to LOCATE code by content — much cheaper '
            . 'than reading files to look.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'PCRE regular expression, without delimiters'],
                'path' => ['type' => 'string', 'description' => 'Workspace-relative directory to search, default "."'],
                'glob' => ['type' => 'string', 'description' => 'Only search files whose basename matches this glob, e.g. "*.php"'],
                'ignore_case' => ['type' => 'boolean', 'description' => 'Case-insensitive match'],
            ],
            'required' => ['pattern'],
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
        $pattern = (string) ($input['pattern'] ?? '');

        if (trim($pattern) === '') {
            throw new ToolException('grep: "pattern" is required');
        }

        $regex = '~' . str_replace('~', '\~', $pattern) . '~' . (($input['ignore_case'] ?? false) ? 'i' : '');

        if (@preg_match($regex, '') === false) {
            throw new ToolException("grep: not a valid regular expression: {$pattern}");
        }

        $dir = (string) ($input['path'] ?? '.');
        $root = $this->workspace->resolveExisting($dir === '' ? '.' : $dir);

        if (!is_dir($root)) {
            throw new ToolException("grep: not a directory: {$dir}");
        }

        $glob = (string) ($input['glob'] ?? '');
        $base = $this->workspace->root();
        $matches = [];
        $truncated = false;

        foreach ($this->walk($root) as $file) {
            if ($glob !== '' && !fnmatch($glob, basename($file))) {
                continue;
            }

            // Through the workspace guard, by relative path: a search must never read a credential file,
            // and resolveExisting() is the one place that rule lives — so reuse it rather than restate it.
            $rel = ltrim(str_replace($base, '', $file), \DIRECTORY_SEPARATOR);

            try {
                $safe = $this->workspace->resolveExisting($rel);
            } catch (ToolException) {
                continue;   // outside the workspace, or a secret file — skip it silently
            }

            if (($size = @filesize($safe)) === false || $size > self::MAX_FILE_BYTES) {
                continue;
            }

            $handle = @fopen($safe, 'r');

            if ($handle === false) {
                continue;
            }

            $first = (string) fread($handle, 4096);

            if (str_contains($first, "\0")) {   // binary — a text search is meaningless here
                fclose($handle);

                continue;
            }

            rewind($handle);
            $line = 0;

            while (($text = fgets($handle)) !== false) {
                ++$line;

                if (preg_match($regex, $text) !== 1) {
                    continue;
                }

                if (\count($matches) >= $this->maxMatches) {
                    $truncated = true;

                    break;
                }

                $matches[] = "{$rel}:{$line}: " . trim($text);
            }

            fclose($handle);

            if ($truncated) {
                break;
            }
        }

        if ($matches === []) {
            return "no matches for /{$pattern}/";
        }

        $out = implode("\n", $matches);

        return $truncated ? $out . "\n... [truncated at {$this->maxMatches} matches]" : $out;
    }

    /**
     * Every file under $root, skipping the dependency and VCS trees. A generator so a huge repo is walked
     * lazily and the match cap can stop it early without having built the whole list first.
     *
     * @return \Generator<string>
     */
    private function walk(string $root): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !($f->isDir() && \in_array($f->getFilename(), self::SKIP_DIRS, true)),
            ),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                yield $entry->getPathname();
            }
        }
    }
}
