<?php

declare(strict_types=1);

namespace Claw;

use Claw\Exceptions\ClawException;
use Claw\Http\HttpClientInterface;

/**
 * The CLI's side of the one-writer rule: it does not open a project db, it asks the server to. The
 * server is the only process that writes, so the CLI finds one ({@see ServerLocator}) and, when there
 * is none, starts one and waits for it to answer — then it is a thin client, a manager that hands work
 * off rather than doing it.
 *
 * Autospawn needs to launch php WITH the server extension, which the CLI itself runs without; the
 * command is rebuilt from {@see PHP_BINARY} and `CLAW_SERVER_EXTENSION` (the `.so`, defaulting to the
 * name resolved from php's extension_dir).
 */
final readonly class ServerClient
{
    private const int SPAWN_TIMEOUT_MS = 10_000;
    private const int POLL_MS = 100;

    private ServerLocator $locator;

    public function __construct(
        private HttpClientInterface $http,
        private string $workspace,
        private string $root,
        private string $host = '127.0.0.1',
        private int $port = 8787,
    ) {
        $this->locator = new ServerLocator($workspace);
    }

    /**
     * The address of a server already answering for this workspace, or null when none is — so the CLI
     * can say it is about to start one before {@see ensure()} does.
     *
     * @return array{host: string, port: int}|null
     */
    public function running(): ?array
    {
        $found = $this->locator->locate();

        return $found !== null && $this->locator->alive($found['host'], $found['port'])
            ? ['host' => $found['host'], 'port' => $found['port']]
            : null;
    }

    /**
     * The address of a live server for this workspace, starting one if none answers.
     *
     * @return array{host: string, port: int}
     *
     * @throws ClawException when no server can be reached and none can be started
     */
    public function ensure(): array
    {
        return $this->running() ?? $this->spawn();
    }

    /**
     * Ask the server to start the issue's solver. Returns once the run is launched — the server owns
     * it from there.
     *
     * @param array{host: string, port: int} $server
     *
     * @throws ClawException when the server refuses the start
     */
    public function startRun(array $server, string $key, string $issueId): void
    {
        $url = sprintf(
            'http://%s:%d/api/projects/%s/issues/%s/start',
            $server['host'],
            $server['port'],
            rawurlencode($key),
            rawurlencode($issueId),
        );

        $response = $this->http->post($url, '', ['Content-Type: application/json']);

        if ($response->status === 202) {
            return;
        }

        if ($response->status === 409) {
            throw new ClawException('a run for this issue is already active');
        }

        throw new ClawException("the server refused to start the run (HTTP {$response->status})");
    }

    /**
     * The command that launches a server, or null when one cannot be built (no php binary, or bin/claw
     * is not where it should be). Pure so the shape can be asserted without spawning anything.
     */
    public static function spawnCommand(string $root, string $host, int $port): ?string
    {
        $claw = $root . '/bin/claw';

        if (!is_file($claw)) {
            return null;
        }

        $extension = getenv('CLAW_SERVER_EXTENSION');
        $extension = $extension === false || $extension === '' ? 'true_async_server.so' : $extension;

        return sprintf(
            '%s -d extension=%s %s serve --host %s --port %d',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($extension),
            escapeshellarg($claw),
            escapeshellarg($host),
            $port,
        );
    }

    /**
     * Start a server and wait for it to answer.
     *
     * @return array{host: string, port: int}
     *
     * @throws ClawException
     */
    private function spawn(): array
    {
        $command = self::spawnCommand($this->root, $this->host, $this->port);

        if ($command === null) {
            throw new ClawException('cannot start a server: no php binary or bin/claw to launch');
        }

        // setsid puts the server in its own session so it outlives this CLI and holds no terminal; the
        // descriptor spec points its stdio at files, so it never inherits — and never blocks — the pipe
        // this process was invoked through. The loser of a bind race exits on its own duplicate-guard,
        // and the poll below simply finds the winner instead.
        $log = $this->workspace . \DIRECTORY_SEPARATOR . 'server.log';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];
        // --fork so setsid forks the server and returns at once: without it setsid execs the server in
        // place and proc_close would block on it for the server's whole life.
        $process = @proc_open('setsid --fork ' . $command, $descriptors, $pipes);

        if (\is_resource($process)) {
            proc_close($process);
        }

        $waited = 0;

        while ($waited < self::SPAWN_TIMEOUT_MS) {
            $found = $this->locator->locate();

            if ($found !== null && $this->locator->alive($found['host'], $found['port'])) {
                return ['host' => $found['host'], 'port' => $found['port']];
            }

            usleep(self::POLL_MS * 1000);
            $waited += self::POLL_MS;
        }

        throw new ClawException(
            'the server did not come up within ' . (self::SPAWN_TIMEOUT_MS / 1000) . 's — is the server '
            . 'extension available? (set CLAW_SERVER_EXTENSION to its .so path)',
        );
    }
}
