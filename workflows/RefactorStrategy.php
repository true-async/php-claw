<?php

declare(strict_types=1);

namespace ClawWorkflow\Library;

use Claw\Project\IssueType;
use Claw\Workflow\GenerationStrategy;

/**
 * Changes the shape of code without changing what it does.
 *
 * Choose it when the ticket asks for the same behaviour expressed differently — extract this, rename
 * that, split a class, remove a duplication. The defining property is that there is nothing new to
 * prove: the tests that passed before must pass after, unchanged, and that is the whole acceptance
 * criterion.
 *
 * Not for a refactor that is really a rewrite, where behaviour is expected to move. If the ticket
 * cannot say what must stay identical, it is not this.
 */
#[GenerationStrategy(IssueType::Refactor)]
final class RefactorStrategy
{
    public const string RECIPE = <<<'RECIPE'
        REFACTORING — what the solver you write has to accomplish. How many steps that costs is your
        decision; the shape below folds into one step for a small change.

        1. RECORD THE GREEN, BEFORE TOUCHING ANYTHING.
           Run the project's whole test suite and keep its verbatim output. This is not ceremony — it is
           the entire acceptance criterion of the work, captured before the work can influence it.

           If the suite is not green to begin with, say so and STOP with the question protocol. A
           refactor is "the same tests still pass"; starting from red means there is no such statement
           to make, and a person needs to decide whether to fix first or proceed knowing it.

           Note in the same breath WHICH tests cover the code about to change. A refactor of code
           nothing tests is not verifiable, and pretending otherwise is how a silent behaviour change
           ships. If nothing covers it, say so with the question marker rather than proceeding: writing
           the missing test first is usually the right answer, and it is not yours to decide alone.

        2. CHANGE THE SHAPE, NOT THE BEHAVIOUR.
           Make the structural change the ticket asks for and nothing else.

           The temptation to resist is the improvement nobody asked for: while renaming a method, also
           fixing its off-by-one, also tightening a type, also reordering the arguments "since we are
           here". Each of those is a behaviour change wearing a refactor's clothes, and it is invisible
           precisely because the diff is already large.

           If you find a real defect while working, do NOT fix it here. Say what you found; a bug found
           during a refactor is worth a ticket of its own, where it gets a failing test first.

        3. PROVE NOTHING MOVED.
           Run the whole suite again and record the verbatim output. Compare it against step 1: the same
           tests must pass, and passing a DIFFERENT set is a failure even when the total is higher — a
           test that started passing is behaviour that changed.

           If anything differs, the refactor is wrong until explained. "It was already flaky" is a claim
           that needs the step-1 record behind it, and you have that record.

        WHAT TO REVIEW, AND WHERE. Two claims are worth an independent check and they are the two ends:
        that the suite was green and covered the code before, and that the same suite passes the same
        way after. The middle — the change itself — should be reviewed for SHAPE: is it confined to what
        the ticket named, and does the diff contain anything that is not structural?
        RECIPE;
}
