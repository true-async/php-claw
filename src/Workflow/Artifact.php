<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * A named output a step produces — the concrete thing the step made, recorded so it is visible in the
 * run's journal and handed to the step's critic for review. An artifact is either a piece of TEXT (a
 * summary, a decision, a diff) or a reference to a FILE the step wrote: the critic, an ordinary AI with
 * tools, reads the file itself when it needs the contents. Beyond review, artifacts are the inspectable
 * trail of a run — what each step actually yielded, not just that it ran.
 */
final class Artifact
{
    private function __construct(
        public readonly string $label,
        public readonly string $kind,   // 'text' | 'file'
        public readonly string $value,  // the text itself, or the file path
    ) {
    }

    public static function text(string $label, string $text): self
    {
        return new self($label, 'text', $text);
    }

    public static function file(string $label, string $path): self
    {
        return new self($label, 'file', $path);
    }

    /** How the artifact is presented to the critic — a file as a path it can open, text inline. */
    public function render(): string
    {
        return $this->kind === 'file'
            ? "- {$this->label} (file, read it to inspect): {$this->value}"
            : "- {$this->label} (text): {$this->value}";
    }
}
