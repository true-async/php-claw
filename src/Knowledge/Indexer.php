<?php

declare(strict_types=1);

namespace Claw\Knowledge;

use Claw\Exceptions\ClawException;

/**
 * Brings the index up to date with the notes folder.
 *
 * Incremental: it walks `kb/`, stats each file, hashes only the ones whose stat moved, and embeds only
 * the ones whose hash changed. Embedding is the sole expensive step — an HTTP round trip per batch — so
 * everything above it exists to call it as rarely as possible.
 *
 * Interruptible on purpose. A note is committed to the index only after its embeddings come back, and
 * its `notes` row is written last, so a pass killed halfway leaves that note looking STALE rather than
 * looking fresh and being wrong. Run again and it picks up where it stopped. That property is what makes
 * it safe to spawn this on a coroutine and let a run get on with its work.
 */
final readonly class Indexer
{
    /** Notes per embedding request. The round trip dominates; a note of twenty chunks is one call. */
    private const int BATCH = 64;

    public function __construct(
        private KnowledgeIndex $index,
        private EmbedderInterface $embedder,
    ) {
    }

    /**
     * Reindex $folder. Returns what changed, so a caller can say something useful rather than "done".
     *
     * @return array{indexed: int, removed: int, unchanged: int}
     */
    public function sync(string $folder): array
    {
        $root = realpath($folder);

        if ($root === false || !is_dir($root)) {
            return ['indexed' => 0, 'removed' => 0, 'unchanged' => 0];   // no kb/ folder is not an error
        }

        $seen = [];
        $indexed = 0;
        $unchanged = 0;

        foreach (self::notesIn($root) as $file) {
            $path = self::relative($root, $file);
            $seen[$path] = true;

            $mtime = (int) @filemtime($file);
            $size = (int) @filesize($file);

            if (!$this->index->isStale($path, $mtime, $size)) {
                $unchanged++;

                continue;
            }

            $markdown = @file_get_contents($file);

            if ($markdown === false) {
                continue;   // vanished between the walk and the read; the next pass will settle it
            }

            // The stat moved but the bytes may not have — a checkout, an rsync, a touch. Hashing here is
            // what stops those costing an embedding call each.
            $sha = hash('sha256', $markdown);

            if ($sha === $this->index->hashOf($path)) {
                $unchanged++;

                continue;
            }

            $this->reindex($path, $markdown, $mtime, $size, $sha);
            $indexed++;
        }

        $removed = 0;

        foreach ($this->index->paths() as $known) {
            if (!isset($seen[$known])) {
                $this->index->forget($known);
                $removed++;
            }
        }

        return ['indexed' => $indexed, 'removed' => $removed, 'unchanged' => $unchanged];
    }

    /** @throws ClawException when the embedder fails; the note keeps its previous version in the index */
    private function reindex(string $path, string $markdown, int $mtime, int $size, string $sha): void
    {
        $note = NoteParser::parse($path, $markdown);

        if ($note['chunks'] === []) {
            $this->index->replaceNote($path, $note['title'], $mtime, $size, $sha, [], $note['links'], $note['refs'], $note['tags']);

            return;
        }

        // What gets embedded is the breadcrumb AND the text. A paragraph reading "run it again with the
        // flag" retrieves for nothing on its own; under `Deployment / Rollback` it retrieves correctly.
        $texts = array_map(
            static fn (array $chunk): string => trim($note['title'] . ' — ' . $chunk['heading'] . "\n\n" . $chunk['text']),
            $note['chunks'],
        );

        $vectors = [];

        foreach (array_chunk($texts, self::BATCH) as $batch) {
            foreach ($this->embedder->embed($batch) as $vector) {
                $vectors[] = $vector;
            }
        }

        if (\count($vectors) !== \count($note['chunks'])) {
            throw new ClawException(
                'knowledge: the embedder returned ' . \count($vectors) . ' vectors for ' . \count($note['chunks'])
                . " chunks of {$path} — refusing to index a note whose vectors do not line up with it",
            );
        }

        $chunks = [];

        foreach ($note['chunks'] as $i => $chunk) {
            $chunks[] = ['heading' => $chunk['heading'], 'text' => $chunk['text'], 'embedding' => $vectors[$i]];
        }

        $this->index->replaceNote($path, $note['title'], $mtime, $size, $sha, $chunks, $note['links'], $note['refs'], $note['tags']);
    }

    /**
     * Every `.md` under the folder, depth-first. Hidden directories are skipped — `.git`, `.obsidian`
     * and the like hold a great deal of markdown that nobody wrote as knowledge.
     *
     * @return list<string>
     */
    private static function notesIn(string $root): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !str_starts_with($file->getFilename(), '.'),
            ),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && strtolower($file->getExtension()) === 'md') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private static function relative(string $root, string $file): string
    {
        return ltrim(str_replace($root, '', $file), \DIRECTORY_SEPARATOR);
    }
}
