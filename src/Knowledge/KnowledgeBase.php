<?php

declare(strict_types=1);

namespace Claw\Knowledge;

use Claw\Exceptions\ClawException;
use Claw\Project\Project;

/**
 * The knowledge base, assembled. The only class outside this namespace ever names is the interface;
 * this is the one place that knows there is a folder, an index, a parser and an embedder at all.
 *
 * It is a composition root, not a god object. Parsing markdown, deciding what is stale, storing and
 * ranking, and paying for embeddings stay four separate classes behind this door — collapsing them
 * into one would trade a leaky surface for an unreadable middle. What this class owns is the
 * *policy* that used to be spread across five callers: where things live, and when the index is
 * brought up to date.
 */
final class KnowledgeBase implements KnowledgeBaseInterface
{
    /** Has this instance brought the index up to date yet? See {@see fresh()}. */
    private bool $synced = false;

    private function __construct(
        private readonly KnowledgeIndex $index,
        private readonly EmbedderInterface $embedder,
        private readonly string $folder,
        private readonly string $lockPath,
    ) {
    }

    /**
     * The base for a project, or null when it cannot work.
     *
     * Null rather than an empty base that answers "nothing is written down": a caller that gets null
     * offers no tool at all, and a model never spends turns interrogating something that cannot
     * answer. With no embedder there is nothing to turn a question into a search, and an unwritable
     * project folder has nowhere to keep notes — both are reasons to have no base, not to have a
     * broken one.
     */
    public static function forProject(Project $project, string $projectsDir, ?EmbedderInterface $embedder): ?self
    {
        if ($embedder === null) {
            return null;
        }

        $folder = self::create($project->path);

        if ($folder === null) {
            return null;
        }

        return new self(
            KnowledgeIndex::openAt($projectsDir . \DIRECTORY_SEPARATOR . $project->id . '.kb.db'),
            $embedder,
            $folder,
            $projectsDir . \DIRECTORY_SEPARATOR . $project->id . '.kb.lock',
        );
    }

    /**
     * Make sure a project has somewhere to keep notes, and say where that is. Null when it could not
     * be made.
     *
     * Separate from {@see forProject()} because creation happens at registration, before there is a
     * project row to pass or an embedder to search with — and because a caller that only needs to
     * tell a person where to write should not have to build a retrieval stack to find out.
     */
    public static function create(string $projectPath): ?string
    {
        $folder = $projectPath . \DIRECTORY_SEPARATOR . Indexer::FOLDER;

        return Indexer::scaffold($folder) ? $folder : null;
    }

    public function search(string $question, int $limit = 5, string $tag = ''): array
    {
        $this->fresh();

        $vectors = $this->embedder->embed([$question]);
        $passages = [];

        foreach ($this->index->search($question, $vectors[0] ?? [], $limit, $tag) as $hit) {
            $passages[] = new Passage(
                note: $hit['path'],
                section: $hit['heading'],
                text: trim($hit['text']),
                tags: $hit['tags'],
                seeAlso: $hit['links'],
            );
        }

        return $passages;
    }

    /**
     * The note as written, read from where it is kept rather than reassembled from what was indexed.
     *
     * Not an implementation detail worth hiding from ourselves: chunks drop frontmatter and heading
     * lines on the way in, so a note rebuilt from them is not the note. This is also why the two can
     * disagree — a note written a moment ago is readable before it is searchable — and stating which
     * one is authoritative is the whole answer to a question the callers used to answer differently.
     */
    public function read(string $note): string
    {
        $markdown = @file_get_contents($this->resolve($note));

        if ($markdown === false) {
            throw new ClawException("knowledge: cannot read {$note}");
        }

        return $markdown;
    }

    public function outline(string $note): array
    {
        $this->fresh();

        return $this->index->sectionsOf($note);
    }

    public function about(string $sourceFile): array
    {
        $this->fresh();

        return $this->index->about($sourceFile);
    }

    /**
     * Tags, with the index brought up to date first like every other indexed read.
     *
     * This one is called from prompt assembly on every turn, so the cost matters — which is exactly
     * why {@see fresh()} runs at most once per instance. The first call in a run pays for whatever
     * changed since the last one; every later call is a boolean.
     */
    public function tags(): array
    {
        $this->fresh();

        return $this->index->tagCounts();
    }

    public function notes(): array
    {
        $this->fresh();

        return $this->index->notes();
    }

    public function links(string $note): array
    {
        $this->fresh();

        return $this->index->linksBothWays($note);
    }

    public function conventions(): string
    {
        try {
            return $this->read(Indexer::README);
        } catch (ClawException) {
            return '';   // a base whose rules page was deleted still works; it just has no rules to show
        }
    }

    public function location(): string
    {
        return $this->folder;
    }

    /**
     * Bring the index up to date, at most once per instance, and never twice at the same time across
     * processes.
     *
     * ONCE PER INSTANCE because repetition is not a cost problem but a correctness one: a warm pass is
     * well under a millisecond, while three call sites each triggering their own pass is three chances
     * to do it at a moment nobody chose.
     *
     * SERIALIZED because two runs of ONE project are started concurrently by design — the server keys
     * active runs by issue, not by project — and both used to walk the same folder and write the same
     * rows. Measured before this lock existed: concurrent passes over a fresh database killed runs
     * outright with "database is locked", and when both survived, both paid for embedding every note.
     *
     * THE LOSER DOES NOT WAIT. It proceeds against whatever is already indexed, which is safe because
     * a pass commits one note at a time and writes each note's row last: an interrupted or overtaken
     * index is STALE, never wrong. Waiting would buy a marginally fresher answer at the price of
     * blocking a run behind another run's HTTP calls.
     */
    private function fresh(): void
    {
        if ($this->synced) {
            return;
        }

        $this->synced = true;   // set first: a failed pass must not be retried on every call
        $lock = @fopen($this->lockPath, 'c');

        if ($lock === false) {
            new Indexer($this->index, $this->embedder)->sync($this->folder);

            return;
        }

        try {
            if (flock($lock, \LOCK_EX | \LOCK_NB)) {
                new Indexer($this->index, $this->embedder)->sync($this->folder);
                flock($lock, \LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /**
     * A handle to a readable file inside the notes folder.
     *
     * The handle originates here, but a caller can pass back anything, and a model chooses what a
     * caller passes — so `../../.env` is a possible argument and is refused. Confinement lives in this
     * class rather than being borrowed from the tool layer: the base is not a way around a guard, and
     * it must not depend on the layer that calls it to hold one.
     *
     * @throws ClawException
     */
    private function resolve(string $note): string
    {
        $root = realpath($this->folder);
        $path = realpath($this->folder . \DIRECTORY_SEPARATOR . ltrim($note, '/\\'));

        if ($root === false || $path === false || !is_file($path)) {
            throw new ClawException("knowledge: no such note: {$note}");
        }

        if ($path !== $root && !str_starts_with($path, $root . \DIRECTORY_SEPARATOR)) {
            throw new ClawException("knowledge: {$note} is outside the notes folder");
        }

        return $path;
    }
}
