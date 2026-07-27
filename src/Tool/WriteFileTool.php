<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Write a text file in the workspace (creates or overwrites). Mutating.
 */
final readonly class WriteFileTool implements ToolInterface
{
    public function __construct(private Workspace $workspace)
    {
    }

    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Write a UTF-8 text file in the workspace (creates or overwrites it).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative file path'],
                'content' => ['type' => 'string', 'description' => 'File content'],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function effects(): array
    {
        return [Effect::Write];
    }

    public function risk(): Risk
    {
        return Risk::Mutating;
    }

    public function handle(array $input): string
    {
        $path = (string) ($input['path'] ?? '');

        if ($path === '') {
            throw new ToolException('write_file: "path" is required');
        }

        $content = (string) ($input['content'] ?? '');
        $real = $this->workspace->resolveForWrite($path);

        if (file_put_contents($real, $content) === false) {
            throw new ToolException("write_file: cannot write {$path}");
        }

        return 'Wrote ' . strlen($content) . " bytes to {$path}";
    }
}
