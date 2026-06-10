<?php

declare(strict_types=1);

namespace Claw\Chat;

/**
 * One terminal conversation: reads user lines from stdin, prints replies to
 * stdout. Blank lines are skipped; EOF closes the conversation (returns null).
 */
final class ConsoleConversation implements ConversationInterface
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct($input, $output)
    {
        $this->input = $input;
        $this->output = $output;
    }

    public function receive(): ?string
    {
        while (($line = fgets($this->input)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    public function send(string $text): void
    {
        fwrite($this->output, $text . "\n");
    }
}
