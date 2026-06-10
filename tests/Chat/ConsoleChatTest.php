<?php

declare(strict_types=1);

namespace Tests\Chat;

use Claw\Chat\ConsoleChat;
use Testo\Assert;
use Testo\Test;

final class ConsoleChatTest
{
    #[Test]
    public function receivesNonBlankLinesThenNullAtEof(): void
    {
        $input = $this->memoryStream();
        fwrite($input, "hello\n\nbye\n");
        rewind($input);

        $conversation = (new ConsoleChat($input, $this->memoryStream()))->accept();

        Assert::same($conversation->receive(), 'hello');
        Assert::same($conversation->receive(), 'bye');   // blank line skipped
        Assert::same($conversation->receive(), null);    // EOF closes the conversation
    }

    #[Test]
    public function sendWritesLineToOutput(): void
    {
        $output = $this->memoryStream();

        (new ConsoleChat($this->memoryStream(), $output))->accept()->send('hi there');

        rewind($output);
        Assert::same(stream_get_contents($output), "hi there\n");
    }

    /**
     * @return resource
     */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('cannot open memory stream');
        }

        return $stream;
    }
}
