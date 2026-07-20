<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Project\IssueType;

/**
 * Marks a workflow class as a ready-made one the ProjectManager may CHOOSE for a ticket, and declares
 * the kinds of work it handles. Only classes carrying this are offered: a library folder also holds
 * base classes and helpers, and a workflow that has to be picked deliberately should say so rather
 * than be discovered by virtue of sitting in a directory.
 *
 * The types are what makes the choice sound. The ProjectManager is shown only the workflows that
 * serve the type its ticket was given, so "this is a bug, use the research workflow" is not a mistake
 * it can make — the option was never on the list. A rule that merely told it to match would be one
 * more instruction to be ignored.
 *
 * The DESCRIPTION is not here: it is the class's own docblock, read by reflection. Prose belongs
 * where a developer already writes it, and the same paragraph then serves the reader of the file and
 * the model choosing from the catalogue. This attribute carries only what has to be machine-checked.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class LibraryWorkflow
{
    /** @var list<IssueType> */
    public readonly array $serves;

    /**
     * @param IssueType ...$serves the kinds of ticket this workflow is offered for; at least one, because
     *                             a workflow offered for everything is a workflow nobody can choose between
     */
    public function __construct(IssueType ...$serves)
    {
        $this->serves = array_values($serves);
    }
}
