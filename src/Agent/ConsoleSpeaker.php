<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * A {@see SpeakerInterface} backed by the console: it writes the question and a `Request> ` prompt to
 * the output stream and reads the person's typed line from the input stream as the reply. This is the
 * plain driver behind {@see \Claw\Workflow\WorkflowAbstract::ask()} — put an {@see AgentSpeaker} (or a
 * Telegram / queue driver) in the same slot and the very same ask() is answered by an agent instead,
 * with no change to the workflow. Streams are injected, so it is testable without real stdio.
 */
final class ConsoleSpeaker implements SpeakerInterface
{
    /**
     * @param resource $input  where the answer is read from (e.g. STDIN)
     * @param resource $output where the question and prompt are written (e.g. STDOUT)
     */
    public function __construct(
        private $input,
        private $output,
    ) {
    }

    public function name(): SpeakerRole
    {
        return SpeakerRole::Human;
    }

    public function reply(string $incoming): ?string
    {
        if (\is_resource($this->output)) {
            fwrite($this->output, "\n" . $incoming . "\nRequest> ");
        }

        $line = \is_resource($this->input) ? fgets($this->input) : false;

        // EOF / closed input is "no one is there to answer" (null = pass it up), distinct from a
        // deliberately blank line (''), which is a real empty answer. Returning '' on EOF would make
        // the turn loop treat absence as an answer and churn empty user turns until the next cap.
        return $line === false ? null : rtrim($line, "\r\n");
    }
}
