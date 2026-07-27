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
        private ?Secrets  $secrets = null,
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
        $path = (string) ($input['path'] ?? '');

        if ($path === '') {
            throw new ToolException('read_file: "path" is required');
        }

        $real = $this->workspace->resolveExisting($path);

        $data = file_get_contents($real, false, null, 0, $this->maxBytes + 1);

        if ($data === false) {
            throw new ToolException("read_file: cannot read {$path}");
        }

        // BEFORE truncating, and this order is the whole point: a value cut across the byte limit would
        // match nothing and both halves would survive.
        //
        // Reading is guarded as well as running because the value gets OUT through a file. `git remote
        // set-url origin "https://x:$GH_TOKEN@github.com/…"` — the canonical use of a token, and exactly
        // what the bash tool invites — writes the expanded URL into `.git/config`. `gh auth login` and
        // npm and pip cache credentials under $HOME, which for a run IS the project folder. Any of those
        // files read back on a later turn would hand the value to the model and to the journal, and
        // `.git/config` is not a credential-shaped name that any blocklist would have caught.
        $data = ($this->secrets ?? Secrets::none())->redact($data);

        if (\strlen($data) > $this->maxBytes) {
            return substr($data, 0, $this->maxBytes) . "\n... [truncated]";
        }

        return $data;
    }
}
