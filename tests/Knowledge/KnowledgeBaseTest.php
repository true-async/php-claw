<?php

declare(strict_types=1);

namespace Tests\Knowledge;

use Claw\Knowledge\EmbedderInterface;
use Claw\Knowledge\Indexer;
use Claw\Knowledge\KnowledgeIndex;
use Claw\Knowledge\NoteParser;
use Claw\Tool\KnowledgeTool;
use Testo\Assert;
use Testo\Test;

final class KnowledgeBaseTest
{
    /**
     * A chunk carries its breadcrumb, and that is what makes retrieval work on technical notes.
     *
     * "Run it again with the flag" retrieves for nothing on its own. Under `Deployment / Rollback` it
     * retrieves correctly, and the same string is what a reader sees above the result — so the context
     * is paid for once and used twice.
     */
    #[Test]
    public function aNoteIsChunkedByHeadingAndEachChunkKnowsWhereItCameFrom(): void
    {
        $note = NoteParser::parse('ops/deploy.md', <<<'MD'
            ---
            tags: [ops]
            ---
            # Deploying

            The short version: push the tag.

            ## Rollback

            ### Manual steps

            Run it again with the flag. See [[incident-log]] and src/Deploy/Runner.php:88.
            MD);

        Assert::same($note['title'], 'Deploying');

        $headings = array_map(static fn (array $c): string => $c['heading'], $note['chunks']);
        Assert::same($headings, ['Deploying', 'Deploying / Rollback / Manual steps']);

        // Frontmatter is bookkeeping for Obsidian, not prose: embedding `tags: [ops]` would put a note's
        // metadata into competition with its content for every query.
        Assert::false(str_contains(implode(' ', array_column($note['chunks'], 'text')), 'tags:'));

        // The graph and the code references come out as edges, not as text to be searched fuzzily.
        Assert::same($note['links'], ['incident-log']);
        Assert::same($note['refs'], [['file' => 'src/Deploy/Runner.php', 'line' => 88]]);
    }

    /**
     * Indexing is incremental, and the two gates are there for different reasons.
     *
     * A stat is cheap and settles the common case — but stat lies, because a checkout, an rsync or a
     * `touch` move mtime without changing a byte. So a moved stat means "hash it", and only a changed
     * hash costs an embedding call, which is the one expensive step in the pass.
     */
    #[Test]
    public function onlyAChangedNoteCostsAnEmbeddingCall(): void
    {
        $dir = self::tempDir();

        try {
            mkdir($dir . '/kb');
            file_put_contents($dir . '/kb/one.md', "# One\n\nThe first note.\n");
            file_put_contents($dir . '/kb/two.md', "# Two\n\nThe second note.\n");

            $embedder = new CountingEmbedder();
            $index = new KnowledgeIndex(new \PDO('sqlite::memory:'));
            $indexer = new Indexer($index, $embedder);

            Assert::same($indexer->sync($dir . '/kb'), ['indexed' => 2, 'removed' => 0, 'unchanged' => 0]);
            Assert::same($embedder->calls, 2);

            // Nothing moved: no stat difference, so not even a read.
            Assert::same($indexer->sync($dir . '/kb'), ['indexed' => 0, 'removed' => 0, 'unchanged' => 2]);
            Assert::same($embedder->calls, 2);

            // The stat moves but the bytes do not — the case that would otherwise re-embed the world.
            touch($dir . '/kb/one.md', time() + 10);
            Assert::same($indexer->sync($dir . '/kb'), ['indexed' => 0, 'removed' => 0, 'unchanged' => 2]);
            Assert::same($embedder->calls, 2);

            // Real edit: one call, for one note.
            file_put_contents($dir . '/kb/one.md', "# One\n\nThe first note, revised.\n");
            Assert::same($indexer->sync($dir . '/kb'), ['indexed' => 1, 'removed' => 0, 'unchanged' => 1]);
            Assert::same($embedder->calls, 3);

            // A deleted note leaves the index, or the base would answer out of files nobody has.
            unlink($dir . '/kb/two.md');
            Assert::same($indexer->sync($dir . '/kb'), ['indexed' => 0, 'removed' => 1, 'unchanged' => 1]);
            Assert::same($index->paths(), ['one.md']);
        } finally {
            self::rmrf($dir);
        }
    }

    /** Search finds the passage that answers the question; `about` answers exactly, with no vectors. */
    #[Test]
    public function theToolSearchesSemanticallyAndLooksUpCodeExactly(): void
    {
        $dir = self::tempDir();

        try {
            mkdir($dir . '/kb');
            file_put_contents($dir . '/kb/deploy.md', "# Deploying\n\nPush the tag, then watch src/Deploy/Runner.php:88.\n");
            file_put_contents($dir . '/kb/style.md', "# Style\n\nBlank line after a closing brace.\n");

            $embedder = new KeywordEmbedder();
            $index = new KnowledgeIndex(new \PDO('sqlite::memory:'));
            $tool = new KnowledgeTool($index, $embedder, $dir . '/kb');

            $found = $tool->handle(['action' => 'search', 'query' => 'how do we deploy']);
            Assert::true(str_contains($found, 'Push the tag'));
            Assert::true(str_contains($found, 'deploy.md'));   // the result says where it came from

            // The exact half: a path in hand needs no embedding call and no approximation.
            $about = $tool->handle(['action' => 'about', 'file' => 'src/Deploy/Runner.php']);
            Assert::true(str_contains($about, 'deploy.md'));
            Assert::true(str_contains($about, 'line 88'));

            Assert::true(str_contains($tool->handle(['action' => 'about', 'file' => 'src/Nothing.php']), 'says nothing'));
            Assert::true(str_contains($tool->handle(['action' => 'read', 'path' => 'style.md']), 'closing brace'));
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * A note's path is the model's to choose, so it is confined like every other file path in the system.
     * The knowledge base is not a way around the workspace guard.
     */
    #[Test]
    public function readingANoteCannotEscapeTheNotesFolder(): void
    {
        $dir = self::tempDir();

        try {
            mkdir($dir . '/kb');
            file_put_contents($dir . '/secret.txt', 'not for the model');
            file_put_contents($dir . '/kb/ok.md', "# Ok\n\nfine\n");

            $tool = new KnowledgeTool(new KnowledgeIndex(new \PDO('sqlite::memory:')), new KeywordEmbedder(), $dir . '/kb');
            $threw = false;

            try {
                $tool->handle(['action' => 'read', 'path' => '../secret.txt']);
            } catch (\Claw\Exceptions\ToolException) {
                $threw = true;
            }

            Assert::true($threw);
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * Tags come from BOTH places Obsidian puts them, and they file a note rather than describe it.
     *
     * People use frontmatter and people use `#inline`, so a base that understood one would hold half a
     * classification and give no sign of it. And a tag is never embedded with the text: filing should
     * NARROW a search, not compete with the note's own content for relevance.
     */
    #[Test]
    public function tagsAreReadFromFrontmatterAndProseAndNarrowASearch(): void
    {
        $note = NoteParser::parse('decisions/deploy.md', <<<'MD'
            ---
            tags: [ops, Deployment]
            ---
            # Deploying

            Push the tag. #release and #ops again.

            ## Not a tag

            A heading is not a tag.
            MD);

        // Both sources, lower-cased, de-duplicated; the heading is markdown, not a tag.
        Assert::same($note['tags'], ['ops', 'deployment', 'release']);

        $dir = self::tempDir();

        try {
            mkdir($dir . '/kb');
            file_put_contents($dir . '/kb/deploy.md', "---\ntags: [ops]\n---\n# Deploying\n\nPush the tag.\n");
            file_put_contents($dir . '/kb/style.md', "---\ntags: [style]\n---\n# Style\n\nDeploy a blank line after a brace.\n");

            $index = new KnowledgeIndex(new \PDO('sqlite::memory:'));
            $tool = new KnowledgeTool($index, new KeywordEmbedder(), $dir . '/kb');

            // The base can say what it is filed under, so a model narrows with a real tag not a guess.
            $tags = $tool->handle(['action' => 'tags']);
            Assert::true(str_contains($tags, 'ops (1)'));
            Assert::true(str_contains($tags, 'style (1)'));

            // Both notes mention deploying; the tag decides which subject is being asked about.
            $onlyOps = $tool->handle(['action' => 'search', 'query' => 'deploy', 'tag' => 'ops']);
            Assert::true(str_contains($onlyOps, 'deploy.md'));
            Assert::false(str_contains($onlyOps, 'style.md'));

            // A hit says how it is filed, so the answer carries its own provenance.
            Assert::true(str_contains($onlyOps, '#ops'));

            // A tag nobody uses says so, and points at the action that lists the real ones.
            $none = $tool->handle(['action' => 'search', 'query' => 'deploy', 'tag' => 'nonexistent']);
            Assert::true(str_contains($none, "action='tags'"));
        } finally {
            self::rmrf($dir);
        }
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-kb-' . uniqid('', true);
        mkdir($dir, 0o775, true);

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? self::rmrf($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

/** Counts calls, so a test can assert how often the expensive step was reached. */
final class CountingEmbedder implements EmbedderInterface
{
    public int $calls = 0;

    public function embed(array $texts): array
    {
        $this->calls++;

        return array_map(static fn (): array => [1.0, 0.0, 0.0, 0.0], $texts);
    }

    public function dimensions(): int
    {
        return 4;
    }
}

/**
 * A deterministic stand-in for a real embedder: one dimension per keyword, set when the text contains
 * it. Crude, and enough to prove that a query about deploying finds the note about deploying rather
 * than the one about braces — which is the behaviour under test, not the quality of anyone's model.
 */
final class KeywordEmbedder implements EmbedderInterface
{
    private const array WORDS = ['deploy', 'tag', 'style', 'brace'];

    public function embed(array $texts): array
    {
        $vectors = [];

        foreach ($texts as $text) {
            $lower = strtolower($text);
            $vector = [];

            foreach (self::WORDS as $word) {
                $vector[] = str_contains($lower, $word) ? 1.0 : 0.0;
            }

            $vectors[] = $vector;
        }

        return $vectors;
    }

    public function dimensions(): int
    {
        return \count(self::WORDS);
    }
}
