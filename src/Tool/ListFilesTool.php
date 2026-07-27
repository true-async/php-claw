<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * List the files and directories in a workspace directory — one level, or the whole tree beneath it
 * when `recursive` is set. Safe. Directories are suffixed with "/"; files show their size.
 */
final readonly class ListFilesTool implements ToolInterface
{
    public function __construct(
        private Workspace $workspace,
        private int       $maxEntries = 1000,
    ) {
    }

    public function name(): string
    {
        return 'list_files';
    }

    public function description(): string
    {
        return 'List the files and directories in a workspace directory. Optional "path" '
            . '(workspace-relative, default "."). Lists ONE level by default; pass "recursive": true to '
            . 'walk the whole tree beneath it (capped). To find files by NAME across the tree, glob is '
            . 'usually the better tool.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative directory, default "."'],
                'recursive' => [
                    'type' => 'boolean',
                    'description' => 'Walk the whole tree beneath path instead of listing one level (default false)',
                ],
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
        $path = (string) ($input['path'] ?? '.');

        if ($path === '') {
            $path = '.';
        }

        $real = $this->workspace->resolveExisting($path);

        if (!is_dir($real)) {
            throw new ToolException("list_files: not a directory: {$path}");
        }

        $lines = ($input['recursive'] ?? false)
            ? $this->walkTree($real)
            : $this->listLevel($real, $path);

        return $lines === [] ? '(empty directory)' : implode("\n", $lines);
    }

    /**
     * One level, via scandir — the entry names as they sit in the directory. Directories carry a
     * trailing "/", files their size.
     *
     * @return list<string>
     */
    private function listLevel(string $real, string $path): array
    {
        $entries = scandir($real);

        if ($entries === false) {
            throw new ToolException("list_files: cannot read directory: {$path}");
        }

        $entries = array_values(array_filter($entries, static fn (string $e): bool => $e !== '.' && $e !== '..'));
        $lines = [];

        foreach ($entries as $i => $entry) {
            if ($i >= $this->maxEntries) {
                $lines[] = "... [truncated at {$this->maxEntries} entries]";

                break;
            }

            $full = $real . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($full)) {
                $lines[] = $entry . '/';
            } else {
                $size = filesize($full);
                $lines[] = $entry . '  (' . ($size === false ? '?' : $size) . ' bytes)';
            }
        }

        return $lines;
    }

    /**
     * The whole tree beneath $real, as workspace-relative paths sorted for a stable listing. Unreadable
     * sub-directories are skipped (CATCH_GET_CHILD) rather than aborting the walk, and the same
     * maxEntries cap bounds the output — a recursive listing of a large tree must not be unbounded.
     *
     * @return list<string>
     */
    private function walkTree(string $real): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        $prefix = strlen($real) + 1;
        $rows = [];

        foreach ($iterator as $info) {
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($info->getPathname(), $prefix));

            if ($info->isDir()) {
                $rows[] = $rel . '/';
            } else {
                $size = $info->getSize();
                $rows[] = $rel . '  (' . ($size === false ? '?' : $size) . ' bytes)';
            }
        }

        sort($rows, SORT_STRING);

        if (\count($rows) > $this->maxEntries) {
            $rows = \array_slice($rows, 0, $this->maxEntries);
            $rows[] = "... [truncated at {$this->maxEntries} entries]";
        }

        return $rows;
    }
}
