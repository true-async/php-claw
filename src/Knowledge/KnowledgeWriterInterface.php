<?php

declare(strict_types=1);

namespace Claw\Knowledge;

/**
 * The write half of the knowledge base — deliberately its OWN interface, exactly as
 * {@see KnowledgeBaseInterface} promises: reading and writing are obtained separately, so a caller
 * that must not write is simply never handed this type. The constraint is the absence of a method,
 * not an instruction somebody has to honour.
 */
interface KnowledgeWriterInterface
{
    /**
     * File one NEW note into the base and return the handle it can be read back by.
     *
     * $kind names the question the note answers — 'decision' (what was decided and WHY),
     * 'postmortem' (what went wrong and what it cost), 'design' (how a part of the system works) —
     * and decides where the note is filed. An existing note is never overwritten: a colliding title
     * gets a fresh name, because by the base's own conventions a decision that replaces an earlier
     * one is a NEW note, not an edit of the old.
     *
     * The note is readable (and thus authoritative) immediately; searchable from the next indexing
     * pass — the same read-beats-search lag {@see KnowledgeBaseInterface::read()} documents.
     *
     * @param list<string> $tags
     *
     * @throws \Claw\Exceptions\ClawException when the kind is unknown, the title or body is empty,
     *                                        or the note cannot be written
     */
    public function record(string $kind, string $title, string $body, array $tags = []): string;
}
