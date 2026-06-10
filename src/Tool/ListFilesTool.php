<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * List the files and directories in a workspace directory (one level). Safe.
 * Directories are suffixed with "/"; files show their size.
 */
final class ListFilesTool implements ToolInterface
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly int $maxEntries = 1000,
    ) {
    }

    public function name(): string
    {
        return 'list_files';
    }

    public function description(): string
    {
        return 'List files and directories in a workspace directory. Optional "path" (workspace-relative, default ".").';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative directory, default "."'],
            ],
        ];
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

        $entries = scandir($real);
        if ($entries === false) {
            throw new ToolException("list_files: cannot read directory: {$path}");
        }

        $entries = array_values(array_filter($entries, static fn (string $e): bool => $e !== '.' && $e !== '..'));
        if ($entries === []) {
            return '(empty directory)';
        }

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

        return implode("\n", $lines);
    }
}
