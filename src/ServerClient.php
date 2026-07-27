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
     * The argv that launches a server, or null when one cannot be built (bin/claw is not where it
     * should be). Returned as a list, not a shell string, so it carries no quoting to get wrong and can
     * be handed to proc_open's array form on either platform. Pure, so the shape can be asserted without
     * spawning anything.
     *
     * The extension defaults to the `.so` name php resolves from its extension_dir; on Windows (a `.dll`)
     * or a non-standard install, set CLAW_SERVER_EXTENSION to the file to load.
     *
     * @return list<string>|null
     */
    public static function spawnCommand(string $root, string $host, int $port): ?array
    {
        $claw = $root . \DIRECTORY_SEPARATOR . 'bin' . \DIRECTORY_SEPARATOR . 'claw';

        if (!is_file($claw)) {
            return null;
        }

        $extension = getenv('CLAW_SERVER_EXTENSION');
        $extension = $extension === false || $extension === '' ? 'true_async_server.so' : $extension;

        return [
            \PHP_BINARY, '-d', 'extension=' . $extension,
            $claw, 'serve', '--host', $host, '--port', (string) $port,
        ];
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
            throw new ClawException('cannot start a server: bin/claw is not where it should be');
        }

        $this->launchDetached($command);

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
            . 'extension available? (set CLAW_SERVER_EXTENSION to its .so/.dll path)',
        );
    }

    /**
     * Start the server as a process that outlives this CLI and holds none of its stdio.
     *
     * The two platforms detach differently, and neither is the shell `&` a terminal-launched CLI would
     * leak its console through. POSIX: `setsid --fork` gives the server its own session and returns at
     * once (without --fork setsid execs in place and proc_close would block for the server's life).
     * Windows: `create_new_console` cuts it from this console so it survives the CLI exiting. Either way
     * the descriptor spec points stdio at files, so the server never inherits the pipe we were called
     * through.
     *
     * @param list<string> $command
     */
    private function launchDetached(array $command): void
    {
        $log = $this->workspace . \DIRECTORY_SEPARATOR . 'server.log';
        $windows = \PHP_OS_FAMILY === 'Windows';

        $descriptors = [
            0 => ['file', $windows ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        if ($windows) {
            $process = @proc_open($command, $descriptors, $pipes, null, null, [
                'bypass_shell' => true,
                'create_new_console' => true,
                'create_process_group' => true,
            ]);
        } else {
            $process = @proc_open(['setsid', '--fork', ...$command], $descriptors, $pipes);
        }

        if (\is_resource($process)) {
            proc_close($process);
        }
    }
}
