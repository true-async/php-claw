<?php

declare(strict_types=1);

namespace Tests\Agent;

use Claw\Agent\ConsoleSpeaker;
use Claw\Agent\SpeakerRole;
use Testo\Assert;
use Testo\Test;

final class ConsoleSpeakerTest
{
    #[Test]
    public function printsTheQuestionWithARequestPromptAndReturnsTheTypedLine(): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        if ($in === false || $out === false) {
            throw new \RuntimeException('cannot open memory stream');
        }
        fwrite($in, "blue\n");
        rewind($in);

        $speaker = new ConsoleSpeaker($in, $out);

        Assert::same($speaker->name(), SpeakerRole::Human);
        Assert::same($speaker->reply('What colour?'), 'blue');   // the typed line, newline stripped

        rewind($out);
        $shown = (string) stream_get_contents($out);
        Assert::true(str_contains($shown, 'What colour?'));      // the question is shown
        Assert::true(str_contains($shown, 'Request>'));          // with the Request> prompt
    }

    #[Test]
    public function returnsNullOnEofButEmptyStringOnABlankLine(): void
    {
        $out = fopen('php://memory', 'r+');
        if ($out === false) {
            throw new \RuntimeException('cannot open memory stream');
        }

        // A deliberately blank line is a real (empty) answer.
        $blank = fopen('php://memory', 'r+');
        if ($blank === false) {
            throw new \RuntimeException('cannot open memory stream');
        }
        fwrite($blank, "\n");
        rewind($blank);
        Assert::same((new ConsoleSpeaker($blank, $out))->reply('q'), '');

        // Exhausted input (EOF) means no one is there to answer -> null, so the loop stops
        // instead of treating absence as an empty answer and churning.
        $empty = fopen('php://memory', 'r+');
        if ($empty === false) {
            throw new \RuntimeException('cannot open memory stream');
        }
        Assert::null((new ConsoleSpeaker($empty, $out))->reply('q'));
    }
}
