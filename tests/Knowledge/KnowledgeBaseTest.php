<?php

declare(strict_types=1);

namespace Tests\Knowledge;

use Claw\Http\HttpResponse;
use Claw\Knowledge\EmbedderInterface;
use Claw\Knowledge\Indexer;
use Claw\Knowledge\KnowledgeBase;
use Claw\Knowledge\KnowledgeIndex;
use Claw\Knowledge\NoteParser;
use Claw\Knowledge\OpenAiEmbedder;
use Claw\Tool\KnowledgeTool;
use Testo\Assert;
use Testo\Test;
use Tests\Support\ScriptedHttpClient;

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
     * A note that is not written in English survives chunking intact.
     *
     * This is a regression test with a specific corpse behind it. The chunker split on `/\R/` with no
     * `/u`, so PCRE read the note as bytes — and in byte mode `\R` also matches 0x85, the Unicode NEL.
     * 0x85 is the second byte of `х` (U+0445), so every Russian note was cut through the middle of a
     * letter. The malformed chunk then reached `json_encode(..., JSON_THROW_ON_ERROR)` in the embedder
     * and the `JsonException` — which is not a `ClawException`, so nothing caught it — ended the whole
     * indexing pass. One common letter made the knowledge base unusable.
     */
    #[Test]
    public function aNoteInAnotherLanguageIsNotCutThroughTheMiddleOfALetter(): void
    {
        $note = NoteParser::parse('design/pool.md', <<<'MD'
            ---
            tags: [pool]
            ---
            # Пул соединений

            Техника простая: соединение закрепляется за корутиной.

            ## Хранение

            Хранилище держит одно соединение на проект.
            MD);

        Assert::same($note['title'], 'Пул соединений');

        foreach ($note['chunks'] as $chunk) {
            Assert::true(mb_check_encoding($chunk['text'], 'UTF-8'), 'a chunk was cut mid-character');
            Assert::true(mb_check_encoding($chunk['heading'], 'UTF-8'), 'a heading was cut mid-character');
        }

        // The paragraph must arrive whole, not as two halves either side of a letter.
        $texts = array_column($note['chunks'], 'text');
        Assert::true(str_contains(implode("\n", $texts), 'Техника простая'));
        Assert::same(array_column($note['chunks'], 'heading'), ['Пул соединений', 'Пул соединений / Хранение']);
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

            $tool = new KnowledgeTool(self::baseAt($dir));

            $found = $tool->handle(['action' => 'search', 'query' => 'how do we deploy']);
            Assert::true(str_contains($found, 'Push the tag'));
            Assert::true(str_contains($found, 'deploy.md'));   // the result says where it came from

            // The exact half: a path in hand needs no embedding call and no approximation.
            $about = $tool->handle(['action' => 'about', 'file' => 'src/Deploy/Runner.php']);
            Assert::true(str_contains($about, 'deploy.md'));
            Assert::true(str_contains($about, 'line 88'));

            Assert::true(str_contains($tool->handle(['action' => 'about', 'file' => 'src/Nothing.php']), 'says nothing'));
            Assert::true(str_contains($tool->handle(['action' => 'read', 'note' => 'style.md']), 'closing brace'));

            // The sections of a note, so a caller can ask about one part instead of pulling it all in.
            Assert::true(str_contains($tool->handle(['action' => 'outline', 'note' => 'deploy.md']), 'Deploying'));

            // And the project's own rules, which is how a model learns them without a copy in its prompt.
            Assert::true(str_contains($tool->handle(['action' => 'conventions']), 'Knowledge base'));
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

            $tool = new KnowledgeTool(self::baseAt($dir));
            $threw = false;

            try {
                $tool->handle(['action' => 'read', 'note' => '../secret.txt']);
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

            $tool = new KnowledgeTool(self::baseAt($dir));

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

    /**
     * The lexical half finds what the vectors lose: an identifier quoted inside an ordinary sentence.
     *
     * This is the whole case for a second ranker. Measured on a real 346-chunk base built from this
     * repository's own documents, the vectors and the full-text index are within noise of each other on
     * questions phrased in ordinary words — but a spelled-out token has to come back exactly, and 256
     * dimensions smear it. The embedder here is deliberately blind to the identifier, which is what a
     * narrow real embedding does to a token it has never seen.
     */
    #[Test]
    public function anIdentifierInsideASentenceIsFoundByTheLexicalHalf(): void
    {
        $index = new KnowledgeIndex(new \PDO('sqlite::memory:'));
        $embedder = new KeywordEmbedder();

        $index->replaceNote('design/pool.md', 'Pool', 1, 1, 'a', [
            ['heading' => 'Sizing', 'text' => 'The pool is capped by ATTR_POOL_MAX, raised to eight.', 'embedding' => $embedder->embed(['deploy'])[0]],
        ], [], [], []);
        $index->replaceNote('design/other.md', 'Other', 1, 1, 'b', [
            ['heading' => 'Prose', 'text' => 'Deploying is done by pushing a tag, and the release notes follow.', 'embedding' => $embedder->embed(['deploy deploy'])[0]],
        ], [], [], []);

        // The query's vector points at the OTHER note — the dense half is actively wrong here, exactly
        // as it is when a 256-dim vector meets a token it has no room for.
        $vector = $embedder->embed(['deploy deploy'])[0];
        $hits = $index->search('what does ATTR_POOL_MAX do and why was it raised', $vector, 5);

        Assert::same($hits[0]['path'], 'design/pool.md');

        // With no vector at all the base is still searchable — the lexical half answers alone.
        $lexicalOnly = $index->search('ATTR_POOL_MAX', [], 5);
        Assert::same($lexicalOnly[0]['path'], 'design/pool.md');
    }

    /**
     * A model writes the query, so the query is arbitrary text — and arbitrary text is not FTS5 syntax.
     *
     * `SQLSTATE[HY000] [2002]` handed to MATCH unquoted is a syntax error near `[`, not a search, and
     * every one of these shapes reaches the tool in normal use. None of them may throw, and a query with
     * nothing exact in it must fall back to the vectors rather than failing.
     */
    #[Test]
    public function anArbitraryQueryNeverBreaksTheFullTextHalf(): void
    {
        $index = new KnowledgeIndex(new \PDO('sqlite::memory:'));
        $embedder = new KeywordEmbedder();

        $index->replaceNote('ops/errors.md', 'Errors', 1, 1, 'a', [
            ['heading' => 'Sockets', 'text' => 'The driver reports SQLSTATE[HY000] [2002] Connection refused.', 'embedding' => $embedder->embed(['deploy'])[0]],
        ], [], [], []);

        $vector = $embedder->embed(['deploy'])[0];

        foreach (['SQLSTATE[HY000] [2002] Connection refused', '--- [] ( ) ***', 'AND OR NOT NEAR', '"unbalanced', 'что решили про пул', ''] as $query) {
            $index->search($query, $vector, 5);   // must not throw
        }

        // The one that carries something spelled exactly still finds it.
        $hits = $index->search('why do we see SQLSTATE[HY000] [2002] in the log', [], 5);
        Assert::same($hits[0]['path'], 'ops/errors.md');
    }

    /**
     * Reindexing and deleting a note leave nothing of it behind in the full-text index.
     *
     * External-content FTS5 is kept in step by triggers alone, and an index that drifts from its content
     * table does not fail — it answers, with rows that are no longer there. That is the failure mode
     * worth a test.
     */
    #[Test]
    public function theFullTextIndexFollowsTheChunksItMirrors(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $index = new KnowledgeIndex($pdo);
        $embedder = new KeywordEmbedder();
        $vector = $embedder->embed(['deploy'])[0];

        $index->replaceNote('a.md', 'A', 1, 1, 'v1', [
            ['heading' => 'H', 'text' => 'The flag is CLAW_FIRST_VALUE here.', 'embedding' => $vector],
        ], [], [], []);
        Assert::same(\count($index->search('CLAW_FIRST_VALUE', [], 5)), 1);

        // Reindexed with different content: the old text must not still be findable.
        $index->replaceNote('a.md', 'A', 2, 2, 'v2', [
            ['heading' => 'H', 'text' => 'The flag is CLAW_SECOND_VALUE now.', 'embedding' => $vector],
        ], [], [], []);
        Assert::same($index->search('CLAW_FIRST_VALUE', [], 5), []);
        Assert::same(\count($index->search('CLAW_SECOND_VALUE', [], 5)), 1);

        // And a forgotten note leaves nothing at all.
        $index->forget('a.md');
        Assert::same($index->search('CLAW_SECOND_VALUE', [], 5), []);
    }

    /**
     * A full-text index built over a base that already has chunks is backfilled, not left empty.
     *
     * The silent version of this bug is the dangerous one: an empty external-content index answers
     * `SELECT count(*)` from the CONTENT table, so it reports the right number of rows while matching
     * none of them, and `integrity-check` passes. Every search would quietly lose its lexical half.
     */
    #[Test]
    public function anIndexAddedToAPopulatedBaseIsBackfilled(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $embedder = new KeywordEmbedder();

        new KnowledgeIndex($pdo)->replaceNote('a.md', 'A', 1, 1, 'v1', [
            ['heading' => 'H', 'text' => 'The constant is CLAW_BACKFILL_ME today.', 'embedding' => $embedder->embed(['deploy'])[0]],
        ], [], [], []);

        // Drop the lexical half, as an older base that predates it would be.
        $pdo->exec('DROP TRIGGER chunks_fts_insert');
        $pdo->exec('DROP TRIGGER chunks_fts_delete');
        $pdo->exec('DROP TABLE chunks_fts');

        $reopened = new KnowledgeIndex($pdo);
        Assert::same(\count($reopened->search('CLAW_BACKFILL_ME', [], 5)), 1);
    }

    /**
     * The embedder attaches each vector to the chunk it was asked for, BY INDEX.
     *
     * The API documents an `index` field precisely because the order of the response array is not
     * promised. A batch that came back reordered and was read positionally would give every chunk
     * somebody else's vector — a base that retrieves confidently and wrongly, with nothing anywhere to
     * see. Narrow vectors are asked for on purpose: the scan's cost is the vector's width.
     */
    #[Test]
    public function theEmbedderOrdersVectorsByIndexAndNotByArrival(): void
    {
        // The provider answers out of order deliberately: index 1 first, then index 0.
        $body = json_encode(['data' => [
            ['index' => 1, 'embedding' => [0.0, 1.0]],
            ['index' => 0, 'embedding' => [1.0, 0.0]],
        ]]);

        $http = new ScriptedHttpClient(new HttpResponse(200, (string) $body));
        $embedder = new OpenAiEmbedder($http, 'https://api.example/v1', 'k', dimensions: 2);

        Assert::same($embedder->embed(['first', 'second']), [[1.0, 0.0], [0.0, 1.0]]);
        Assert::same($embedder->dimensions(), 2);

        // A batch that comes back short is refused rather than quietly indexing half a note.
        $short = new ScriptedHttpClient(new HttpResponse(200, (string) json_encode(['data' => [
            ['index' => 0, 'embedding' => [1.0, 0.0]],
        ]])));
        $threw = false;

        try {
            new OpenAiEmbedder($short, 'https://api.example/v1', 'k', dimensions: 2)->embed(['a', 'b']);
        } catch (\Claw\Exceptions\ClawException $e) {
            $threw = str_contains($e->getMessage(), 'asked for 2');
        }

        Assert::true($threw);
    }

    /**
     * The tool is offered to a run only when it can actually work.
     *
     * It was built and wired to nothing at all — a tested library with no callers, which is the way a
     * feature ships looking finished and being unreachable. Now it reaches a run, and only under the two
     * conditions that make it useful: the project has notes, and there is something to embed with.
     * Offering it otherwise gives a model a tool whose every answer is "nothing is written down", which
     * it will try several times before believing.
     */
    #[Test]
    public function theToolReachesARunOnlyWhenTheProjectHasNotesAndAnEmbedder(): void
    {
        $dir = self::tempDir();

        try {
            $project = new \Claw\Project\Project('p1', 'Demo', $dir);
            $workspace = new \Claw\Tool\Workspace($dir);
            $store = self::tempDir();

            // No embedder: nothing could turn a question into a search, so there is NO BASE — the
            // decision is the module's, and the factory only asks whether it got one.
            Assert::same(KnowledgeBase::forProject($project, $store, null), null);
            Assert::false(\Claw\Tool\ToolFactory::forRun($project, $workspace, null, null)->has('knowledge'));
            Assert::false(is_dir($dir . '/kb'));   // and nothing was created on the way

            // A MISSING FOLDER IS NOT A REASON TO WITHHOLD IT — it is made, seeded, and offered.
            // This assertion used to be the opposite, and that inverted contract is why the knowledge
            // base was never once used: the tool appeared only for a project that already had kb/,
            // and nothing anywhere created kb/.
            $base = KnowledgeBase::forProject($project, $store, new KeywordEmbedder());

            if ($base === null) {
                throw new \RuntimeException('a writable project with an embedder must have a base');
            }

            Assert::true(\Claw\Tool\ToolFactory::forRun($project, $workspace, null, $base)->has('knowledge'));

            // The base reports where notes go; the caller never assembles that path.
            Assert::same($base->location(), $dir . \DIRECTORY_SEPARATOR . 'kb');
            Assert::true(str_contains($base->conventions(), 'decisions/'));

            // A project that has rewritten its own rules keeps them: creating never overwrites.
            file_put_contents($dir . '/kb/README.md', '# Ours');
            KnowledgeBase::create($dir);
            Assert::same(file_get_contents($dir . '/kb/README.md'), '# Ours');
            self::rmrf($store);
        } finally {
            self::rmrf($dir);
        }
    }

    /** A real base over a temp project folder — the tool's only dependency. */
    private static function baseAt(string $projectDir): \Claw\Knowledge\KnowledgeBaseInterface
    {
        $store = $projectDir . '/state';

        if (!is_dir($store)) {
            mkdir($store, 0o775, true);
        }

        $base = KnowledgeBase::forProject(
            new \Claw\Project\Project('p', 'P', $projectDir),
            $store,
            new KeywordEmbedder(),
        );

        if ($base === null) {
            throw new \RuntimeException('the test could not build a knowledge base');
        }

        return $base;
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
