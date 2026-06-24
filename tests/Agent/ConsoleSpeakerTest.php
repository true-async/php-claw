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
}
