<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Tool\ToolResultMeta;
use Claw\Workflow\Artifact;
use Testo\Assert;
use Testo\Test;

final class ArtifactTest
{
    #[Test]
    public function fileDerivesExtAndMimeFromThePath(): void
    {
        $a = Artifact::file('the patch', 'src/Project/ProjectStore.php');

        Assert::same($a->kind, 'file');
        Assert::same($a->ext, 'php');
        Assert::same($a->mime, 'text/x-php');
    }

    #[Test]
    public function textSniffsItsContentTypeWhenNoneIsDeclared(): void
    {
        Assert::same(Artifact::text('p', '<?php echo 1;')->ext, 'php');
        Assert::same(Artifact::text('d', "diff --git a/x b/x\n@@ -1 +1 @@")->ext, 'diff');
        Assert::same(Artifact::text('j', '{"a":1}')->ext, 'json');
        Assert::same(Artifact::text('t', 'just some prose')->ext, 'txt');
    }

    #[Test]
    public function textHonoursAnExplicitExtOverTheSniff(): void
    {
        $a = Artifact::text('x', 'just some prose', 'md');   // would sniff as txt

        Assert::same($a->ext, 'md');
        Assert::same($a->mime, 'text/markdown');
    }

    #[Test]
    public function anUnknownExtKeepsTheExtButFallsBackToPlainTextMime(): void
    {
        $a = Artifact::text('x', 'data', '.XYZ');   // also normalizes the leading dot + case

        Assert::same($a->ext, 'xyz');
        Assert::same($a->mime, 'text/plain');
    }

    #[Test]
    public function evidenceKeepsTheOutputVerbatimAndNamesWhatProducedIt(): void
    {
        $output = "PHPUnit 9.6.34\n\nERRORS!\nTests: 10, Assertions: 0, Errors: 10.";
        $a = Artifact::evidence('tests', $output, 'bash');

        Assert::same($a->kind, 'evidence');
        Assert::same($a->value, $output);   // untouched — the point is that it was not composed
        Assert::same($a->source, 'bash');
        Assert::same($a->note, '');
    }

    #[Test]
    public function evidenceIsPresentedAsCapturedOutputRatherThanAsTheStepsWords(): void
    {
        // The critic has to be able to tell these apart at a glance: a text artifact is a claim, an
        // evidence artifact is what a command actually printed. If they render alike, a step can go
        // on asserting success it never had and the reviewer has no way to notice.
        $rendered = Artifact::evidence('tests', 'ERRORS! Tests: 10, Errors: 10.', 'bash')->render();

        Assert::true(str_contains($rendered, 'CAPTURED OUTPUT'));
        Assert::true(str_contains($rendered, 'not written by the step'));
        Assert::true(str_contains($rendered, '`bash`'));
        Assert::true(str_contains($rendered, 'ERRORS! Tests: 10, Errors: 10.'));
    }

    #[Test]
    public function aStepsNoteRidesAlongsideEvidenceAndIsMarkedAsItsClaim(): void
    {
        $a = Artifact::evidence('tests', 'ERRORS! Tests: 10, Errors: 10.', 'bash', 'the suite is green');
        $rendered = $a->render();

        Assert::same($a->note, 'the suite is green');
        // Stored apart, so a false summary cannot be mistaken for part of the output it contradicts.
        Assert::false(str_contains($a->value, 'the suite is green'));
        Assert::true(str_contains($rendered, "the step's own summary of it, which is a claim: the suite is green"));
    }

    #[Test]
    public function evidenceKeepsTheToolsOwnReportAndDerivesNothing(): void
    {
        $graded = Artifact::evidence(
            'tests',
            "PHPUnit 9.6.35 by Sebastian Bergmann and contributors.\n\nOK (3 tests, 5 assertions)",
            'php vendor/bin/phpunit tests/SorterTest.php',
            '',
            new ToolResultMeta('ok', 'phpunit', 'OK (3 tests, 5 assertions)'),
        );

        Assert::same($graded->status, 'ok');
        Assert::same($graded->tool, 'phpunit');
        Assert::same($graded->summary, 'OK (3 tests, 5 assertions)');

        // no report — no grading; the record never invents one from the text
        $bare = Artifact::evidence('run', "[exit 2]\nboom", 'my-script.sh');

        Assert::same($bare->status, '');
        Assert::same($bare->tool, '');
        Assert::same($bare->summary, '');
    }

    #[Test]
    public function pastedToolOutputIsRecognizable(): void
    {
        Assert::true(Artifact::looksLikeToolOutput("PHPUnit 9.6.35 by Sebastian Bergmann and contributors.\nOK (3 tests)"));
        Assert::true(Artifact::looksLikeToolOutput('No syntax errors detected in src/Sorter.php'));
        Assert::false(Artifact::looksLikeToolOutput('I decided to use a bubble sort because...'));
        Assert::false(Artifact::looksLikeToolOutput('The tests are described in docs/testing.md'));
    }
}
