<?php

declare(strict_types=1);

namespace Claw;

use Claw\Exceptions\ClawException;
use Claw\Http\HttpClientInterface;
use Claw\Http\HttpResponse;

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
    private const int ATTACH_TIMEOUT_MS = 10_000;
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
     * Send a person's reply to a run's open gate.
     *
     * $questionId names the question being answered (0 to omit). The server refuses a reply aimed at a
     * question that has since been answered and replaced — the id is what closes the terminal-vs-dashboard
     * race the blocking prompt opens.
     *
     * @param array{host: string, port: int} $server
     *
     * @throws ClawException when the run is not waiting, the question has moved on (409), or the server
     *                       refuses the answer
     */
    public function answer(array $server, string $key, string $issueId, string $text, int $questionId = 0): void
    {
        $url = sprintf(
            'http://%s:%d/api/projects/%s/issues/%s/answer',
            $server['host'],
            $server['port'],
            rawurlencode($key),
            rawurlencode($issueId),
        );

        $fields = ['text' => $text];

        if ($questionId > 0) {
            $fields['question'] = $questionId;
        }

        $body = json_encode($fields);

        if ($body === false) {
            throw new ClawException('cannot send the answer: it is not valid text');
        }

        $response = $this->http->post($url, $body, ['Content-Type: application/json']);

        if ($response->status === 202) {
            return;
        }

        if ($response->status === 409) {
            throw new ClawException($this->errorText($response) ?? 'the run is not waiting for an answer right now');
        }

        throw new ClawException("the server refused the answer (HTTP {$response->status})");
    }

    /** The server's own `error` message from a JSON body, or null when there is not one to show. */
    private function errorText(HttpResponse $response): ?string
    {
        $data = json_decode($response->body, true);

        return \is_array($data) && \is_string($data['error'] ?? null) ? $data['error'] : null;
    }

    /**
     * The runs recorded for an issue, oldest first — how the CLI finds the run a start produced, since
     * the id is minted inside the run and not returned by the start.
     *
     * @param array{host: string, port: int} $server
     *
     * @return list<array{id: string, status: string}>
     */
    public function runs(array $server, string $key, string $issueId): array
    {
        $url = sprintf(
            'http://%s:%d/api/projects/%s/issues/%s/runs',
            $server['host'],
            $server['port'],
            rawurlencode($key),
            rawurlencode($issueId),
        );

        $runs = [];

        foreach ($this->getList($url) as $run) {
            if (\is_array($run) && isset($run['id'])) {
                $runs[] = ['id' => (string) $run['id'], 'status' => (string) ($run['status'] ?? '')];
            }
        }

        return $runs;
    }

    /**
     * A run's trace rows past $since — the poll the tail renders from, the same rows an SSE subscriber
     * would receive.
     *
     * @param array{host: string, port: int} $server
     *
     * @return list<array<string, mixed>>
     */
    public function trace(array $server, string $key, string $runId, int $since): array
    {
        $url = sprintf(
            'http://%s:%d/api/projects/%s/runs/%s/trace?since=%d',
            $server['host'],
            $server['port'],
            rawurlencode($key),
            rawurlencode($runId),
            $since,
        );

        $rows = [];

        foreach ($this->getList($url) as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * GET a URL and decode a top-level JSON list, or [] on any error. These endpoints return bare
     * arrays (a run list, trace rows), which {@see HttpResponse::json()} refuses by contract — it is for
     * string-keyed objects — so the body is decoded here instead.
     *
     * @return list<mixed>
     */
    private function getList(string $url): array
    {
        $response = $this->http->get($url);

        if (!$response->isOk()) {
            return [];
        }

        $data = json_decode($response->body, true);

        return \is_array($data) && array_is_list($data) ? $data : [];
    }

    /**
     * The highest run id recorded for an issue, or 0 when it has none — the mark a new run must beat.
     *
     * @param array{host: string, port: int} $server
     */
    public function latestRunId(array $server, string $key, string $issueId): int
    {
        $highest = 0;

        foreach ($this->runs($server, $key, $issueId) as $run) {
            $highest = max($highest, (int) $run['id']);
        }

        return $highest;
    }

    /**
     * Wait for the run a start just produced: the first run whose id beats $afterId. Null when none
     * appears in time — the caller falls back to telling the user to watch elsewhere.
     *
     * @param array{host: string, port: int} $server
     */
    public function awaitRun(array $server, string $key, string $issueId, int $afterId): ?string
    {
        $waited = 0;

        while ($waited < self::ATTACH_TIMEOUT_MS) {
            foreach ($this->runs($server, $key, $issueId) as $run) {
                if ((int) $run['id'] > $afterId) {
                    return $run['id'];
                }
            }

            usleep(self::POLL_MS * 1000);
            $waited += self::POLL_MS;
        }

        return null;
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
