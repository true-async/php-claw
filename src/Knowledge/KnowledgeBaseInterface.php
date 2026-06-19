<?php

declare(strict_types=1);

namespace Claw\Knowledge;

/**
 * Declarative, long-lived memory: a network of articles linked by hyperlinks and
 * organised by tags, distinct from a workflow's working memory (context tree) and
 * procedural memory (workflows as code).
 *
 * Exposed to agents as a Tool with its own usage docs. Agents may add and update
 * articles (and tags) when the workflow grants the capability. Each article can
 * cite its provenance: which workflow and which moment in history caused it.
 * Scopes: shared, session, and a project's own base.
 *
 * The reference implementation stores articles as Obsidian-compatible Markdown
 * (frontmatter for tags/weights/provenance, [[wikilinks]] for links, folders for
 * scope) kept in the repository; sqlite-vec indexes them for search().
 */
interface KnowledgeBaseInterface
{
    public function get(string $id): ?Article;

    /**
     * Semantic lookup over the base (RAG). Skeleton for now.
     *
     * @return list<Article>
     */
    public function search(string $query): array;

    /** @return list<Article> */
    public function byTag(string $tag): array;

    /** Add a new article; returns its id. */
    public function add(Article $article): string;

    /** Update an existing article (content, tags, links). */
    public function update(Article $article): void;
}
