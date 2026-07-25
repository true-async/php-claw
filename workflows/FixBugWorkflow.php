<?php

declare(strict_types=1);

namespace ClawWorkflow\Library;

use Claw\Project\IssueType;
use Claw\Workflow\AiStep;
use Claw\Workflow\LibraryWorkflow;
use Claw\Workflow\StepAI;
use Claw\Workflow\WorkflowAbstract;

/**
 * Fixes a defect that can be demonstrated: something behaves one way and should behave another.
 *
 * Works by making the bug visible first — a test that fails for the reason the ticket describes —
 * and only then changing the code, so what the fix did is settled by that test going from red to
 * green rather than by anyone's account of it. Choose it when the ticket reports broken behaviour
 * in code that already exists. Not for new behaviour nobody has asked for yet, and not for a defect
 * that cannot be reached from a test at all, which needs a person to decide how to prove it.
 */
#[LibraryWorkflow(IssueType::Bug)]
final class FixBugWorkflow extends WorkflowAbstract
{
    public function name(): string
    {
        return 'fix-bug';
    }

    /**
     * Make the bug visible as a FAILING TEST, and record the failure verbatim.
     *
     * This is the step that earns the rest. A fix written against a description fixes the
     * description; a fix written against a red test fixes what the test caught. The failure output
     * is stored as evidence rather than summarised, because a summary of a test run is exactly the
     * kind of claim the reviewer has no way to check.
     *
     * The prohibitions in the prompt are not decoration. On its first live run this step "reproduced"
     * the bug by editing the existing test to expect the WRONG answer — the defect's own output, with
     * the comment "Test for miscalculation" — which turns the suite green and ships the bug. The
     * critic caught it and the run stopped, but the instruction that invited it was ours: it said
     * "write a test" and nothing about leaving the ones already there alone.
     */
    #[StepAI(critic: 'reproduced')]
    protected function reproduce(): AiStep
    {
        return new AiStep(
            $this->rework()
            . $this->ticket()
            . "\n\nMake this defect visible as a FAILING TEST, in the project's own test suite and its own "
            . "style — find how the existing tests are written and run before writing anything.\n\n"
            . "Work in this order:\n"
            . "1. Find the code the ticket is about and read it.\n"
            . '2. RUN THE EXISTING TESTS FIRST. If one already fails for the reason the ticket describes, '
            . "that IS the reproduction — you are done: record its output and write no test at all.\n"
            . "3. Only if nothing covers it, ADD a test asserting the behaviour the ticket says is CORRECT.\n"
            . '4. Run it and watch it FAIL — and check it fails for the reason in the ticket, not because '
            . "the test is wrong. A test that errors on a typo or a missing import has proved nothing.\n\n"
            . "TWO THINGS YOU MUST NOT DO, because both destroy the thing you were sent to find:\n"
            . '- Do not change what an existing test EXPECTS. Editing an assertion to match the buggy '
            . 'output does not reproduce the defect, it erases it — the suite goes green and the bug '
            . 'ships. If a test expects the right answer and fails, that is exactly what you were '
            . "looking for.\n"
            . "- Do not touch production code here. The fix is the next step's job, and a bug fixed "
            . "before it was demonstrated was never demonstrated.\n\n"
            . 'Record TWO things with the `artifact` tool, each by passing `command` — it runs the command '
            . "and keeps exactly what it printed, so do not paste output you already have:\n"
            . '- `baseline` — the whole suite as it stands. This is what tells the later steps which '
            . "failures were already here and are not yours.\n"
            . '- `reproduction` — the failing test itself, with a short `note` saying what the failure '
            . "shows.\n\n"
            . 'If the defect genuinely cannot be reached by a test, say so with the `[question]` marker '
            . 'instead of inventing one.',
        );
    }

    /**
     * Change the production code, and nothing else, until the red test goes green.
     *
     * Deliberately given the smaller brief: the hard thinking was done above, and a step told to fix
     * a specific failing test is far less likely to wander into a redesign than one told to fix a bug.
     *
     * Its critic judges the SHAPE of the change, not whether it worked — `verify` settles that, and a
     * second opinion on the same question would only cost a reviewer pass. Nothing used to look at the
     * blast radius at all: a fix that turned the suite green while rewriting code the ticket never
     * mentioned passed every gate this workflow has, because every gate was reading the test results.
     */
    #[StepAI(critic: 'contained')]
    protected function fix(): AiStep
    {
        return new AiStep(
            $this->rework()
            . $this->ticket()
            . "\n\nThe failing test above is the definition of this bug. Change the PRODUCTION code so "
            . "it passes.\n\n"
            . 'Make the smallest change that fixes the cause — not the symptom, and not the test. Do not '
            . 'edit the test to agree with the current behaviour: that erases the bug instead of fixing '
            . 'it. Do not refactor code the ticket did not ask about. Run the test as you go.'
            . "\n\nWhen you stop, record what you changed with the `artifact` tool, passing `command` set "
            . 'to `git diff --stat` — that is what the reviewer of this step reads. If you could not make '
            . 'the test pass, say so plainly rather than leaving a half-applied change: a reported '
            . 'failure is worked with, a silent one is discovered later by someone else.',
        );
    }

    /**
     * Prove it: the new test passes AND nothing else broke.
     *
     * The second half matters more than the first. A fix that makes its own test green while turning
     * the suite red has traded one defect for others, and a step that only reran its own test would
     * report that as success.
     */
    #[StepAI(critic: 'proven')]
    protected function verify(): AiStep
    {
        return new AiStep(
            $this->rework()
            . "Run the project's WHOLE test suite — not only the test written for this bug — and read the "
            . "output.\n\n"
            . 'A failure the change explains is part of this work: fix it and run again. A failure that '
            . 'was ALREADY THERE before any of this is not yours to chase — the reproduce step recorded '
            . "the suite's starting state as the `baseline` artifact, so call "
            . "recall(what='artifacts', name='reproduce') and compare against it rather than guessing.\n\n"
            . 'Then record the result with the `artifact` tool, passing `command` set to the suite command '
            . '— it runs it and keeps the real output. In `note`, name any failure you are setting aside '
            . 'as pre-existing, and say which line of the baseline says so. Do not declare it green '
            . 'without having seen it.',
        );
    }

    protected function criticRules(): array
    {
        return [
            'reproduced' => 'Two artifacts are required: `baseline`, the suite as it stood before the work, '
                . 'and `reproduction`, a run showing a test that FAILS. Both must be real captured output; '
                . 'reject if either is missing. On the reproduction: '
                . 'Run the command yourself and read what it prints. Reject if: there is no evidence, only '
                . 'prose; the output shows a passing run or no run at all; the failure is a syntax error, a '
                . 'missing file or an import mistake rather than the behaviour the ticket describes; or the '
                . 'production code was changed in this step, which would mean the bug was never demonstrated. '
                . 'ALSO check what the step did to the tests that were already there — `git diff` on the test '
                . 'files says it plainly. Reject if an existing expectation was changed, relaxed or deleted: '
                . 'rewriting an assertion to match the buggy output turns the suite green and lets the bug '
                . 'ship, which is worse than not reproducing it at all. Adding a new test is the only edit '
                . 'this step may make, and it need not make even that one — a defect the existing suite '
                . 'already catches is reproduced by running it.',

            'contained' => 'Judge the SHAPE of the change, not whether the tests pass — the next step '
                . 'settles that, and re-deciding it here buys nothing. Run `git diff` yourself and read '
                . 'what this step actually did. Note the diff is CUMULATIVE — nothing '
                . 'commits between steps, so it also contains the failing test the previous step added. '
                . "Call recall(what='artifacts', name='reproduce') to see what that step recorded, and "
                . 'judge only what is left. Reject if: a test was changed BEYOND the one reproduce added '
                . '(this step may not touch tests at all); a file the ticket does not concern was changed for reasons the fix does not require; the change is a broad '
                . 'rewrite or refactor where a targeted fix would do; or the defect was papered over at '
                . 'the call site rather than fixed at its cause. A change that is small, confined to the '
                . 'code the ticket names, and aimed at the cause PASSES — even if the tests are still red, '
                . 'which is not this step\'s verdict to give.',

            'proven' => 'The evidence must be the real output of running the WHOLE suite. Run it yourself. '
                . 'Reject if: the output covers only the new test; the test written for the bug was weakened '
                . 'or deleted rather than satisfied; or the claim of a green suite has no output behind it. '
                . 'A failure that was ALREADY THERE is not this step\'s fault — but that is now checkable '
                . "rather than a matter of faith: call recall(what='artifacts', name='reproduce') and read "
                . 'the `baseline` artifact, which is the suite\'s verbatim output before any of this work. '
                . 'Accept a failure the baseline also shows; reject one it does not, and reject a step that '
                . 'sets a failure aside without pointing at the baseline line that excuses it.',
        ];
    }

    /**
     * The reviewer's findings, when this is a re-run after a rejection — empty on a first attempt.
     *
     * Without it a rejected step replays its ORIGINAL prompt onto the continued conversation and is never
     * told what was wrong, so it plausibly answers "already done", records the same artifact, and is
     * rejected again until the round cap kills the run. The rework loop a critic pays for is worth
     * nothing unless the worker hears the verdict.
     */
    private function rework(): string
    {
        $critique = $this->critique();

        return $critique === null
            ? ''
            : "A REVIEWER REJECTED YOUR PREVIOUS ATTEMPT AT THIS STEP:\n{$critique}\n\n"
                . "Fix exactly what it names. Do not start over, and do not repeat what it rejected.\n\n";
    }

    /** The ticket as the steps see it — this workflow takes no parameters, the issue IS the input. */
    private function ticket(): string
    {
        $issue = $this->issue();

        if ($issue === null) {
            return 'No ticket was attached to this run.';
        }

        return "The ticket:\nTitle: {$issue->title}\n\nDescription:\n{$issue->description}";
    }
}
