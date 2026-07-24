<?php

declare(strict_types=1);

namespace Claw\Knowledge;

/**
 * Reads one Obsidian markdown note into the three things the index stores: chunks to embed, the notes
 * it links to, and the source files it mentions.
 *
 * Chunked BY HEADING, and each chunk carries its breadcrumb — `Deployment / Rollback / Manual steps` —
 * prepended to the text before embedding. That is not decoration: on technical notes the heading path
 * is most of what says which subject a paragraph belongs to, and a paragraph reading "run it again with
 * the flag" is meaningless without it. The same string is what a reader sees above the result, so it
 * costs nothing twice.
 *
 * @internal to {@see KnowledgeBase}.
 */
final class NoteParser
{
    /**
     * A section longer than this is split on paragraph boundaries, keeping its breadcrumb.
     *
     * Long enough to hold a whole thought — a procedure, an argument — because splitting one in half
     * gives two chunks that each retrieve badly. Short enough that a hit points somewhere specific
     * rather than at a page.
     */
    private const int MAX_CHUNK = 1500;

    /** @return array{title: string, chunks: list<array{heading: string, text: string}>, links: list<string>, refs: list<array{file: string, line: int}>, tags: list<string>} */
    public static function parse(string $path, string $markdown): array
    {
        $body = self::withoutFrontmatter($markdown);

        return [
            'title' => self::titleOf($path, $body),
            'chunks' => self::chunks($body),
            'links' => self::links($body),
            'refs' => self::refs($body),
            'tags' => self::tags($markdown, $body),
        ];
    }

    /**
     * A note's tags, from BOTH places Obsidian puts them: the `tags:` key in frontmatter, and `#inline`
     * tags in the prose.
     *
     * Both, because people use both and a base that only understood one would quietly hold half a
     * classification. Frontmatter accepts either spelling — `tags: [a, b]` and a dashed list — because
     * an editor writes one and a person writes the other.
     *
     * Tags are read from the frontmatter but never EMBEDDED with the text (see withoutFrontmatter): they
     * are how a note is filed, and filing should narrow a search rather than compete with its content.
     *
     * @return list<string>
     */
    private static function tags(string $markdown, string $body): array
    {
        $tags = [];

        if (preg_match('/^---\R(.*?)\R---/su', $markdown, $front) === 1) {
            // `tags: [a, b]` or `tags: a, b`
            if (preg_match('/^tags:[ \t]*\[?([^\]\r\n]*)\]?[ \t]*$/mi', $front[1], $inline) === 1) {
                foreach (explode(',', $inline[1]) as $tag) {
                    $tags[] = $tag;
                }
            }

            // a dashed list under `tags:`
            if (preg_match('/^tags:\s*\R((?:\s*-\s*\S+\R?)+)/miu', $front[1], $listed) === 1) {
                preg_match_all('/-\s*(\S+)/', $listed[1], $items);

                foreach ($items[1] as $tag) {
                    $tags[] = $tag;
                }
            }
        }

        // Inline `#tags` in the prose. Read from the BODY so a heading (`# Deploying`) is not mistaken
        // for one — a hash followed by a space is markdown, a hash followed by a word is a tag.
        preg_match_all('/(?:^|\s)#([A-Za-z][\w\/-]*)/m', $body, $inlineTags);

        foreach ($inlineTags[1] as $tag) {
            $tags[] = $tag;
        }

        $clean = [];

        foreach ($tags as $tag) {
            $tag = strtolower(trim($tag, " \t\"'"));

            if ($tag !== '' && !\in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }

        return $clean;
    }

    /**
     * Frontmatter is metadata for Obsidian, not prose. Embedding `tags: [x, y]` would put a note's
     * bookkeeping into competition with its content for every query.
     */
    private static function withoutFrontmatter(string $markdown): string
    {
        if (!str_starts_with($markdown, "---\n")) {
            return $markdown;
        }

        $end = strpos($markdown, "\n---", 4);

        return $end === false ? $markdown : ltrim(substr($markdown, $end + 4));
    }

    /** The first H1 if there is one, else the filename — a note always has a name to be found under. */
    private static function titleOf(string $path, string $body): string
    {
        if (preg_match('/^#\s+(.+)$/m', $body, $m) === 1) {
            return trim($m[1]);
        }

        return basename($path, '.md');
    }

    /**
     * Split into (breadcrumb, text) pairs.
     *
     * @return list<array{heading: string, text: string}>
     */
    private static function chunks(string $body): array
    {
        // `/u` is load-bearing, not decoration. Without it PCRE reads the subject as bytes, and in byte
        // mode `\R` also matches 0x85 (NEL) — which is the second byte of `х` (U+0445), `ą`, `Ѕ` and
        // every other character whose continuation byte lands there. A Russian note was therefore split
        // through the middle of a letter, and the malformed chunk reached json_encode() in the embedder,
        // whose JSON_THROW_ON_ERROR killed the whole indexing pass. See dev/POSTMORTEM.md.
        $lines = preg_split('/\R/u', $body) ?: [];
        $trail = [];        // heading stack, index 0 = h1
        $heading = '';
        $buffer = [];
        $chunks = [];

        $flush = static function () use (&$buffer, &$heading, &$chunks): void {
            $text = trim(implode("\n", $buffer));
            $buffer = [];

            if ($text === '') {
                return;
            }

            foreach (self::split($text) as $piece) {
                $chunks[] = ['heading' => $heading, 'text' => $piece];
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $flush();
                $depth = \strlen($m[1]) - 1;
                $trail = \array_slice($trail, 0, $depth);
                $trail[$depth] = trim($m[2]);
                $heading = implode(' / ', array_filter($trail));

                continue;
            }

            $buffer[] = $line;
        }

        $flush();

        return $chunks;
    }

    /**
     * Split an over-long section on blank lines, never mid-paragraph.
     *
     * @return list<string>
     */
    private static function split(string $text): array
    {
        if (\strlen($text) <= self::MAX_CHUNK) {
            return [$text];
        }

        $pieces = [];
        $current = '';

        foreach (preg_split('/\R{2,}/u', $text) ?: [] as $paragraph) {
            if ($current !== '' && \strlen($current) + \strlen($paragraph) > self::MAX_CHUNK) {
                $pieces[] = $current;
                $current = '';
            }

            $current = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
        }

        if (trim($current) !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * `[[wikilinks]]` — the note graph, and the reason this is a base rather than a pile of files. An
     * alias (`[[note|shown as]]`) links to the note, not to what it was called at the point of use.
     *
     * @return list<string>
     */
    private static function links(string $body): array
    {
        preg_match_all('/\[\[([^\]|#]+)(?:[|#][^\]]*)?\]\]/', $body, $m);

        return array_values(array_unique(array_map(trim(...), $m[1])));
    }

    /**
     * Source files a note mentions, with the line if it gives one: `src/Foo.php:12`.
     *
     * Kept as exact edges rather than left to the vectors, because "what do we know about this file" has
     * a right answer, and a fuzzy scan would give an approximate one more slowly.
     *
     * @return list<array{file: string, line: int}>
     */
    private static function refs(string $body): array
    {
        preg_match_all('#\b([A-Za-z0-9_./-]+\.(?:php|js|ts|py|go|rs|java|rb|sql|ya?ml|json|md))(?::(\d+))?#', $body, $m, PREG_SET_ORDER);

        $refs = [];
        $seen = [];

        foreach ($m as $match) {
            $file = $match[1];
            $line = isset($match[2]) ? (int) $match[2] : 0;
            $key = $file . ':' . $line;

            if (!str_contains($file, '/') || isset($seen[$key])) {
                continue;   // a bare `README.md` is prose, not a reference to a place in the tree
            }

            $seen[$key] = true;
            $refs[] = ['file' => $file, 'line' => $line];
        }

        return $refs;
    }
}
