<?php

declare(strict_types=1);

namespace Claw\Knowledge;

/**
 * The knowledge base's index: notes, their chunks and vectors, the links between them, and the code
 * they mention. SQLite, in a file OUTSIDE the repository.
 *
 * The notes are the truth; this is a cache of them. Deleting it loses nothing — the next indexing pass
 * rebuilds it from the markdown — and that asymmetry is what decides the rest of the design. In
 * particular it is why the freshness markers (`mtime`, `size`, `sha256`) live HERE rather than in each
 * note's frontmatter: a hash written into a note would make the note's content a function of the
 * indexer, so every reindex would rewrite files a person edits by hand, every rewrite would be a commit,
 * and two machines indexing one checkout would conflict over bytes neither author typed. In the index
 * there is one store and one truth, and removing the file is a complete reset.
 *
 * Search is a brute-force cosine scan over packed float32. Measured before it was chosen: at 256
 * dimensions, 10 000 chunks scan in about 218 ms — a fifth of a second inside a tool call that is
 * already waiting on a model, against `sqlite-vec` becoming a platform-specific build dependency for a
 * speed nobody's own notes need. `dev/design/knowledge-base.md` has the numbers.
 */
final class KnowledgeIndex
{
    public function __construct(private readonly \PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::ensureTables($pdo);
    }

    public static function ensureTables(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notes (
                path       TEXT PRIMARY KEY,
                title      TEXT NOT NULL,
                mtime      INTEGER NOT NULL,
                size       INTEGER NOT NULL,
                sha256     TEXT NOT NULL,
                indexed_at INTEGER NOT NULL
            )',
        );

        // A note is the unit of reindexing: its chunks, links and refs are deleted by path and written
        // again. Anything finer would leave orphans behind the first time a heading moved.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chunks (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                path      TEXT NOT NULL,
                heading   TEXT NOT NULL,
                ord       INTEGER NOT NULL,
                text      TEXT NOT NULL,
                embedding BLOB NOT NULL
            )',
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS chunks_path ON chunks (path)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS links (path TEXT NOT NULL, target TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS links_target ON links (target)');

        // What a note says about the code. Kept apart from the vectors because it answers a question
        // embeddings answer badly: "what do we know about THIS file" is an exact lookup, and paying for
        // an embedding call plus a fuzzy scan to answer it would be both slower and less correct.
        $pdo->exec('CREATE TABLE IF NOT EXISTS refs (path TEXT NOT NULL, file TEXT NOT NULL, line INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS refs_file ON refs (file)');

        // How a note is FILED, as opposed to what it says. A tag narrows a search rather than competing
        // with its content for relevance, which is why tags are stored here and never embedded.
        $pdo->exec('CREATE TABLE IF NOT EXISTS tags (path TEXT NOT NULL, tag TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS tags_tag ON tags (tag)');
    }

    /**
     * Has this note changed since it was indexed?
     *
     * mtime and size first because they are one stat call and settle the common case. They also lie — a
     * checkout, an rsync, a `touch` all move mtime without changing a byte — so a difference here means
     * "read it and hash it", never "reindex it". Only a changed hash costs an embedding call, which is
     * the one expensive step in the whole pass.
     */
    public function isStale(string $path, int $mtime, int $size): bool
    {
        $stmt = $this->pdo->prepare('SELECT mtime, size FROM notes WHERE path = :path');
        $stmt->execute(['path' => $path]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return !\is_array($row) || (int) $row['mtime'] !== $mtime || (int) $row['size'] !== $size;
    }

    /** The recorded hash of a note, or '' when it has never been indexed. */
    public function hashOf(string $path): string
    {
        $stmt = $this->pdo->prepare('SELECT sha256 FROM notes WHERE path = :path');
        $stmt->execute(['path' => $path]);
        $hash = $stmt->fetchColumn();

        return \is_scalar($hash) ? (string) $hash : '';
    }

    /**
     * Replace everything the index holds about one note, in a transaction.
     *
     * Atomic because a half-written note is worse than a stale one: a reader would see some chunks from
     * the old version and some from the new, with no way to tell. The note row is written LAST, so an
     * interrupted pass leaves the note looking stale and gets redone rather than looking fresh and being
     * wrong.
     *
     * @param list<array{heading: string, text: string, embedding: list<float>}> $chunks
     * @param list<string>                                                       $links
     * @param list<array{file: string, line: int}>                               $refs
     * @param list<string>                                                       $tags
     */
    public function replaceNote(
        string $path,
        string $title,
        int $mtime,
        int $size,
        string $sha256,
        array $chunks,
        array $links,
        array $refs,
        array $tags = [],
    ): void {
        $this->pdo->beginTransaction();

        try {
            $this->forgetContentOf($path);

            $insert = $this->pdo->prepare(
                'INSERT INTO chunks (path, heading, ord, text, embedding) VALUES (:p, :h, :o, :t, :e)',
            );

            foreach ($chunks as $ord => $chunk) {
                $insert->execute([
                    'p' => $path,
                    'h' => $chunk['heading'],
                    'o' => $ord,
                    't' => $chunk['text'],
                    'e' => self::pack($chunk['embedding']),
                ]);
            }

            $link = $this->pdo->prepare('INSERT INTO links (path, target) VALUES (:p, :t)');

            foreach ($links as $target) {
                $link->execute(['p' => $path, 't' => $target]);
            }

            $ref = $this->pdo->prepare('INSERT INTO refs (path, file, line) VALUES (:p, :f, :l)');

            foreach ($refs as $mention) {
                $ref->execute(['p' => $path, 'f' => $mention['file'], 'l' => $mention['line']]);
            }

            $tag = $this->pdo->prepare('INSERT INTO tags (path, tag) VALUES (:p, :t)');

            foreach ($tags as $name) {
                $tag->execute(['p' => $path, 't' => $name]);
            }

            $this->pdo->prepare(
                'INSERT OR REPLACE INTO notes (path, title, mtime, size, sha256, indexed_at)
                 VALUES (:p, :ti, :m, :s, :sha, :at)',
            )->execute(['p' => $path, 'ti' => $title, 'm' => $mtime, 's' => $size, 'sha' => $sha256, 'at' => time()]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /** Drop a note entirely — it is gone from the folder, so it must go from the index. */
    public function forget(string $path): void
    {
        $this->forgetContentOf($path);
        $this->pdo->prepare('DELETE FROM notes WHERE path = :p')->execute(['p' => $path]);
    }

    /**
     * Every note path the index knows — walked against the folder to find the ones that were deleted.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $stmt = $this->pdo->query('SELECT path FROM notes ORDER BY path');

        return $stmt === false ? [] : array_values(array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * The $limit closest chunks to $query, best first, with what each one links to.
     *
     * A full scan. See the class docblock for why that is the right shape here and where the seam is if
     * it ever stops being.
     *
     * $tag narrows the scan to notes filed under it — the cheap half of retrieval doing the work the
     * expensive half would do worse. "What did we decide about deployment" is a tag and a query, not a
     * query that has to out-rank every other note in the base.
     *
     * @param list<float> $query
     *
     * @return list<array{path: string, heading: string, text: string, score: float, links: list<string>, tags: list<string>}>
     */
    public function nearest(array $query, int $limit = 5, string $tag = ''): array
    {
        if ($tag === '') {
            $stmt = $this->pdo->query('SELECT path, heading, text, embedding FROM chunks');
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT c.path, c.heading, c.text, c.embedding FROM chunks c
                 JOIN tags t ON t.path = c.path WHERE t.tag = :tag',
            );
            $stmt->execute(['tag' => strtolower($tag)]);
        }

        if ($stmt === false) {
            return [];
        }

        $norm = self::norm($query);
        $scored = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $vector = self::unpack((string) $row['embedding']);

            if (\count($vector) !== \count($query)) {
                continue;   // a chunk embedded by a different model: not comparable, and not an error here
            }

            $scored[] = [
                'path' => (string) $row['path'],
                'heading' => (string) $row['heading'],
                'text' => (string) $row['text'],
                'score' => self::cosine($query, $vector, $norm),
                'links' => [],
                'tags' => [],
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = \array_slice($scored, 0, max(1, $limit));

        foreach ($top as $i => $hit) {
            $top[$i]['links'] = $this->linksOf($hit['path']);
            $top[$i]['tags'] = $this->tagsOf($hit['path']);
        }

        return $top;
    }

    /**
     * Which notes mention a source file, and where they say it.
     *
     * The exact half of the base. "What do we know about src/Foo.php" has a right answer that costs one
     * indexed lookup; asking it of a vector scan would be slower and only approximately right.
     *
     * @return list<array{path: string, line: int}>
     */
    public function about(string $file): array
    {
        $stmt = $this->pdo->prepare('SELECT path, line FROM refs WHERE file = :f ORDER BY path, line');
        $stmt->execute(['f' => $file]);

        $found = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $found[] = ['path' => (string) $row['path'], 'line' => (int) $row['line']];
        }

        return $found;
    }

    /**
     * Every tag in the base with how many notes carry it — the shape of the filing, so a model can pick
     * a real one rather than guess a plausible one.
     *
     * @return array<string, int>
     */
    public function tagCounts(): array
    {
        $stmt = $this->pdo->query('SELECT tag, COUNT(*) n FROM tags GROUP BY tag ORDER BY n DESC, tag');

        if ($stmt === false) {
            return [];
        }

        $counts = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['tag']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return list<string> */
    private function tagsOf(string $path): array
    {
        $stmt = $this->pdo->prepare('SELECT tag FROM tags WHERE path = :p ORDER BY tag');
        $stmt->execute(['p' => $path]);

        return array_values(array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function linksOf(string $path): array
    {
        $stmt = $this->pdo->prepare('SELECT target FROM links WHERE path = :p ORDER BY target');
        $stmt->execute(['p' => $path]);

        return array_values(array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function forgetContentOf(string $path): void
    {
        foreach (['chunks', 'links', 'refs', 'tags'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE path = :p")->execute(['p' => $path]);
        }
    }

    /**
     * float32, little-endian — a third the size of doubles, and past the noise floor of an embedding.
     *
     * @param list<float> $vector
     */
    private static function pack(array $vector): string
    {
        return pack('g*', ...array_map(floatval(...), $vector));
    }

    /** @return list<float> */
    private static function unpack(string $blob): array
    {
        $values = unpack('g*', $blob);

        return $values === false ? [] : array_values($values);
    }

    /** @param list<float> $vector */
    private static function norm(array $vector): float
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }

    /**
     * Cosine similarity. The query's norm is passed in because it is the same for every chunk in a
     * scan, and recomputing it per row is the kind of waste that turns 218 ms into 400.
     *
     * @param list<float> $query
     * @param list<float> $chunk
     */
    private static function cosine(array $query, array $chunk, float $queryNorm): float
    {
        $dot = 0.0;
        $chunkSum = 0.0;

        foreach ($query as $i => $value) {
            $other = $chunk[$i];
            $dot += $value * $other;
            $chunkSum += $other * $other;
        }

        $denominator = $queryNorm * sqrt($chunkSum);

        return $denominator > 0.0 ? $dot / $denominator : 0.0;
    }
}
