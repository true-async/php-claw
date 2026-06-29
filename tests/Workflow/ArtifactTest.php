<?php

declare(strict_types=1);

namespace Tests\Workflow;

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
}
