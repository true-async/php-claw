<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * Top-level container that unites everything. A project owns its workflows (its
 * procedural memory), its own knowledge base (a store separate from the common
 * one), and its issues. A workflow lives either in a project or in the common
 * area; project workflows are visible only inside the project. In short, a project
 * is a named scope for procedural and declarative memory.
 */
final class Project
{
    /** @param list<string> $workflows names of workflows owned by this project */
    public function __construct(
        public readonly string $id,
        public string $name,
        public string $description = '',
        public array $workflows = [],
    ) {
    }
}
