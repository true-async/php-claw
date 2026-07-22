<?php

declare(strict_types=1);

namespace Claw\Knowledge;

/**
 * One passage that answers a question — what a caller gets back from a search.
 *
 * Deliberately NOT the shape the index stores. There is no score: the fusion weight behind the
 * ordering is meaningful for sorting and meaningless to read, and publishing it would export the
 * ranking algorithm to everyone who displays a result. There is no chunk id and no file path: `$note`
 * is a handle the base issues and only the base interprets, so a caller can pass it back to
 * {@see KnowledgeBaseInterface::read()} without ever learning that notes are files in a folder.
 */
final readonly class Passage
{
    /**
     * @param string       $note    a handle to the note this came from, valid only as an argument back
     * @param string       $section where in the note it sits, in the note's own headings
     * @param list<string> $tags    how the note is filed
     * @param list<string> $seeAlso handles of notes this one links to
     */
    public function __construct(
        public string $note,
        public string $section,
        public string $text,
        public array $tags = [],
        public array $seeAlso = [],
    ) {
    }
}
