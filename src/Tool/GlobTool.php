<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Find files by NAME pattern across the workspace — the companion to {@see GrepTool}: glob locates the
 * files, grep locates the contents, and the model reads only what those point at. Its own tool rather
 * than `find`/`ls` through {@see BashTool} for the same reasons: READ-only by construction, bounded
 * output, and it skips the dependency and VCS trees.
 *
 * The pattern is a glob over the workspace-RELATIVE path. `*` matches within a path segment, `**` across
 * segments, `?` a single character: `*.php` (php files at the search root), `src/*.php`, `**\/*Test.php`.
 * Results are newest-first (by mtime), so the files most likely relevant to recent work come up first.
 */
final readonly class GlobTool implements ToolInterface
{
    private const array SKIP_DIRS = ['.git', '.svn', '.hg', 'vendor', 'node_modules', 'dist', 'build', '.idea'];

    public function __construct(
        private Workspace $workspace,
        private int $maxResults = 300,
    ) {
    }

    public function name(): string
    {
        return 'glob';
    }

    public function description(): string
    {
        return 'Find files by NAME pattern across the workspace, newest first. The pattern globs the '
            . 'workspace-relative path: "*" within a path segment, "**" across segments, "?" one char — '
            . 'e.g. "*.php", "src/**/*.php", "**/*Test.php". Optional "path" (workspace-relative directory '
            . 'to search under, default "."). Use this to LOCATE files before reading them.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'Glob over the relative path, e.g. "src/**/*.php"'],
                'path' => ['type' => 'string', 'description' => 'Workspace-relative directory to search under, default "."'],
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
            throw new ToolException('glob: "pattern" is required');
        }

        $dir = (string) ($input['path'] ?? '.');
        $root = $this->workspace->resolveExisting($dir === '' ? '.' : $dir);

        if (!is_dir($root)) {
            throw new ToolException("glob: not a directory: {$dir}");
        }

        $regex = self::globToRegex($pattern);
        $base = $this->workspace->root();
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !($f->isDir() && \in_array($f->getFilename(), self::SKIP_DIRS, true)),
            ),
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }

            $rel = ltrim(str_replace($base, '', $entry->getPathname()), \DIRECTORY_SEPARATOR);

            if (preg_match($regex, $rel) === 1) {
                $found[$rel] = $entry->getMTime();
            }
        }

        if ($found === []) {
            return "no files match {$pattern}";
        }

        arsort($found);   // newest first — recent work surfaces at the top
        $paths = array_keys($found);
        $truncated = \count($paths) > $this->maxResults;
        $paths = \array_slice($paths, 0, $this->maxResults);
        $out = implode("\n", $paths);

        return $truncated ? $out . "\n... [truncated at {$this->maxResults} files]" : $out;
    }

    /** Translate a `*`/`**`/`?` glob into an anchored PCRE over a `/`-separated relative path. */
    private static function globToRegex(string $glob): string
    {
        $out = '';
        $len = \strlen($glob);

        for ($i = 0; $i < $len; ++$i) {
            $c = $glob[$i];

            if ($c === '*') {
                if ($i + 1 < $len && $glob[$i + 1] === '*') {
                    if ($i + 2 < $len && $glob[$i + 2] === '/') {
                        $out .= '(?:.*/)?';   // "**/" matches ZERO or more leading directories
                        $i += 2;
                    } else {
                        $out .= '.*';   // bare "**" crosses path separators
                        ++$i;
                    }
                } else {
                    $out .= '[^/]*';   // "*" stays within a segment
                }
            } elseif ($c === '?') {
                $out .= '[^/]';
            } else {
                $out .= preg_quote($c, '~');
            }
        }

        return '~^' . $out . '$~';
    }
}
