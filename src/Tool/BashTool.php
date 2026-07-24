<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Run a shell command and return its combined output. Mutating.
 *
 * Depends only on a working directory and the OS. Under the TrueAsync reactor
 * proc_open's pipes are driven by libuv, so the read is non-blocking; whether a
 * given command is allowed is the permission layer's job, not this tool's.
 */
final class BashTool implements ToolInterface, ReportsResultMetaInterface
{
    /**
     * Signal numbers rather than `SIGTERM`/`SIGKILL`, because those constants come from `pcntl` and this
     * has to work without it — CI runs a build that does not load it, which is where the difference was
     * found. The numbers are fixed by POSIX on every platform this runs on.
     */
    private const int SIG_TERM = 15;

    private const int SIG_KILL = 9;

    /** The report of the most recent handle() — read back through {@see resultMeta()}. */
    private ?ToolResultMeta $last = null;

    public function __construct(
        private readonly string $cwd,
        private readonly ?Secrets $secrets = null,
        private readonly int $timeoutMs = 0,
    ) {
    }

    public function resultMeta(): ?ToolResultMeta
    {
        return $this->last;
    }

    public function name(): string
    {
        return 'bash';
    }

    public function description(): string
    {
        $names = ($this->secrets ?? Secrets::none())->names();

        if ($names === []) {
            return 'Run a shell command in the workspace and return its combined output and exit code.';
        }

        // Named, never valued. The model has to know a credential is reachable or it cannot use one; it
        // must not know what the credential IS, or it could put it somewhere that gets written down.
        return 'Run a shell command in the workspace and return its combined output and exit code. '
            . 'This project has credentials available to the shell as environment variables: $'
            . implode(', $', $names) . '. Use them by name — `curl -H "Authorization: Bearer $'
            . $names[0] . '" …` — and do not try to read or copy the values. A value printed verbatim is '
            . 'stripped from the output before you see it, but that is a safety net, not a wall: a value '
            . 'you transform (encode it, split it, put it in a URL a command then writes to a file) will '
            . 'NOT be stripped, and everything you print or write is recorded in this project\'s journal '
            . 'permanently. Pass them to the command that needs them and nowhere else.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'Shell command to run'],
            ],
            'required' => ['command'],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Mutating;
    }

    public function handle(array $input): string
    {
        $command = (string) ($input['command'] ?? '');

        if (trim($command) === '') {
            throw new ToolException('bash: "command" is required');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Scrubbed environment: keep only PATH/HOME/LANG so the HOST's secrets — the api key this
        // process runs on, a bot token — never reach the command. The PROJECT's own secrets are then
        // added deliberately, and they are the only ones here.
        //
        // As environment rather than by rewriting the command, which matters twice over: the shell does
        // the expansion, so quoting behaves exactly as anyone reading the command would expect; and the
        // command is never rewritten, so what the tracer records is what the MODEL wrote — `$STRIPE_KEY`
        // unexpanded — rather than the value it stood for.
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $this->cwd,
            'LANG' => 'C.UTF-8',
            ...($this->secrets ?? Secrets::none())->environment(),
        ];

        // POSIX `sh` on Unix; on Windows /bin/sh is not a valid path, so fall back
        // to a `sh` resolved from PATH (e.g. the one shipped with Git Bash).
        $shell = DIRECTORY_SEPARATOR === '\\' ? 'sh' : '/bin/sh';

        // Its own process group, so the whole tree can be signalled: `sh -c 'a | b & c'` leaves
        // grandchildren that a signal to the shell alone never reaches, and those are exactly the
        // long-running things a deadline exists for.
        $process = proc_open(
            [$shell, '-c', $command],
            $descriptors,
            $pipes,
            $this->cwd,
            $env,
            ['create_process_group' => true],
        );

        if (!\is_resource($process)) {
            throw new ToolException('bash: failed to start the command');
        }

        fclose($pipes[0]);
        [$stdout, $stderr, $exit, $timedOut] = $this->drain($process, $pipes);

        // Take the values back out before anyone — the model, the journal, a person reading the trace —
        // sees them. The command chose what to print; this decides what leaves the tool.
        $output = ($this->secrets ?? Secrets::none())->redact(trim($stdout . ($stderr !== '' ? "\n" . $stderr : '')));

        // The structured report, declared by the one party that KNOWS: the exit code is held right
        // here, the program that ran is named by the command, and its verdict line is lifted by the
        // tool that ran it — nobody downstream re-parses the text.
        $producer = self::producerOf($command);
        $this->last = new ToolResultMeta(
            $timedOut || $exit !== 0 ? 'fail' : 'ok',
            $producer,
            self::verdictOf($producer, $output),
        );

        if ($timedOut) {
            $seconds = round($this->timeoutMs / 1000, 1);

            return trim("[timed out after {$seconds}s — the command was killed]\n" . $output);
        }

        if ($exit !== 0) {
            return trim("[exit {$exit}]\n" . $output);
        }

        return $output === '' ? '(no output)' : $output;
    }

    /** The known program named by the command line, or '' — drives the dashboard's badge and the verdict lift. */
    private static function producerOf(string $command): string
    {
        return match (true) {
            preg_match('/\bphpunit\b/', $command) === 1 => 'phpunit',
            preg_match('/\bphp\s+-l\b/', $command) === 1 => 'php-lint',
            preg_match('/\bphpstan\b/', $command) === 1 => 'phpstan',
            preg_match('/\bcomposer\b/', $command) === 1 => 'composer',
            default => '',
        };
    }

    /**
     * The producer's own verdict line, lifted verbatim from output a person would scan for anyway —
     * so a viewer can show "OK (3 tests, 5 assertions)" without opening the body. Empty when the
     * producer is unknown or printed nothing recognizable; the status stands on its own.
     */
    private static function verdictOf(string $producer, string $output): string
    {
        $line = match ($producer) {
            'phpunit' => self::firstMatch('/^(OK \(.+\)|OK, but .+|FAILURES!|ERRORS!|No tests executed!)$/m', $output)
                ?? self::firstMatch('/^Tests: .+$/m', $output),
            'php-lint' => self::firstMatch('/^No syntax errors detected.*$/m', $output)
                ?? self::firstMatch('/^(?:PHP )?Parse error:.*$/m', $output),
            'phpstan' => self::firstMatch('/^\s*\[(?:OK|ERROR)\].*$/m', $output),
            default => null,
        };

        return $line === null ? '' : trim($line);
    }

    private static function firstMatch(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) === 1 ? $m[0] : null;
    }

    /**
     * Read the pipes to the end, or until the deadline — and if the deadline comes first, KILL the
     * command rather than merely stopping waiting for it.
     *
     * This exists because the obvious arrangement does not work, and the evidence is worth keeping. The
     * deadline used to live entirely in {@see \Claw\Exec\TimeoutMiddleware}, which cancels the coroutine
     * this runs in. Measured: a `sleep 40` capped at two seconds returned "timed out after 2s" on time
     * and was STILL RUNNING afterwards — one survivor counted out of /proc, alive for as long as the PHP
     * process lived, which in the dashboard server is its whole lifetime.
     *
     * Probed further, with a `catch` around the read that logged whether it was ever reached: it was
     * NOT. A coroutine blocked in `stream_get_contents` does not unwind, so a cancellation cannot reach
     * anything here — no `finally`, no destructor, nothing that could call `proc_terminate`. Which is
     * why the deadline has to be held by the only thing that owns the process handle, and why the read
     * must never block: something has to be awake to notice the time.
     *
     * `Async\delay` between polls rather than `usleep` — it suspends this coroutine and blocks nothing,
     * which on a server handling other work is the whole difference.
     *
     * @param resource             $process
     * @param array<int, resource> $pipes
     *
     * @return array{0: string, 1: string, 2: int, 3: bool} stdout, stderr, exit code, timed out
     */
    private function drain($process, array $pipes): array
    {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = $this->timeoutMs > 0 ? microtime(true) + ($this->timeoutMs / 1000) : null;
        $stdout = '';
        $stderr = '';

        while (true) {
            $stdout .= (string) fread($pipes[1], 65536);
            $stderr .= (string) fread($pipes[2], 65536);

            $status = proc_get_status($process);

            if (!$status['running']) {
                // Drain whatever is still buffered: the process is gone but its output is not.
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return [$stdout, $stderr, $status['exitcode'], false];
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                self::kill($process, $status['pid']);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return [$stdout, $stderr, -1, true];
            }

            \Async\delay(20);
        }
    }

    /**
     * Stop the command and everything it started. The GROUP first — a pipeline is several processes and
     * signalling the shell alone leaves the rest running, which is how a "killed" command goes on using
     * the machine. TERM before KILL so anything with cleanup gets its chance, and a short grace period
     * because the difference between the two is a process tree that tidies up and one that does not.
     *
     * @param resource $process
     */
    private static function kill($process, int $pid): void
    {
        if ($pid > 0 && \function_exists('posix_kill')) {
            @posix_kill(-$pid, self::SIG_TERM);   // negative pid = the whole process group
        }

        @proc_terminate($process, self::SIG_TERM);
        \Async\delay(100);

        if (@proc_get_status($process)['running']) {
            if ($pid > 0 && \function_exists('posix_kill')) {
                @posix_kill(-$pid, self::SIG_KILL);
            }

            @proc_terminate($process, self::SIG_KILL);
        }
    }

    /**
     * Read the exit code back out of a result this tool produced; null when there is no prefix, which
     * means the command succeeded (a zero exit is not marked).
     *
     * It lives here, beside the line that writes the prefix, because the caller that needs it is in
     * another namespace: {@see \Claw\Agent\DefaultTurnLoop} counts failed attempts and cannot see a
     * non-zero exit any other way — the executor marks a command that ran and reported failure as a
     * SUCCESSFUL tool call, which it was. Parsing a format from a different file is how a guard goes
     * quietly blind the day the format changes; parsing it from this one cannot.
     */
    public static function exitCode(string $result): ?int
    {
        return preg_match('/^\[exit (\d+)\]/', $result, $m) === 1 ? (int) $m[1] : null;
    }
}
