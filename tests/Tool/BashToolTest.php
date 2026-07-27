<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\BashTool;
use Claw\Tool\Effect;
use Claw\Tool\Risk;
use Testo\Assert;
use Testo\Test;

final class BashToolTest
{
    /**
     * A timed-out command is actually KILLED, not merely stopped being waited for.
     *
     * Measured before this existed: a `sleep 40` capped at two seconds returned "timed out after 2s" on
     * time and was still running afterwards — for as long as the PHP process lived, which in the
     * dashboard server is its whole lifetime, so every timeout leaked one.
     *
     * The obvious arrangement cannot work, and that was probed too. The deadline used to live entirely
     * in the surrounding middleware, which cancels this coroutine — and a coroutine blocked in
     * `stream_get_contents` does not unwind, so nothing here was ever reached. A `catch` placed around
     * the read to find out logged nothing at all. The deadline has to be held by the thing that owns
     * the process handle, and the read has to stay awake to notice it.
     */
    #[Test]
    public function aTimedOutCommandIsKilledAndSaysSo(): void
    {
        $marker = 'claw-test-' . bin2hex(random_bytes(4));
        $bash = new BashTool(sys_get_temp_dir(), null, 1500);

        $out = $bash->handle(['command' => "echo starting; sleep 30 # {$marker}"]);

        Assert::true(str_contains($out, 'timed out'));
        Assert::true(str_contains($out, 'killed'));
        Assert::true(str_contains($out, 'starting'));   // what it managed to say is kept

        Assert::same(self::aliveWith($marker), 0);
    }

    /** A command that finishes in time is untouched by any of this. */
    #[Test]
    public function aCommandThatFinishesInTimeIsUnaffected(): void
    {
        $bash = new BashTool(sys_get_temp_dir(), null, 5000);

        Assert::same($bash->handle(['command' => 'echo fine']), 'fine');
        Assert::true(str_contains($bash->handle(['command' => 'echo oops >&2; exit 3']), '[exit 3]'));
        Assert::true(str_contains($bash->handle(['command' => 'echo oops >&2; exit 3']), 'oops'));
    }

    /**
     * A read-only shell runs reads but refuses the obvious writes — the guard a REVIEWER needs so a shell
     * handed to it for running checks cannot edit the very file it is judging (a live supervisor reached
     * for `sed -i` on that file). Naive by design: it catches the direct write, not a write hidden inside
     * an interpreter.
     */
    #[Test]
    public function aReadOnlyShellRefusesTheObviousWrites(): void
    {
        $bash = new BashTool(sys_get_temp_dir(), null, 5000, readOnly: true);

        $writes = ["sed -i 's/a/b/' x.php", 'echo hi > out.txt', 'echo x >> log', 'rm x.php', 'git commit -am wip'];

        foreach ($writes as $write) {
            $threw = false;

            try {
                $bash->handle(['command' => $write]);
            } catch (ToolException $e) {
                $threw = str_contains($e->getMessage(), 'read-only');
            }

            Assert::true($threw);   // each write is refused before it runs
        }
    }

    /** ...and it STILL runs a read — that is the whole point: a reviewer must be able to run its checks. */
    #[Test]
    public function aReadOnlyShellStillRunsReads(): void
    {
        $bash = new BashTool(sys_get_temp_dir(), null, 5000, readOnly: true);

        Assert::same($bash->handle(['command' => 'echo checking']), 'checking');
        Assert::same($bash->handle(['command' => 'true 2>/dev/null']), '(no output)');   // stderr redirect is fine
    }

    /** The effect a palette filters on: a normal shell writes, its read-only clone does not. */
    #[Test]
    public function readOnlyModeIsReflectedInTheToolsEffects(): void
    {
        $normal = new BashTool(sys_get_temp_dir());
        $readOnly = $normal->readOnly();

        Assert::true(\in_array(Effect::Write, $normal->effects(), true));
        Assert::false(\in_array(Effect::Write, $readOnly->effects(), true));
        Assert::true(\in_array(Effect::Read, $readOnly->effects(), true));
    }

    /** How many processes carry $marker — read from /proc, because a `pgrep` would match its own shell. */
    private static function aliveWith(string $marker): int
    {
        $n = 0;

        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $cmd = @file_get_contents($file);

            if ($cmd !== false && str_contains($cmd, $marker)) {
                $n++;
            }
        }

        return $n;
    }

    #[Test]
    public function runsCommandAndReturnsOutput(): void
    {
        $bash = new BashTool($this->dir());

        Assert::same($bash->handle(['command' => 'echo hello']), 'hello');
        Assert::same($bash->name(), 'bash');
        Assert::same($bash->risk(), Risk::Mutating);
    }

    #[Test]
    public function capturesStderrAndExitCode(): void
    {
        $bash = new BashTool($this->dir());

        Assert::true(str_contains($bash->handle(['command' => 'echo oops 1>&2']), 'oops'));
        Assert::true(str_contains($bash->handle(['command' => 'exit 3']), '[exit 3]'));
    }

    #[Test]
    public function runsInCwdWithScrubbedEnv(): void
    {
        $dir = $this->dir();
        $bash = new BashTool($dir);

        $pwd = $bash->handle(['command' => 'pwd']);

        if (DIRECTORY_SEPARATOR === '\\') {
            // MSYS/Git `sh` reports a POSIX path (/tmp/…) that can't equal PHP's
            // realpath (C:\…\Temp\…); assert it ran in the right dir by name.
            Assert::true(str_ends_with($pwd, '/' . basename($dir)));
        } else {
            Assert::same($pwd, realpath($dir));
        }

        putenv('CLAW_LEAK=secret');
        Assert::same($bash->handle(['command' => 'echo "[$CLAW_LEAK]"']), '[]');   // not leaked
        putenv('CLAW_LEAK');
    }

    #[Test]
    public function requiresCommand(): void
    {
        $threw = false;

        try {
            new BashTool(sys_get_temp_dir())->handle([]);
        } catch (ToolException $e) {
            $threw = true;
        }

        Assert::true($threw);
    }

    private function dir(): string
    {
        $dir = sys_get_temp_dir() . '/claw_bash_' . bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }
}
