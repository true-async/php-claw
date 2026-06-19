<?php

declare(strict_types=1);

namespace Claw\Workflow\Example;

use Claw\Workflow\Step;
use Claw\Workflow\WorkflowAbstract;

/**
 * An example workflow, showing the shape the base supports.
 *
 * State is plain fields. Steps are {@see Step} methods whose bodies are written by hand — read a
 * file with {@see tool()}, reach the model with {@see ai()}, write a field. The base drives the
 * steps in order, snapshots the fields after each, and skips steps already done on resume, so the
 * body stays this small. A critic is not special machinery: it is a sub-step — another ai() call
 * the author makes inside a step and acts on in plain PHP.
 */
final class ReviewFileWorkflow extends WorkflowAbstract
{
    private string $source   = '';
    private string $issues   = '';
    private string $proposal = '';

    public function name(): string
    {
        return 'review-file';
    }

    #[Step]
    public function read(): void
    {
        $this->source = $this->tool('read_file', ['path' => (string) $this->param('path')]);
    }

    #[Step]
    public function findIssues(): void
    {
        $this->issues = $this->ai("List the problems in this file:\n\n" . $this->source);
    }

    #[Step]
    public function propose(): void
    {
        $this->proposal = $this->ai("Propose concrete fixes for:\n\n" . $this->issues);

        // A critic as a sub-step: judge the proposal with another ai() call and, if it is weak,
        // redo it once. Plain PHP — the author decides when to judge; nothing is baked into step().
        $verdict = $this->ai("Reply only 'ok' or 'weak' — is this proposal solid?\n\n" . $this->proposal);
        if (str_contains(strtolower($verdict), 'weak')) {
            $this->proposal = $this->ai("Make this proposal stronger and more concrete:\n\n" . $this->proposal);
        }
    }
}
