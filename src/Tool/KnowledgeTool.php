<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;
use Claw\Knowledge\EmbedderInterface;
use Claw\Knowledge\Indexer;
use Claw\Knowledge\KnowledgeIndex;

/**
 * The knowledge base, as one tool: what has been LEARNED about this project.
 *
 * Distinct from the other two memories a run has. Procedural memory is the solver classes — how work of
 * a kind gets done. Working memory is the context tree — what this run is holding right now, gone when
 * it ends. This is the declarative one: notes a person or an earlier run wrote down, in the project's
 * `kb/` folder as Obsidian markdown, outliving every run.
 *
 * TWO KINDS OF LOOKUP, and choosing between them is most of using this well:
 *
 *  - `search` is semantic and fuzzy. Ask it a question in words and it finds the passage that answers
 *    it, even worded differently. Use it when you do not know where the answer lives.
 *  - `about` is exact and instant. It answers "what do we know about src/Foo.php" from the references
 *    notes make to the code, with no embedding call and no approximation. Use it whenever you have a
 *    path, because a fuzzy scan would be slower and only roughly right.
 */
final readonly class KnowledgeTool implements ToolInterface
{
    public function __construct(
        private KnowledgeIndex $index,
        private EmbedderInterface $embedder,
        private string $folder,
    ) {
    }

    public function name(): string
    {
        return 'knowledge';
    }

    public function description(): string
    {
        $tags = array_keys($this->index->tagCounts());
        $filed = $tags === [] ? '' : ' Tags currently in use: ' . implode(', ', \array_slice($tags, 0, 25)) . '.';

        // The rules are POINTED AT, never quoted. Each project keeps its own in that page and may
        // rewrite them entirely; a description that carried a copy would silently disagree with the
        // file the moment somebody edited it, and the model would have no way to tell which was true.
        return "This project's knowledge base — what has been LEARNED about it, outliving any one run. "
            . "It is markdown in the project's kb/ folder, filed in three kinds:\n"
            . "- decisions/ — what was decided and WHY, so a choice is not re-argued from nothing;\n"
            . "- postmortems/ — what went wrong and what it cost, so it is not paid for twice;\n"
            . "- design/ — how a part of the system works, one document per subject.\n"
            . 'THIS PROJECT SETS ITS OWN RULES for the base, and they are one call away: '
            . "action='read' with path='" . Indexer::README . "' returns the index — what the base "
            . 'holds and how this project expects it to be kept. Read it before relying on the base, '
            . "and before filing anything; the conventions below are only the defaults it may replace.\n"
            . "Every note carries tags, and a tag narrows a search to its subject.\n\n"
            . "action='search' (query, optional tag, optional limit) finds passages that ANSWER a "
            . 'question, worded however they happen to be worded; use it when you do not know where the '
            . 'answer lives, and pass a tag when you do know the subject. '
            . "action='about' (file) lists what the notes say about one source path, exactly and "
            . 'instantly; use it whenever you have a path in hand rather than searching for it. '
            . "action='read' (path) returns one note in full, for when a result is worth reading around. "
            . "action='tags' lists what the base is filed under."
            . $filed;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['search', 'about', 'read', 'tags']],
                'query' => ['type' => 'string', 'description' => "what you want to know, in words — action='search'"],
                'file' => ['type' => 'string', 'description' => "a source path, e.g. src/Run/Triage.php — action='about'"],
                'path' => ['type' => 'string', 'description' => "a note's path inside kb/ — action='read'"],
                'tag' => ['type' => 'string', 'description' => "narrow the search to one subject — action='search'"],
                'limit' => ['type' => 'integer', 'description' => 'how many passages to return (default 5)'],
            ],
            'required' => ['action'],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $action = \is_scalar($input['action'] ?? null) ? (string) $input['action'] : '';

        return match ($action) {
            'search' => $this->search($input),
            'about' => $this->about($input),
            'read' => $this->read($input),
            'tags' => $this->tags(),
            default => throw new ToolException("knowledge: unknown action '{$action}' (use search|about|read|tags)"),
        };
    }

    /** @param array<string, mixed> $input */
    private function search(array $input): string
    {
        $query = trim(\is_scalar($input['query'] ?? null) ? (string) $input['query'] : '');

        if ($query === '') {
            throw new ToolException("knowledge: action='search' needs a 'query' — say what you want to know");
        }

        // Bring the index up to date first. It is incremental — a stat per note, a hash only where the
        // stat moved, an embedding call only where the hash changed — so the common case is a few
        // milliseconds, and the alternative is answering out of a base that silently predates the notes.
        new Indexer($this->index, $this->embedder)->sync($this->folder);

        $tag = trim(\is_scalar($input['tag'] ?? null) ? (string) $input['tag'] : '');
        $vectors = $this->embedder->embed([$query]);
        $hits = $this->index->search($query, $vectors[0] ?? [], $this->limitOf($input), $tag);

        if ($hits === []) {
            $where = $tag === '' ? 'the knowledge base' : "notes tagged '{$tag}'";

            return "Nothing in {$where} matches that. It may simply not have been written down"
                . ($tag === '' ? '.' : ", or filed under a different tag — action='tags' lists them.");
        }

        $lines = [];

        foreach ($hits as $hit) {
            $where = $hit['heading'] === '' ? $hit['path'] : "{$hit['path']} — {$hit['heading']}";
            $filed = $hit['tags'] === [] ? '' : ' #' . implode(' #', $hit['tags']);
            $also = $hit['links'] === [] ? '' : "\n  see also: " . implode(', ', $hit['links']);
            // No relevance number: the score is now a rank-fusion weight, which is meaningful for
            // ordering and meaningless to read. The order IS the signal.
            $lines[] = sprintf("[%s]%s\n%s%s", $where, $filed, trim($hit['text']), $also);
        }

        return implode("\n\n---\n\n", $lines);
    }

    /** @param array<string, mixed> $input */
    private function about(array $input): string
    {
        $file = trim(\is_scalar($input['file'] ?? null) ? (string) $input['file'] : '');

        if ($file === '') {
            throw new ToolException("knowledge: action='about' needs a 'file' — the source path to look up");
        }

        new Indexer($this->index, $this->embedder)->sync($this->folder);
        $found = $this->index->about($file);

        if ($found === []) {
            return "The knowledge base says nothing about {$file}.";
        }

        $lines = [];

        foreach ($found as $mention) {
            $lines[] = $mention['line'] > 0
                ? "- {$mention['path']} (about line {$mention['line']})"
                : "- {$mention['path']}";
        }

        return "Notes mentioning {$file}:\n" . implode("\n", $lines)
            . "\n\nRead one in full with action='read'.";
    }

    /** @param array<string, mixed> $input */
    private function read(array $input): string
    {
        $path = trim(\is_scalar($input['path'] ?? null) ? (string) $input['path'] : '');

        if ($path === '') {
            throw new ToolException("knowledge: action='read' needs a 'path' — the note to read");
        }

        // Confined to the notes folder for the same reason every other file tool is confined: a path is
        // a model's to choose, and `../../.env` is a path.
        $note = new Workspace($this->folder)->resolveExisting($path);
        $markdown = @file_get_contents($note);

        if ($markdown === false) {
            throw new ToolException("knowledge: cannot read {$path}");
        }

        return $markdown;
    }

    /** What the base is filed under, so a model narrows a search with a real tag rather than a guessed one. */
    private function tags(): string
    {
        new Indexer($this->index, $this->embedder)->sync($this->folder);
        $counts = $this->index->tagCounts();

        if ($counts === []) {
            return 'No notes are tagged yet.';
        }

        $lines = [];

        foreach ($counts as $tag => $n) {
            $lines[] = "- {$tag} ({$n})";
        }

        return "The knowledge base is filed under:\n" . implode("\n", $lines);
    }

    /** @param array<string, mixed> $input */
    private function limitOf(array $input): int
    {
        $limit = \is_scalar($input['limit'] ?? null) ? (int) $input['limit'] : 5;

        return max(1, min(20, $limit));
    }
}
