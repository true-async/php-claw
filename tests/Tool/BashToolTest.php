<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Tool\BashTool;
use Claw\Tool\Risk;
use Testo\Assert;
use Testo\Test;

final class BashToolTest
{
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
            (new BashTool(sys_get_temp_dir()))->handle([]);
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
