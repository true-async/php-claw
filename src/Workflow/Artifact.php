<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * A named output a step produces — the concrete thing the step made, recorded so it is visible in the
 * run's journal and handed to the step's critic for review. Beyond review, artifacts are the inspectable
 * trail of a run — what each step actually yielded, not just that it ran.
 *
 * There are three kinds, and the distinction that matters is WHO WROTE THE CONTENT:
 *
 *  - `text` — the step's own words. A summary, a decision, generated source. Useful, but it is a CLAIM:
 *    the step composes it freely and can assert anything, including success it never had.
 *  - `file` — a path the step wrote; the critic, an ordinary AI with tools, opens it itself.
 *  - `evidence` — the CAPTURED OUTPUT of a tool the step ran, recorded verbatim. This is the kind that
 *    cannot be composed: the step hands over what the command printed, not a sentence about it.
 *
 * `evidence` exists because a real run closed an issue on a lie. The step recorded the text artifact
 * "All tests passed." while phpunit was erroring, the critic had nothing else to look at, and the issue
 * went to Done with 8 of 10 tests red. Telling the reviewer to distrust prose only goes so far — the
 * fix is to give it the raw material, so there is nothing left to fabricate. A step may still add its
 * own summary alongside; it is carried separately and presented as the step's words, never merged in.
 *
 * `kind` is only the STORAGE shape. The CONTENT type is carried separately as an extension + MIME, so a
 * viewer can render it properly (PHP highlighting, a diff view, an image) instead of treating every
 * artifact as plain text. Both are derived automatically — from the file path for a file, or from the
 * content for inline text — with an optional explicit override.
 */
final class Artifact
{
    /** Extension → MIME. The single source of the content-type mapping for artifacts. */
    private const array MIME = [
        'php' => 'text/x-php',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'diff' => 'text/x-diff',
        'patch' => 'text/x-diff',
        'json' => 'application/json',
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'text/javascript',
        'ts' => 'text/typescript',
        'xml' => 'application/xml',
        'yaml' => 'application/yaml',
        'yml' => 'application/yaml',
        'sql' => 'application/sql',
        'sh' => 'application/x-sh',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];

    private function __construct(
        public readonly string $label,
        public readonly string $kind,   // 'text' | 'file' | 'evidence' — storage shape
        public readonly string $value,  // the text itself, the file path, or the captured output
        public readonly string $ext,    // content extension, e.g. 'php' (no dot)
        public readonly string $mime,   // content MIME, e.g. 'text/x-php'
        public readonly string $source = '',   // evidence only: what produced it, e.g. 'bash'
        public readonly string $note = '',     // evidence only: the step's own summary, kept apart
    ) {
    }

    /** Inline text. The extension is taken from $ext if given, otherwise sniffed from the content. */
    public static function text(string $label, string $text, string $ext = ''): self
    {
        $ext = $ext !== '' ? self::normalizeExt($ext) : self::sniff($text);

        return new self($label, 'text', $text, $ext, self::mimeFor($ext));
    }

    /** A file the step wrote (its path). The extension/MIME come from the path. */
    public static function file(string $label, string $path): self
    {
        $ext = self::normalizeExt(pathinfo($path, PATHINFO_EXTENSION));

        return new self($label, 'file', $path, $ext, self::mimeFor($ext));
    }

    /**
     * The verbatim output of a tool the step ran. $source names what produced it (the tool), and $note
     * is the step's OWN summary of what it means — optional, and deliberately stored apart from the
     * output rather than blended into it, so a reviewer can always tell the machine's words from the
     * step's.
     */
    public static function evidence(string $label, string $output, string $source = '', string $note = ''): self
    {
        return new self($label, 'evidence', $output, 'txt', 'text/plain', $source, $note);
    }

    /**
     * How the artifact is presented to the critic. Evidence is labelled as captured output and names
     * its source, because the whole point is that the reviewer knows it was not composed; the step's
     * own note follows it, marked as the step's claim about that output.
     */
    public function render(): string
    {
        if ($this->kind === 'file') {
            return "- {$this->label} (file, read it to inspect): {$this->value}";
        }

        if ($this->kind === 'evidence') {
            $from = $this->source === '' ? '' : " of `{$this->source}`";
            $rendered = "- {$this->label} (CAPTURED OUTPUT{$from} — recorded verbatim, not written by the step):\n{$this->value}";

            return $this->note === ''
                ? $rendered
                : "{$rendered}\n  (the step's own summary of it, which is a claim: {$this->note})";
        }

        return "- {$this->label} (text): {$this->value}";
    }

    private static function mimeFor(string $ext): string
    {
        return self::MIME[$ext] ?? 'text/plain';
    }

    private static function normalizeExt(string $ext): string
    {
        $ext = strtolower(ltrim($ext, '.'));

        return $ext !== '' ? $ext : 'txt';
    }

    /** Best-effort content type for inline text when the caller did not declare one. */
    private static function sniff(string $text): string
    {
        $head = ltrim($text);

        if (str_starts_with($head, '<?php')) {
            return 'php';
        }

        if (preg_match('/^(@@ |diff --git |\+\+\+ |--- )/m', $text) === 1) {
            return 'diff';
        }

        if (($head !== '' && ($head[0] === '{' || $head[0] === '[')) && json_decode($head) !== null) {
            return 'json';
        }

        return 'txt';
    }
}
