<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Project\IssueType;

/**
 * Marks a class as a GENERATION STRATEGY — a written approach to work of a given kind, which the
 * generator reads before writing a solver for one particular ticket.
 *
 * The distinction from {@see LibraryWorkflow} is what the shelf entry IS. A library workflow is code:
 * it runs exactly as written, the same steps every time, and its value is that a person wrote and
 * tested it. A strategy is PROSE: it says what the work of this kind has to accomplish and where its
 * branches are, and the generator decides how many steps that costs for the ticket in front of it.
 *
 * The reason to have both is that what generalises between tickets of one kind is usually the
 * APPROACH, not the code. `FixBugWorkflow`'s three step prompts contain nothing about any particular
 * bug — they are a recipe wearing a class's clothes, and the class shape is what makes them rigid:
 * three steps forever, whatever the defect needs. Prose bends where a class cannot; a class is
 * deterministic where prose is not. Neither replaces the other.
 *
 * Like a library workflow, a strategy declares the issue types it serves, so the ProjectManager is
 * shown only the ones that fit — a wrong pick is not on the list rather than merely discouraged. Its
 * DESCRIPTION is the class docblock, read by reflection, so the paragraph a developer already writes
 * serves both the reader of the file and the model choosing from the catalogue.
 *
 * The recipe itself is the class's `RECIPE` constant. A constant rather than a method because it is
 * a fixed text with nothing to compute: what varies from ticket to ticket is decided by the generator
 * that reads it, not by the strategy.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class GenerationStrategy
{
    /** @var list<IssueType> */
    public readonly array $serves;

    /**
     * @param IssueType ...$serves the kinds of ticket this approach is offered for; at least one,
     *                             because an approach offered for everything is one nobody can choose
     */
    public function __construct(IssueType ...$serves)
    {
        $this->serves = array_values($serves);
    }
}
