<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * A unit of work inside a project: a stated task or problem, like a tracker ticket.
 * Workflow runs are spawned under an issue, and the issue tracks work above the
 * level of a single run: one issue may spawn several runs, survive waiting_human
 * pauses, and collect the result. It ties a human request ("I want X") to its
 * execution (workflow plus parameters).
 */
final class Issue
{
    /** @param list<string> $runs ids of workflow runs spawned for this issue */
    public function __construct(
        public readonly string $id,
        public readonly string $project,   // owning project id
        public string $title,
        public string $description = '',
        public IssueStatus $status = IssueStatus::Open,
        public array $runs = [],
    ) {
    }
}
