<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Confines file/bash tools to one directory. Resolves workspace-relative paths
 * and rejects absolute paths and `..` escapes (checked after realpath, so any
 * traversal that lands outside the root is caught).
 */
final readonly class Workspace
{
    private string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);

        if ($real === false) {
            throw new ToolException("Workspace directory does not exist: {$root}");
        }

        $this->root = $real;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Resolve an existing file inside the workspace (for reads). */
    public function resolveExisting(string $path): string
    {
        $real = realpath($this->join($path));

        if ($real === false) {
            throw new ToolException("No such file: {$path}");
        }

        $this->assertInside($real);

        return $real;
    }

    /** Resolve a path for writing; the file may not exist but its parent must. */
    public function resolveForWrite(string $path): string
    {
        $full = $this->join($path);

        $parent = realpath(\dirname($full));

        if ($parent === false) {
            throw new ToolException("Directory does not exist for: {$path}");
        }

        $this->assertInside($parent);

        return $parent . DIRECTORY_SEPARATOR . basename($full);
    }

    private function join(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1) {
            throw new ToolException("Path must be workspace-relative: {$path}");
        }

        return $this->root . DIRECTORY_SEPARATOR . $path;
    }

    private function assertInside(string $real): void
    {
        if ($real !== $this->root && !str_starts_with($real, $this->root . DIRECTORY_SEPARATOR)) {
            throw new ToolException("Path escapes the workspace: {$real}");
        }
    }
}
