<?php

declare(strict_types=1);

namespace Claw\Knowledge;

/**
 * The knowledge base — the ONE way in, and the only type outside this namespace may name.
 *
 * Declarative, long-lived memory: what has been LEARNED about a project, outliving every run.
 * Distinct from the other two memories — procedural memory is the solver classes, working memory is
 * the context tree a run holds and drops.
 *
 * NOTHING HERE SAYS HOW IT IS STORED, and that is the whole point of the type. A caller asks a
 * question and gets passages; it does not know whether an answer came from a file or a database,
 * whether a question was embedded, whether an index exists, whether one is stale, or whose job it is
 * to refresh it. Every one of those was a decision leaking out of this subsystem before, into the run
 * pipeline, the tool factory, the CLI and the HTTP API — five callers, each holding a different piece
 * of the mechanism, and `search` answering from an index while `read` answered from the filesystem.
 *
 * SO THERE IS NO `sync()`. Freshness is the base's own business. A caller that has to remember to
 * refresh something is a caller that knows there is something to refresh, and three call sites that
 * remember are three that can forget.
 *
 * READING ONLY. Writing lives on its own interface and is obtained separately, so a palette that must
 * not write cannot be handed a thing that writes — the constraint is the absence of a method rather
 * than an instruction somebody has to honour.
 *
 * A `$note` is an opaque handle this base issues through {@see search()}, {@see notes()},
 * {@see about()} and {@see links()}. Callers pass handles back; they never construct one.
 */
interface KnowledgeBaseInterface
{
    /**
     * Passages that answer a question, best first.
     *
     * $tag narrows to one subject when the caller knows it — filing doing the work that relevance
     * would do worse.
     *
     * @return list<Passage>
     */
    public function search(string $question, int $limit = 5, string $tag = ''): array;

    /**
     * One note in full, as it was written.
     *
     * This is the authoritative text. A search answers from what has been indexed and may lag a note
     * written a moment ago; a read never does.
     *
     * @param string $note a handle this base issued
     *
     * @throws \Claw\Exceptions\ClawException when no such note is readable
     */
    public function read(string $note): string;

    /**
     * A note's sections, so a caller can ask about one part instead of pulling the whole note into
     * context.
     *
     * @param string $note a handle this base issued
     *
     * @return list<array{section: string, text: string}>
     */
    public function outline(string $note): array;

    /**
     * What the notes say about one source file — the exact half of the base.
     *
     * "What do we know about src/Foo.php" has a right answer and costs one lookup; asking it of a
     * fuzzy search would be slower and only approximately right.
     *
     * @return list<array{note: string, title: string, line: int}>
     */
    public function about(string $sourceFile): array;

    /**
     * How the base is filed: every tag with how many notes carry it.
     *
     * Called from prompt assembly on every turn, so it must stay cheap.
     *
     * @return array<string, int>
     */
    public function tags(): array;

    /**
     * Every note in the base — the browsable view.
     *
     * @return list<array{note: string, title: string, tags: list<string>}>
     */
    public function notes(): array;

    /**
     * What this note links to, and what links back to it.
     *
     * @param string $note a handle this base issued
     *
     * @return array{out: list<string>, in: list<string>}
     */
    public function links(string $note): array;

    /**
     * This project's own rules for keeping the base, as written down in it.
     *
     * A caller shows this to a model instead of carrying a copy of the rules: each project may rewrite
     * them, and a copy in a prompt would disagree with the file the moment anyone edited it.
     */
    public function conventions(): string;

    /**
     * Where a person should put notes, for the CLI and the dashboard to say out loud.
     *
     * The one place the layout is deliberately spoken, because a human has to open a folder. It is an
     * ANSWER from the base, not a constant a caller assembles.
     */
    public function location(): string;
}
