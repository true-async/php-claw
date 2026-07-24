<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ClawException;
use Claw\Exceptions\ToolException;
use Claw\Knowledge\KnowledgeBaseInterface;

/**
 * The knowledge base, as one tool: what has been LEARNED about this project.
 *
 * Distinct from the other two memories a run has. Procedural memory is the solver classes — how work of
 * a kind gets done. Working memory is the context tree — what this run is holding right now, gone when
 * it ends. This is the declarative one: notes a person or an earlier run wrote down, outliving every run.
 *
 * THIS CLASS TRANSLATES, IT DOES NOT DECIDE. It turns a model's action into a call on
 * {@see KnowledgeBaseInterface} and the answer into prose. It holds no folder, no index, no embedder;
 * it does not know whether an answer came off a disk or out of a database, and it never refreshes
 * anything. All of that used to live here, and having it here is why the same subsystem was reached
 * five different ways.
 *
 * TWO KINDS OF LOOKUP, and choosing between them is most of using this well:
 *
 *  - `search` is semantic and fuzzy. Ask it a question in words and it finds the passage that answers
 *    it, even worded differently. Use it when you do not know where the answer lives.
 *  - `about` is exact and instant. It answers "what do we know about src/Foo.php" from the references
 *    notes make to the code. Use it whenever you have a path, because a fuzzy search would be slower
 *    and only roughly right.
 */
final readonly class KnowledgeTool implements ToolInterface
{
    public function __construct(private KnowledgeBaseInterface $knowledge)
    {
    }

    public function name(): string
    {
        return 'knowledge';
    }

    public function description(): string
    {
        $tags = array_keys($this->knowledge->tags());
        $filed = $tags === [] ? '' : ' Tags currently in use: ' . implode(', ', \array_slice($tags, 0, 25)) . '.';

        // The rules are POINTED AT, never quoted. Each project keeps its own and may rewrite them; a
        // description carrying a copy would disagree with the real page the moment somebody edited it,
        // and a model would have no way to tell which was true.
        return "This project's knowledge base — what has been LEARNED about it, outliving any one run. "
            . "Notes are filed in three kinds:\n"
            . "- decisions/ — what was decided and WHY, so a choice is not re-argued from nothing;\n"
            . "- postmortems/ — what went wrong and what it cost, so it is not paid for twice;\n"
            . "- design/ — how a part of the system works, one document per subject.\n"
            . "THIS PROJECT SETS ITS OWN RULES for the base and action='conventions' returns them — what "
            . 'the base holds and how this project expects it to be kept. Read them before relying on '
            . "the base; the three kinds above are only the defaults a project may replace.\n"
            . "Every note carries tags, and a tag narrows a search to its subject.\n\n"
            . "action='search' (query, optional tag, optional limit) finds passages that ANSWER a "
            . 'question, worded however they happen to be worded; use it when you do not know where the '
            . 'answer lives, and pass a tag when you do know the subject. '
            . "action='about' (file) lists what the notes say about one source path, exactly and "
            . 'instantly; use it whenever you have a path in hand rather than searching for it. '
            . "action='read' (note) returns one note in full, for when a result is worth reading around. "
            . "action='outline' (note) lists its sections, for when it is not. "
            . "action='tags' lists what the base is filed under."
            . $filed;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['search', 'about', 'read', 'outline', 'tags', 'conventions']],
                'query' => ['type' => 'string', 'description' => "what you want to know, in words — action='search'"],
                'file' => ['type' => 'string', 'description' => "a source path, e.g. src/Run/Triage.php — action='about'"],
                'note' => ['type' => 'string', 'description' => "a note, exactly as a result named it — action='read'/'outline'"],
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

        try {
            return match ($action) {
                'search' => $this->search($input),
                'about' => $this->about($input),
                'read' => $this->knowledge->read($this->noteOf($input)),
                'outline' => $this->outline($input),
                'tags' => $this->tags(),
                'conventions' => $this->conventions(),
                default => throw new ToolException(
                    "knowledge: unknown action '{$action}' (use search|about|read|outline|tags|conventions)",
                ),
            };
        } catch (ToolException $e) {
            throw $e;
        } catch (ClawException $e) {
            // The base refused — an unreadable note, a path outside it. That is an answer for the
            // model to act on, not a failure of the run.
            throw new ToolException($e->getMessage(), 0, $e);
        }
    }

    /** @param array<string, mixed> $input */
    private function search(array $input): string
    {
        $query = trim(\is_scalar($input['query'] ?? null) ? (string) $input['query'] : '');

        if ($query === '') {
            throw new ToolException("knowledge: action='search' needs a 'query' — say what you want to know");
        }

        $tag = trim(\is_scalar($input['tag'] ?? null) ? (string) $input['tag'] : '');
        $passages = $this->knowledge->search($query, $this->limitOf($input), $tag);

        if ($passages === []) {
            $where = $tag === '' ? 'the knowledge base' : "notes tagged '{$tag}'";

            return "Nothing in {$where} matches that. It may simply not have been written down"
                . ($tag === '' ? '.' : ", or filed under a different tag — action='tags' lists them.");
        }

        $lines = [];

        foreach ($passages as $passage) {
            $where = $passage->section === '' ? $passage->note : "{$passage->note} — {$passage->section}";
            $filed = $passage->tags === [] ? '' : ' #' . implode(' #', $passage->tags);
            $also = $passage->seeAlso === [] ? '' : "\n  see also: " . implode(', ', $passage->seeAlso);
            // No relevance number: the base does not publish one, because the order IS the signal.
            $lines[] = sprintf("[%s]%s\n%s%s", $where, $filed, $passage->text, $also);
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

        $found = $this->knowledge->about($file);

        if ($found === []) {
            return "The knowledge base says nothing about {$file}.";
        }

        $lines = [];

        foreach ($found as $mention) {
            $lines[] = $mention['line'] > 0
                ? "- {$mention['note']} ({$mention['title']}, about line {$mention['line']})"
                : "- {$mention['note']} ({$mention['title']})";
        }

        return "Notes mentioning {$file}:\n" . implode("\n", $lines)
            . "\n\nRead one in full with action='read'.";
    }

    /** @param array<string, mixed> $input */
    private function outline(array $input): string
    {
        $sections = $this->knowledge->outline($this->noteOf($input));

        if ($sections === []) {
            return 'That note has no sections to list.';
        }

        $lines = [];

        foreach ($sections as $section) {
            $lines[] = '- ' . ($section['section'] === '' ? '(untitled opening)' : $section['section']);
        }

        return "Sections:\n" . implode("\n", $lines) . "\n\nRead the whole note with action='read'.";
    }

    /** What the base is filed under, so a model narrows a search with a real tag rather than a guessed one. */
    private function tags(): string
    {
        $counts = $this->knowledge->tags();

        if ($counts === []) {
            return 'No notes are tagged yet.';
        }

        $lines = [];

        foreach ($counts as $tag => $n) {
            $lines[] = "- {$tag} ({$n})";
        }

        return "The knowledge base is filed under:\n" . implode("\n", $lines);
    }

    private function conventions(): string
    {
        $rules = trim($this->knowledge->conventions());

        return $rules === ''
            ? 'This project has not written down how it keeps its knowledge base.'
            : $rules;
    }

    /** @param array<string, mixed> $input */
    private function noteOf(array $input): string
    {
        // `path` is still accepted: it is what the action was called before, and a model that has seen
        // an older description should not get an error it cannot act on.
        $note = trim(\is_scalar($input['note'] ?? null) ? (string) $input['note'] : '');
        $note = $note !== '' ? $note : trim(\is_scalar($input['path'] ?? null) ? (string) $input['path'] : '');

        if ($note === '') {
            throw new ToolException("knowledge: that action needs a 'note' — name one a result gave you");
        }

        return $note;
    }

    /** @param array<string, mixed> $input */
    private function limitOf(array $input): int
    {
        $limit = \is_scalar($input['limit'] ?? null) ? (int) $input['limit'] : 5;

        return max(1, min(20, $limit));
    }
}
