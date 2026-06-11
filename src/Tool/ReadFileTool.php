<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Read a text file from the workspace. Safe (read-only).
 */
final readonly class ReadFileTool implements ToolInterface
{
    /**
     * @param positive-int $maxBytes maximum bytes to return (longer files are truncated)
     */
    public function __construct(
        private Workspace $workspace,
        private int       $maxBytes = 100_000,
    ) {
    }

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Read a UTF-8 text file from the workspace, given a workspace-relative path.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative file path'],
            ],
            'required' => ['path'],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $path = (string) ($input['path'] ?? '');
        if ($path === '') {
            throw new ToolException('read_file: "path" is required');
        }

        $real = $this->workspace->resolveExisting($path);

        $data = file_get_contents($real, false, null, 0, $this->maxBytes + 1);
        if ($data === false) {
            throw new ToolException("read_file: cannot read {$path}");
        }

        if (strlen($data) > $this->maxBytes) {
            return substr($data, 0, $this->maxBytes) . "\n... [truncated]";
        }

        return $data;
    }
}
