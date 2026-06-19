<?php

declare(strict_types=1);

namespace Claw\Project;

/**
 * Top-level container that unites everything. A project owns its workflows (its
 * procedural memory), its own knowledge base (a store separate from the common
 * one), and its issues. A project is a named scope for procedural and declarative
 * memory.
 *
 * Workflows are NOT listed here: they are added and defined dynamically (by agents,
 * by mutation, by humans), so membership is determined by scope — a workflow that
 * lives in the project's area belongs to it, discovered at runtime rather than
 * tracked in a static list that would drift.
 *
 * The project's working tree is an EXTERNAL folder ({@see $path}, possibly a git
 * repository) that lives elsewhere on disk; the application only owns the state it
 * keeps for that folder ({@see ProjectStore}), never the folder itself.
 */
final class Project
{
    public function __construct(
        public readonly string $id,
        public string $name,
        public readonly string $path = '',
        public string $description = '',
    ) {
    }
}
