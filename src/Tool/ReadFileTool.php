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
        return 'Read a UTF-8 text file from the workspace, given a workspace-relative path. To read a '
            . 'WINDOW of a large file, pass "offset" (1-based start line) and/or "limit" (number of lines) '
            . '— the window comes back with line numbers, so it pairs with grep (which gives you the line) '
            . 'and edit. A plain read (no offset/limit) returns the raw text.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative file path'],
                'offset' => ['type' => 'integer', 'description' => '1-based line to start reading at (for a window of a large file)'],
                'limit' => ['type' => 'integer', 'description' => 'How many lines to read from offset'],
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

        $offset = (int) ($input['offset'] ?? 0);
        $limit = (int) ($input['limit'] ?? 0);

        if ($offset < 0 || $limit < 0) {
            throw new ToolException('read_file: "offset" and "limit" must be zero or positive');
        }

        // A WINDOW read — line-numbered so it orients (grep gives the line, this reads around it). Kept
        // separate from the plain read below, which stays raw so its text can be copied verbatim into edit.
        if ($offset > 0 || $limit > 0) {
            return $this->window($real, $path, max(1, $offset), $limit);
        }

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
            return substr($data, 0, $this->maxBytes) . "\n... [truncated — read a later part with offset]";
        }

        return $data;
    }

    /**
     * Read $limit lines starting at line $offset (1-based), line-numbered. Uses SplFileObject so a window
     * near the end of a huge file does not pull the whole thing into memory — the point of a windowed read.
     */
    private function window(string $real, string $path, int $offset, int $limit): string
    {
        $handle = @fopen($real, 'r');

        if ($handle === false) {
            throw new ToolException("read_file: cannot read {$path}");
        }

        $lines = [];
        $current = 0;

        while (($line = fgets($handle)) !== false) {
            ++$current;

            if ($current < $offset) {
                continue;   // scroll to the window's start
            }

            $lines[] = rtrim($line, "\n");

            if ($limit > 0 && \count($lines) >= $limit) {
                break;
            }
        }

        fclose($handle);

        if ($lines === []) {
            return "read_file: {$path} has no line {$offset}";
        }

        $secrets = $this->secrets ?? Secrets::none();
        $out = [];

        foreach ($lines as $i => $line) {
            // Redact per line, then prefix the number — so the line number can never be scrubbed as if it
            // were part of a secret, and a redaction stays confined to its own line.
            $out[] = sprintf('%6d→%s', $offset + $i, $secrets->redact($line));
        }

        return implode("\n", $out);
    }
}
