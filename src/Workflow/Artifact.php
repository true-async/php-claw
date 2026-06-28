<?php

declare(strict_types=1);

namespace Claw\Workflow;

/**
 * A named output a step produces — the concrete thing the step made, recorded so it is visible in the
 * run's journal and handed to the step's critic for review. An artifact is either a piece of TEXT (a
 * summary, a decision, a diff) or a reference to a FILE the step wrote: the critic, an ordinary AI with
 * tools, reads the file itself when it needs the contents. Beyond review, artifacts are the inspectable
 * trail of a run — what each step actually yielded, not just that it ran.
 *
 * `kind` is only the STORAGE shape (inline text vs a file path). The CONTENT type is carried separately
 * as an extension + MIME, so a viewer can render it properly (PHP highlighting, a diff view, an image)
 * instead of treating every artifact as plain text. Both are derived automatically — from the file path
 * for a file, or from the content for inline text — with an optional explicit override.
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
        public readonly string $kind,   // 'text' | 'file' — storage shape
        public readonly string $value,  // the text itself, or the file path
        public readonly string $ext,    // content extension, e.g. 'php' (no dot)
        public readonly string $mime,   // content MIME, e.g. 'text/x-php'
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

    /** How the artifact is presented to the critic — a file as a path it can open, text inline. */
    public function render(): string
    {
        return $this->kind === 'file'
            ? "- {$this->label} (file, read it to inspect): {$this->value}"
            : "- {$this->label} (text): {$this->value}";
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
