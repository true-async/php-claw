<?php

declare(strict_types=1);

namespace ClawWorkflow\Library;

use Claw\Project\IssueType;
use Claw\Workflow\GenerationStrategy;

/**
 * Builds behaviour that was asked for and does not exist yet, against a written definition of done.
 *
 * Choose it for a feature whose acceptance can be stated before the work starts — "this input should
 * produce that output", "this endpoint should exist and answer so". The approach turns the ticket's
 * prose into a checkable target FIRST, then builds against that target rather than against the prose,
 * so what "finished" means is settled by something a test can decide and not by anyone's reading.
 *
 * Not for a feature whose shape is genuinely undecided — if the ticket needs someone to choose between
 * materially different designs, that decision comes before this and belongs to a person.
 */
#[GenerationStrategy(IssueType::Feature)]
final class BuildFeatureStrategy
{
    public const string RECIPE = <<<'RECIPE'
        BUILDING A FEATURE — what the solver you write has to accomplish, in order. How many steps that
        costs is your decision; a small feature is one step, and the shape below folds into it.

        1. ESTABLISH WHAT DONE MEANS, BEFORE BUILDING ANYTHING.
           Read the ticket and the code around where the feature will live. Then state the acceptance
           target as something a command can decide — normally a test, in the project's own suite and
           its own style, asserting the behaviour the ticket asks for.

           The test must FAIL first, and the solver must check that it does. A test that passes before
           the feature exists is asserting something that was already true: it will pass at the end too,
           and it will have proved nothing. That is the single most common way a feature ships unbuilt.

           Record the suite's state before any work as evidence — later steps need it to tell a failure
           they caused from one that was already there.

           If the ticket cannot be turned into a checkable target — two readings that mean different
           work, a goal with no observable criterion — do NOT invent one. Ask, with the question
           protocol; a person deciding what was meant is cheaper than building the wrong thing.

        2. BUILD AGAINST THE TARGET, NOT AGAINST THE PROSE.
           The failing test is now the definition of the feature. Implement until it passes, in the
           smallest change that does so honestly.

           Two things that are not the feature: editing the target to agree with what the code already
           does, and special-casing the test's own inputs. Both turn the suite green and ship nothing.

           Touch what the feature needs and no more. A feature ticket is not licence to reorganise the
           code it happens to land in; if something nearby genuinely blocks the work, say so rather than
           quietly widening the change.

        3. PROVE IT AGAINST THE WHOLE PROJECT.
           Run the entire suite, not just the new test, and record the real output as evidence. A feature
           that works while breaking something else has not been delivered — it has been traded.

           Judge failures against the state recorded in step 1: a failure that was already there is not
           this work's to chase, and one that was not is. Name any failure you set aside and say which
           line of the earlier record excuses it.

        WHAT TO REVIEW, AND WHERE. Two claims here are worth an independent check, and they are the two
        boundaries: that the target was real and failing before the work, and that the whole project is
        no worse after it. The middle — the building itself — is judged by whether the target passes, so
        a critic on it should ask about the SHAPE of the change (scope, no edits to the target) rather
        than re-decide the test results.
        RECIPE;
}
