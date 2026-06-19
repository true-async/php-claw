<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Exceptions\WorkflowException;

/**
 * Where workflows live on disk, and the only door for writing them.
 *
 * Two scopes: a shared "Common" folder visible to everyone, and a private folder
 * per session. Each scope maps to its own namespace, so two sessions can hold a
 * workflow of the same name without a class-redeclaration clash in the single
 * TrueAsync process:
 *
 *   common  -> ClawWorkflow\Common\<Name>           @ <base>/Common/<Name>.php
 *   session -> ClawWorkflow\Session\S<sid>\<Name>   @ <base>/Session/S<sid>/<Name>.php
 *
 * A workflow file is pulled in by a plain namespace->path autoloader, so each
 * class is included exactly once per process.
 */
final class WorkflowStore
{
    private const string NS_ROOT = 'ClawWorkflow';

    /** @var array<string, true> base dirs already wired to the autoloader (per process). */
    private static array $autoloaded = [];

    private readonly string $sessionSegment;

    public function __construct(
        private readonly string $baseDir,
        string $sessionId,
    ) {
        $this->sessionSegment = 'S' . self::sanitize($sessionId);
        $this->registerAutoloader();
    }

    /**
     * Persist a workflow's PHP code: the only sanctioned way to write into the
     * workflow folders. Returns the file path.
     *
     * @throws WorkflowException on an invalid name or a write failure
     */
    public function write(string $name, string $code, bool $shared = false): string
    {
        $this->assertValidName($name);

        $dir = $shared ? $this->commonDir() : $this->sessionDir();
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new WorkflowException("cannot create workflow directory: {$dir}");
        }

        $path = $this->path($name, $shared);
        if (file_put_contents($path, $code) === false) {
            throw new WorkflowException("cannot write workflow: {$path}");
        }

        return $path;
    }

    /** Where a workflow's file lives (or would live), given its scope. */
    public function path(string $name, bool $shared = false): string
    {
        $this->assertValidName($name);

        return ($shared ? $this->commonDir() : $this->sessionDir()) . '/' . $name . '.php';
    }

    /** The fully-qualified class name a workflow must declare, given its scope. */
    public function classFor(string $name, bool $shared = false): string
    {
        $this->assertValidName($name);

        return $shared
            ? self::NS_ROOT . '\\Common\\' . $name
            : self::NS_ROOT . '\\Session\\' . $this->sessionSegment . '\\' . $name;
    }

    private function commonDir(): string
    {
        return $this->baseDir . '/Common';
    }

    private function sessionDir(): string
    {
        return $this->baseDir . '/Session/' . $this->sessionSegment;
    }

    /**
     * Map ClawWorkflow\... to a file under baseDir. The class name is built only
     * from validated identifiers, so no path traversal is possible.
     */
    private function registerAutoloader(): void
    {
        if (isset(self::$autoloaded[$this->baseDir])) {
            return;
        }
        self::$autoloaded[$this->baseDir] = true;

        $base = $this->baseDir;
        spl_autoload_register(static function (string $class) use ($base): void {
            if (!str_starts_with($class, self::NS_ROOT . '\\')) {
                return;
            }

            $relative = substr($class, \strlen(self::NS_ROOT) + 1);
            $path = $base . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }

    /** @throws WorkflowException */
    private function assertValidName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new WorkflowException("invalid workflow name '{$name}'");
        }
    }

    private static function sanitize(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $id) ?? '_';
    }
}
