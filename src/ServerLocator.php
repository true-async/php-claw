<?php

declare(strict_types=1);

namespace Claw;

/**
 * Where a workspace's dashboard server can be reached, written to a file so the CLI finds it without
 * guessing a port. A workspace has at most one server — it owns every project db under it — so the
 * record is one file at the workspace root, not one per project.
 *
 * The record is a hint, never a promise the server is up: it can outlive a crash. Liveness is a
 * separate question ({@see alive()}), and the truthful test of it is whether the address still
 * accepts a connection — a pid can be reused, a file can be stale.
 */
final readonly class ServerLocator
{
    private const string FILE = 'server.json';

    public function __construct(private string $workspace)
    {
    }

    /**
     * Record the address this server bound to. Best-effort: a failure to write only costs discovery.
     *
     * A wildcard bind is stored as the loopback it can actually be reached at — `0.0.0.0` is where the
     * server listens, not an address anyone connects to, and the record exists to be connected to.
     */
    public function record(string $host, int $port): void
    {
        @file_put_contents($this->path(), (string) json_encode([
            'host' => self::reachable($host),
            'port' => $port,
            'pid' => getmypid(),
        ]));
    }

    /**
     * The recorded address, or null when nothing readable is on file.
     *
     * @return array{host: string, port: int, pid: int}|null
     */
    public function locate(): ?array
    {
        $raw = @file_get_contents($this->path());

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!\is_array($data) || !\is_string($data['host'] ?? null) || !\is_int($data['port'] ?? null)) {
            return null;
        }

        if ($data['port'] < 1 || $data['port'] > 65535) {
            return null;
        }

        return ['host' => $data['host'], 'port' => $data['port'], 'pid' => \is_int($data['pid'] ?? null) ? $data['pid'] : 0];
    }

    /**
     * Drop the record, but only if it is still ours.
     *
     * A process that lost the bind race overwrote this file with its own pid on the way to failing;
     * the pid check stops it from then deleting the winner's record. Only the process whose pid the
     * record carries may remove it — anyone else leaves it for {@see alive()} to judge.
     */
    public function clear(): void
    {
        $found = $this->locate();

        if ($found !== null && $found['pid'] === getmypid()) {
            @unlink($this->path());
        }
    }

    /** Whether something is accepting connections at that address right now. */
    public function alive(string $host, int $port): bool
    {
        $socket = @fsockopen(self::reachable($host), $port, $errno, $errstr, 0.3);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /** A wildcard listen address turned into the loopback a client can actually connect to. */
    private static function reachable(string $host): string
    {
        return match ($host) {
            '0.0.0.0' => '127.0.0.1',
            '::', '[::]' => '::1',
            default => $host,
        };
    }

    private function path(): string
    {
        return $this->workspace . \DIRECTORY_SEPARATOR . self::FILE;
    }
}
