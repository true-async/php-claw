<?php

declare(strict_types=1);

namespace Claw\Knowledge;

/**
 * One article in the knowledge base. The base is a network of articles linked to
 * one another by hyperlinks and organised by tags. Mutable: an agent may extend
 * content, tags and links over time. Stored as an Obsidian-compatible Markdown
 * file (frontmatter for tags/weights/provenance, [[wikilinks]] for links).
 */
final class Article
{
    /**
     * @param list<Tag>    $tags
     * @param list<string> $links ids of related articles (hyperlinks)
     */
    public function __construct(
        public readonly string $id,
        public string $content,
        public array $tags = [],
        public array $links = [],
        public ?Provenance $provenance = null,
    ) {
    }
}
