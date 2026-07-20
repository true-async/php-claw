<?php

declare(strict_types=1);

namespace Claw\Project;

/**
 * A unit of work inside a project: a stated task or problem, like a tracker ticket.
 * Workflow runs are spawned under an issue, and the issue tracks work above the
 * level of a single run: one issue may spawn several runs, survive waiting_human
 * pauses, and collect the result. It ties a human request ("I want X") to its
 * execution (workflow plus parameters).
 *
 * Issues form a TREE. A task too big for one run is decomposed into sub-issues, each
 * carrying $parent and a $depth one greater than its parent's. Depth is what bounds the
 * recursion: a decomposition that has reached the cap is refused in code, so a planner
 * cannot keep splitting work into ever-smaller pieces instead of doing it. A root issue
 * has no parent and depth 0.
 */
final class Issue
{
    /**
     * @param list<string> $runs   ids of workflow runs spawned for this issue
     * @param ?string      $parent id of the issue this was decomposed out of; null for a root issue
     * @param int          $depth  distance from the root — 0 for a root issue, parent's depth + 1 otherwise
     * @param ?IssueType   $type   what kind of work this is; null until the ProjectManager has typed it
     */
    public function __construct(
        public readonly string $id,
        public readonly string $project,   // owning project id
        public string $title,
        public string $description = '',
        public IssueStatus $status = IssueStatus::Open,
        public array $runs = [],
        public readonly ?string $parent = null,
        public readonly int $depth = 0,
        public ?IssueType $type = null,
    ) {
    }
}
