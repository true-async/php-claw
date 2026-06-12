<?php

declare(strict_types=1);

namespace Claw\Chat;

/**
 * Terminal gateway: a single conversation on stdin/stdout. No token required —
 * useful for development and the tutorial.
 */
final class ConsoleChat implements ChatInterface
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /**
     * @param resource|null $input
     * @param resource|null $output
     */
    public function __construct($input = null, $output = null)
    {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDOUT;
    }

    public function accept(): ConversationInterface
    {
        return new AsyncConsoleConversation();
    }
}
