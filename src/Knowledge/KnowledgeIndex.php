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
 * Search has two halves over the same chunks — a brute-force cosine scan and a full-text index — fused
 * by rank in {@see search()}, which is where the reasoning for that lives.
 *
 * The dense half is a full scan over packed float32. Measured before it was chosen: at 256 dimensions,
 * 10 000 chunks scan in about 218 ms — a fifth of a second inside a tool call that is already waiting on
 * a model, against `sqlite-vec` becoming a platform-specific build dependency for a speed nobody's own
 * notes need. On a real 346-chunk base the scan measures 12 ms. `dev/design/knowledge-base.md` has the
 * numbers; the lexical half costs no new dependency, since this build has FTS5 with `bm25()` compiled in.
 */
final class KnowledgeIndex
{
    /**
     * Rank fusion constants, MEASURED on this project's own corpus (346 chunks) rather than imported.
     *
     * Cormack's k=60 comes from TREC-scale runs and is degenerate here: at depth 50 over 346 chunks the
     * weights of rank 1 and rank 50 differ by 1.8x, so fusion stops ordering and becomes a vote on which
     * documents appear in both lists. Measured on the eval set, k=60/depth=50 costs 6 points of semantic
     * recall and 13 points of exact-string recall against these. Revisit both past a few thousand chunks.
     */
    private const int RRF_K = 5;
    private const int RRF_DEPTH = 10;

    public function __construct(private readonly \PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::ensureTables($pdo);
    }

    /**
     * Open a project's knowledge database with the settings it needs to survive a second writer.
     *
     * The same reasoning as {@see \Claw\Project\ProjectStore::open()}, and for the same reason it lives
     * with the class that owns the file rather than at the call site: two runs of ONE project are started
     * concurrently by design, and both construct an index over this file. `ensureTables()` runs from the
     * constructor and takes a write lock for its DDL, so without a busy timeout the second run dies with
     * "database is locked" before it has read anything.
     */
    public static function openAt(string $path): self
    {
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 4,   // busy timeout (s) — wait out a concurrent writer rather than fail
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');

        return new self($pdo);
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

        self::ensureFullText($pdo);
    }

    /**
     * The lexical half: full text over the same chunks the vectors cover.
     *
     * External content, so the text is stored once — the FTS index reads it back through `chunks.id`.
     * The two triggers are the whole of the bookkeeping: a note is reindexed by deleting its chunks and
     * inserting them again, never by updating one in place, so insert and delete cover every write.
     *
     * ALL OF IT IN ONE TRANSACTION, which is not tidiness. Creating the table and populating it are
     * separate statements, and a process that died between them would leave an empty index that nothing
     * detects — `SELECT count(*)` reads the content table, not the index, so it answers correctly while
     * every search silently loses its lexical half. Rolled back together, the next open simply rebuilds.
     */
    private static function ensureFullText(\PDO $pdo): void
    {
        $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'chunks_fts'");

        if ($exists !== false && $exists->fetchColumn() !== false) {
            return;
        }

        $pdo->beginTransaction();

        try {
            $pdo->exec(
                "CREATE VIRTUAL TABLE chunks_fts USING fts5(
                    text, heading, content='chunks', content_rowid='id', tokenize='porter unicode61'
                )",
            );
            $pdo->exec(
                'CREATE TRIGGER chunks_fts_insert AFTER INSERT ON chunks BEGIN
                    INSERT INTO chunks_fts (rowid, text, heading) VALUES (new.id, new.text, new.heading);
                END',
            );
            $pdo->exec(
                "CREATE TRIGGER chunks_fts_delete AFTER DELETE ON chunks BEGIN
                    INSERT INTO chunks_fts (chunks_fts, rowid, text, heading)
                    VALUES ('delete', old.id, old.text, old.heading);
                END",
            );
            // An index built over chunks that are already there starts empty; 'rebuild' fills it. On a
            // fresh database this is a no-op.
            $pdo->exec("INSERT INTO chunks_fts (chunks_fts) VALUES ('rebuild')");
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();

            throw $e;
        }
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
     * The $limit best chunks for a query, best first, with what each one links to.
     *
     * TWO RANKERS OVER THE SAME CHUNKS, fused by rank. They fail on different questions, which is the
     * only reason to pay for both: measured on this project's corpus, the vectors and the full-text index
     * are within noise of each other on questions phrased in ordinary words, while an identifier quoted
     * inside a sentence — `ATTR_POOL_MAX`, `SQLSTATE[HY000]`, a file path — is found by the lexical half
     * and missed by the dense one often enough to matter. Fusing beats either alone; see
     * dev/design/knowledge-base-next.md for the numbers and for what was refuted on the way.
     *
     * Reciprocal Rank Fusion, so only positions are compared. No score normalisation, and the sign
     * convention of SQLite's `bm25()` (negative, best first) never enters the arithmetic.
     *
     * $tag narrows BOTH halves to notes filed under it — the cheap half of retrieval doing the work the
     * expensive half would do worse. "What did we decide about deployment" is a tag and a query, not a
     * query that has to out-rank every other note in the base.
     *
     * @param string      $text   the query as written, for the lexical half
     * @param list<float> $vector the same query embedded, for the dense half; [] searches lexically only
     *
     * @return list<array{path: string, heading: string, text: string, score: float, links: list<string>, tags: list<string>}>
     */
    public function search(string $text, array $vector, int $limit = 5, string $tag = ''): array
    {
        $tag = strtolower(trim($tag));   // ONCE, for both halves: tags are stored lowercased
        $fused = [];

        foreach ([$this->densely($vector, $tag), $this->lexically($text, $tag)] as $ranking) {
            foreach ($ranking as $position => $id) {
                $fused[$id] = ($fused[$id] ?? 0.0) + 1 / (self::RRF_K + $position + 1);
            }
        }

        if ($fused === []) {
            return [];
        }

        arsort($fused);
        $top = \array_slice(array_keys($fused), 0, max(1, $limit));

        return $this->rowsOf($top, $fused);
    }

    /**
     * The nearest chunk ids by cosine, best first. A full scan — see the class docblock for why that is
     * the right shape here, and where the seam is if it ever stops being.
     *
     * @param list<float> $query
     *
     * @return list<int>
     */
    private function densely(array $query, string $tag): array
    {
        if ($query === []) {
            return [];   // nothing to compare against: the lexical half answers alone
        }

        if ($tag === '') {
            $stmt = $this->pdo->query('SELECT id, embedding FROM chunks');
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT c.id, c.embedding FROM chunks c JOIN tags t ON t.path = c.path WHERE t.tag = :tag',
            );
            $stmt->execute(['tag' => $tag]);
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

            $scored[(int) $row['id']] = self::cosine($query, $vector, $norm);
        }

        arsort($scored);

        return \array_slice(array_keys($scored), 0, self::RRF_DEPTH);
    }

    /**
     * The best-matching chunk ids by BM25, best first.
     *
     * ONLY IDENTIFIER-SHAPED WORDS ARE ASKED FOR, and that restriction is what makes this half useful
     * rather than harmful. FTS5 has no stopword list, so putting a whole question to it ranks documents
     * on `what`, `the` and `about`; measured, that drowns the one rare token the caller actually cares
     * about and leaves this half ranking worse than the vectors it is meant to complement. A word
     * carrying a digit, an underscore, a slash, a dot, a colon or an internal capital is the shape of
     * something spelled exactly — and spelled-exactly is the only thing the vectors reliably lose.
     *
     * @return list<int>
     */
    private function lexically(string $text, string $tag): array
    {
        $match = self::matchExpression($text);

        if ($match === '') {
            return [];   // an ordinary sentence with nothing exact in it: the dense half answers alone
        }

        $sql = 'SELECT chunks_fts.rowid AS id FROM chunks_fts';

        if ($tag !== '') {
            // The FTS table cannot be aliased — `f MATCH ...` is "no such column: f" — so it stays
            // unaliased here and the joined tables take the aliases.
            $sql .= ' JOIN chunks c ON c.id = chunks_fts.rowid JOIN tags t ON t.path = c.path';
        }

        $sql .= ' WHERE chunks_fts MATCH :q' . ($tag === '' ? '' : ' AND t.tag = :tag')
            . ' ORDER BY bm25(chunks_fts) LIMIT ' . self::RRF_DEPTH;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($tag === '' ? ['q' => $match] : ['q' => $match, 'tag' => $tag]);

        return array_values(array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * A caller's words as an FTS5 MATCH expression, or '' when there is nothing exact to look for.
     *
     * The query is written by a model, so it is arbitrary text, and arbitrary text is not FTS5 syntax:
     * `SQLSTATE[HY000] [2002]` passed through raw is a syntax error near `[`, not a search. Every term is
     * therefore quoted as a phrase, which both neutralises the operators (`AND`, `NOT`, `*`) and keeps
     * stemming.
     *
     * SPLIT ON WHITESPACE ONLY. The tokenizer already breaks `ATTR_POOL_MAX` into `attr`, `pool`, `max`,
     * so splitting the query the same way would ask for three common words; keeping the term whole asks
     * for those three ADJACENT, which is the exactness this half exists for.
     */
    private static function matchExpression(string $text): string
    {
        $terms = [];

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            // `\p{L}\p{N}` and not `A-Za-z0-9`: the notes are not necessarily written in English, and an
            // ASCII test would drop every term of a Russian question and search for nothing.
            if (preg_match('/[\p{L}\p{N}]/u', $word) !== 1 || !self::spelledExactly($word)) {
                continue;
            }

            $terms['"' . str_replace('"', '""', $word) . '"'] = true;
        }

        return implode(' OR ', \array_slice(array_keys($terms), 0, 32));
    }

    /** Is this the shape of something a person spells out rather than a word they merely say? */
    private static function spelledExactly(string $word): bool
    {
        return preg_match('/[\d_\/:\\\\]|\p{Ll}\p{Lu}|\w\.\w/u', $word) === 1;
    }

    /**
     * Load the chunks behind a fused ranking, in the ranking's order.
     *
     * @param list<int>          $ids
     * @param array<int, float>  $scores
     *
     * @return list<array{path: string, heading: string, text: string, score: float, links: list<string>, tags: list<string>}>
     */
    private function rowsOf(array $ids, array $scores): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, path, heading, text FROM chunks WHERE id IN ('
            . implode(', ', array_fill(0, \count($ids), '?')) . ')',
        );
        $stmt->execute($ids);

        $byId = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $hits = [];

        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;

            if ($row === null) {
                continue;   // deleted between the ranking and the read
            }

            $path = (string) $row['path'];
            $hits[] = [
                'path' => $path,
                'heading' => (string) $row['heading'],
                'text' => (string) $row['text'],
                'score' => $scores[$id] ?? 0.0,
                'links' => $this->linksOf($path),
                'tags' => $this->tagsOf($path),
            ];
        }

        return $hits;
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
