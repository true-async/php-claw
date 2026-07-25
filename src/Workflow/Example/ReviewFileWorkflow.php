<?php

declare(strict_types=1);

namespace Claw\Workflow\Example;

use Claw\Workflow\AiStep;
use Claw\Workflow\ParamRequest;
use Claw\Workflow\Step;
use Claw\Workflow\StepAI;
use Claw\Workflow\WorkflowAbstract;

/**
 * An example workflow, showing the shape the base supports.
 *
 * Two kinds of step. A {@see Step} is CODE — deterministic glue, re-run whole on resume (here: read a
 * file with {@see tool()} into a snapshotted field). A {@see StepAI} is PURE: it DECLARES one model
 * exchange as an {@see AiStep} and does no work itself — the base runs, records and resumes that exchange.
 * What one step hands a later one rides a channel — a handoff to the next step, or a param addressed to a
 * named one — never a return value or a shared field of prose. A critic is not special machinery: mark the
 * step `#[StepAI(critic: '<name>')]` and the base judges its recorded artifact against the rules in
 * {@see criticRules()}, re-running the step on the supervisor's guidance while the critic is unhappy.
 */
final class ReviewFileWorkflow extends WorkflowAbstract
{
    private string $source = '';

    public function name(): string
    {
        return 'review-file';
    }

    #[Step]
    protected function read(): void
    {
        $this->source = $this->tool('read_file', ['path' => (string) $this->param('path')]);
    }

    #[StepAI]
    protected function findIssues(): AiStep
    {
        return new AiStep(
            "List the problems in this file:\n\n" . $this->source,
            params: [new ParamRequest(
                forStep: 'propose',
                name: 'issues',
                instruction: 'List the problems you found, one per line.',
            )],
        );
    }

    #[StepAI(critic: 'solid')]
    protected function propose(): AiStep
    {
        return new AiStep(
            'Propose concrete fixes for these problems, then record your proposal with the artifact tool '
            . "(label 'proposal'):\n\n" . (string) $this->param('issues'),
            ['artifact'],
        );
    }

    /** @return array<string, string> */
    protected function criticRules(): array
    {
        return ['solid' => 'the proposal must be concrete and address each listed problem; reject a vague '
            . 'proposal or one that skips a problem, so the step re-runs and strengthens it.'];
    }
}
