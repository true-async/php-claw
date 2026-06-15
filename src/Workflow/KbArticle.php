<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * One article in the knowledge base. The base is a network of articles linked to
 * one another by hyperlinks and organised by tags. Mutable: an agent may extend
 * content, tags and links over time.
 */
final class KbArticle
{
    /**
     * @param list<KbTag>  $tags
     * @param list<string> $links ids of related articles (hyperlinks)
     */
    public function __construct(
        public readonly string $id,
        public string $content,
        public array $tags = [],
        public array $links = [],
        public ?KbProvenance $provenance = null,
    ) {
    }
}
