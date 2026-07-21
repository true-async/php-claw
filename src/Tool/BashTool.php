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
final readonly class BashTool implements ToolInterface
{
    public function __construct(
        private string $cwd,
        private ?Secrets $secrets = null,
    ) {
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

        $process = proc_open([$shell, '-c', $command], $descriptors, $pipes, $this->cwd, $env);

        if (!\is_resource($process)) {
            throw new ToolException('bash: failed to start the command');
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        // Take the values back out before anyone — the model, the journal, a person reading the trace —
        // sees them. The command chose what to print; this decides what leaves the tool.
        $output = ($this->secrets ?? Secrets::none())->redact(trim($stdout . ($stderr !== '' ? "\n" . $stderr : '')));

        if ($exit !== 0) {
            return trim("[exit {$exit}]\n" . $output);
        }

        return $output === '' ? '(no output)' : $output;
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
