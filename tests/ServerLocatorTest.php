<?php

declare(strict_types=1);

namespace Tests;

use Claw\ServerLocator;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

/**
 * The record a server leaves so the CLI can find it: a round-trip through the file, and the
 * distinction the whole design turns on — a record on disk is not proof a server is up.
 */
final class ServerLocatorTest
{
    private string $base;

    public function __construct()
    {
        $this->base = sys_get_temp_dir() . '/claw-locator-' . bin2hex(random_bytes(6));
        mkdir($this->base);
    }

    public function __destruct()
    {
        foreach (glob($this->base . '/*/server.json') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->base . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }

        @rmdir($this->base);
    }

    /** A workspace no other test shares. */
    private function freshWorkspace(): string
    {
        $workspace = $this->base . '/' . bin2hex(random_bytes(6));
        mkdir($workspace);

        return $workspace;
    }

    private function fresh(): ServerLocator
    {
        return new ServerLocator($this->freshWorkspace());
    }

    #[Test]
    public function recordsAndReadsBackAnAddress(): void
    {
        $locator = $this->fresh();
        $locator->record('127.0.0.1', 8787);

        $found = $locator->locate();

        Assert::same(\is_array($found) ? $found['host'] : null, '127.0.0.1');
        Assert::same(\is_array($found) ? $found['port'] : null, 8787);
        Assert::same(\is_array($found) ? $found['pid'] : null, getmypid());
    }

    #[Test]
    public function locatesNothingWhenNoRecordExists(): void
    {
        Assert::same($this->fresh()->locate(), null);
    }

    #[Test]
    public function clearDropsTheRecord(): void
    {
        $locator = $this->fresh();
        $locator->record('127.0.0.1', 8787);
        $locator->clear();

        Assert::same($locator->locate(), null);
    }

    #[Test]
    public function ignoresAMalformedRecord(): void
    {
        $workspace = $this->freshWorkspace();
        file_put_contents($workspace . '/server.json', 'not json');

        Assert::same(new ServerLocator($workspace)->locate(), null);
    }

    #[Test]
    public function ignoresWellFormedJsonWithWrongTypes(): void
    {
        $workspace = $this->freshWorkspace();
        // a plausible hand-edit: the port quoted as a string
        file_put_contents($workspace . '/server.json', '{"host":"127.0.0.1","port":"8787"}');

        Assert::same(new ServerLocator($workspace)->locate(), null);
    }

    #[Test]
    public function clearLeavesAnotherProcessesRecordAlone(): void
    {
        $workspace = $this->freshWorkspace();
        // a record owned by some other, still-imaginable process — clear() must not remove it
        file_put_contents($workspace . '/server.json', '{"host":"127.0.0.1","port":8787,"pid":' . (getmypid() + 1) . '}');

        new ServerLocator($workspace)->clear();

        Assert::same(is_file($workspace . '/server.json'), true);
    }

    #[Test]
    public function aliveIsTrueOnlyWhileSomethingListens(): void
    {
        $locator = $this->fresh();
        $server = @stream_socket_server('tcp://127.0.0.1:0');

        if (!\is_resource($server)) {
            throw new SkipTest('no loopback socket available in this environment');
        }

        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        Assert::same($locator->alive('127.0.0.1', $port), true);

        fclose($server);

        Assert::same($locator->alive('127.0.0.1', $port), false);
    }
}
