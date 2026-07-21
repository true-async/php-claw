<?php

declare(strict_types=1);

namespace ClawWorkflow\Library;

use Claw\Project\IssueType;
use Claw\Workflow\GenerationStrategy;

/**
 * Finds something out and reports it. Changes nothing.
 *
 * Choose it when the ticket asks a question about the project rather than for a change to it: how does
 * this work, where is this decided, what would it take to do that, why is it like this. The deliverable
 * is an answer somebody can act on and check.
 *
 * The whole difficulty is that there is no test to go green. Nothing here can be proved by running it,
 * so the discipline is citation: every claim points at the place it came from, and a reviewer opens
 * that place. An unciteable claim is the failure mode this approach exists to prevent.
 */
#[GenerationStrategy(IssueType::Research)]
final class ResearchStrategy
{
    public const string RECIPE = <<<'RECIPE'
        RESEARCH — what the solver you write has to accomplish. There is no green suite at the end here,
        so what replaces it is where every statement came from.

        1. TURN THE TICKET INTO QUESTIONS THAT HAVE ANSWERS.
           Before reading anything, write down the specific questions the ticket is really asking, as a
           list. "How does authentication work" is a subject, not a question; "which component decides a
           session has expired, and where is the timeout configured" is a question with a right answer.

           This list is the deliverable's contents page and the only defence against reading widely and
           reporting vaguely. If the ticket cannot be turned into answerable questions — it is asking
           for an opinion, or for a decision somebody has to make — say so with the question protocol
           rather than producing prose that looks like research.

        2. ANSWER FROM THE SOURCE, AND CITE AS YOU GO.
           Read the code, run things that reveal behaviour, read the project's own documents. For each
           question, write the answer WITH the place it came from: `src/Run/Triage.php:163`, the output
           of a command, the document and heading.

           Three rules, and they are the ones that make this worth anything:
           - Every claim carries its source. A sentence with no citation is a guess in the shape of a
             finding, and it will be believed.
           - Say what you could NOT establish. A question you failed to answer is a result — it tells
             the reader where the uncertainty is. Quietly dropping it makes the report look complete
             and be wrong.
           - Never infer from what a sensible system WOULD do. That is the single most common way this
             kind of work goes wrong: the code is read quickly, the gaps are filled from experience,
             and the report describes a plausible system that is not this one.

           Where behaviour is the question, RUN it rather than reasoning about it, and record what it
           printed. A command's output is a citation that cannot be misremembered.

        3. DELIVER IT AS A DOCUMENT, NOT A CHAT MESSAGE.
           Write the answer into a file — the project's knowledge base if it has one, otherwise where
           the ticket says. Structure it as the questions from step 1 with their answers.

           Change nothing else. This kind of work commits no code, and a research ticket that also
           "fixed a small thing on the way" has done something nobody reviewed.

        WHAT TO REVIEW, AND WHERE. Not whether the tests pass — there are none to pass, and a reviewer
        told to look for green will reject correct work. The review is: does every claim carry a source,
        does each source actually SAY what the claim says (the reviewer opens two or three and checks),
        are the questions from step 1 all addressed or explicitly marked unanswered, and did anything
        outside the report change?
        RECIPE;
}
