<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Declarative, long-lived memory shared across runs: facts and conclusions of
 * past workflows. Distinct from the working memory (context tree) and the
 * procedural memory (workflows as code).
 *
 * search() is the semantic/RAG entry point, needed later as the base grows.
 * Writing is allowed to an agent only when the workflow grants that capability.
 */
interface KnowledgeBase
{
    public function read(string $key): ?string;

    public function write(string $key, string $value): void;

    /**
     * Semantic lookup over the base (RAG). Skeleton for now.
     *
     * @return list<string>
     */
    public function search(string $query): array;
}
