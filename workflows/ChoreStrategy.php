<?php

declare(strict_types=1);

namespace ClawWorkflow\Library;

use Claw\Project\IssueType;
use Claw\Workflow\GenerationStrategy;

/**
 * Mechanical upkeep applied everywhere it belongs: a dependency bump, a rename across the project, a
 * codemod, a config change, documentation that has fallen behind the code.
 *
 * Choose it when the change itself is obvious and the difficulty is COMPLETENESS — doing it in every
 * place, and being able to show that no place was missed. That is the whole risk of this kind of work
 * and the reason it has its own approach.
 *
 * Not for anything where the change requires judgement per site. If each occurrence needs a decision,
 * it is not mechanical and this will hide that behind a search-and-replace.
 */
#[GenerationStrategy(IssueType::Chore)]
final class ChoreStrategy
{
    public const string RECIPE = <<<'RECIPE'
        MECHANICAL UPKEEP — what the solver you write has to accomplish. The change is easy; missing a
        place is the failure, so the shape below is built around proving you did not.

        1. FIND EVERY PLACE FIRST, AND WRITE THE SEARCH DOWN.
           Before changing anything, run the search that finds every occurrence — grep, the package
           manager's own listing, whatever is exact — and record its verbatim output.

           Two reasons it comes first. It is the denominator: at the end you re-run the SAME search and
           the count must be zero, which is only meaningful if the search was fixed before the work
           could influence it. And it is the sanity check: a search returning three hits for something
           you expected everywhere means the search is wrong, and finding that out now costs nothing.

           Record the exact search string. Step 3 re-runs it, and a slightly different one proves
           nothing.

        2. APPLY IT EVERYWHERE, THE SAME WAY.
           Make the change at every site the search found. Mechanical means mechanical: the same
           transformation, not a judgement per occurrence.

           If a site turns out to need a DIFFERENT change — the rename does not fit there, the bumped
           dependency broke that one call — stop and say so with the question protocol. That site is not
           part of this chore, and quietly hand-crafting an exception is how a codemod leaves a mess
           nobody can see the shape of.

           Do not widen the change while you are in there. A chore that also tidies is a chore nobody
           can review.

        3. PROVE NOTHING WAS LEFT BEHIND, AND NOTHING BROKE.
           Two checks, both recorded verbatim, and both necessary:
           - re-run the SEARCH FROM STEP 1. Zero remaining occurrences, or the ones remaining are named
             and explained. This is the check unique to this kind of work: a rename that misses three
             call sites still compiles in a dynamic language and fails at run time, months later.
           - run the whole test suite. Mechanical is not the same as safe.

        WHAT TO REVIEW, AND WHERE. The two ends. That the search was real and complete before the work,
        and that re-running it comes back empty afterwards — the reviewer should run it themselves, not
        read a claim about it. The middle should be reviewed for uniformity: does the diff do the same
        thing everywhere, or does it contain a site that was quietly treated differently?
        RECIPE;
}
