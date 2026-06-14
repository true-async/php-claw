<?php

declare(strict_types=1);

namespace Claw\Chat;

/**
 * One terminal conversation: reads user lines from stdin, prints replies to
 * stdout. Blank lines are skipped; EOF closes the conversation (returns null).
 *
 * Status is rendered as an overwritable line using ANSI carriage-return tricks:
 *   \r       — move cursor to the start of the current line
 *   \033[K   — erase from cursor to end of line
 * This keeps status and dialogue cleanly separated: status never scrolls.
 */
final class ConsoleConversation implements ConversationInterface
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    private bool $hasStatus = false;

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct($input, $output)
    {
        $this->input = $input;
        $this->output = $output;
    }

    public function id(): string
    {
        return 'console';
    }

    public function receive(): ?string
    {
        // Push any visible status (e.g. token count) into scroll history before
        // the user types, so their input never appears on the same line.
        if ($this->hasStatus) {
            fwrite($this->output, "\n");
            $this->hasStatus = false;
        }

        while (true) {
            fwrite($this->output, 'User: ');
            $line = fgets($this->input);

            if ($line === false) {
                return null;  // EOF / Ctrl+D
            }

            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }
    }

    public function send(string $text): void
    {
        $this->clearStatus();
        fwrite($this->output, 'Claw: ' . $text . "\n");
    }

    public function confirm(string $prompt): Approval
    {
        $this->clearStatus();
        fwrite($this->output, $prompt . ' [y = once / a = always / N = no] ');

        $line = fgets($this->input);

        return $line === false ? Approval::No : Approval::fromInput($line);
    }

    public function updateStatus(?Status $status): void
    {
        if ($status === null) {
            $this->clearStatus();

            return;
        }

        fwrite($this->output, "\r\033[K" . $status->render());
        $this->hasStatus = true;
    }

    private function clearStatus(): void
    {
        if ($this->hasStatus) {
            fwrite($this->output, "\r\033[K");
            $this->hasStatus = false;
        }
    }
}
