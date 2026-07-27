<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;

/**
 * Change a file by REPLACING a piece of it — `old_string` → `new_string` — instead of re-emitting the
 * whole file with {@see WriteFileTool}. This is the default write of every mature coding agent, and for
 * the reasons they give: whole-file rewrite makes the model return the entire file to change three lines
 * (slow, costly, and it invites the "// …rest unchanged" truncation bug), while a targeted replace is
 * cheap and precise. {@see WriteFileTool} stays for CREATING a file or a full rewrite.
 *
 * ONE call can carry MANY edits — the `edits` array — across one or more files, applied ALL-OR-NOTHING:
 * every edit is checked against the current text first, and if any fails to match, NOTHING is written.
 * So a refactor that touches five places lands as one atomic change or not at all.
 *
 * The match must be UNAMBIGUOUS: `old_string` has to occur exactly once in the (current) file, or the
 * edit is refused with the count — add surrounding lines to make it unique, or pass `replace_all` to
 * change every occurrence on purpose. Edits to the same file compound in order, so a later edit sees the
 * text the earlier ones produced.
 */
final readonly class EditTool implements ToolInterface
{
    public function __construct(private Workspace $workspace)
    {
    }

    public function name(): string
    {
        return 'edit';
    }

    public function description(): string
    {
        return 'Edit an existing file by replacing an exact substring: "old_string" -> "new_string". '
            . '"old_string" must occur EXACTLY ONCE in the file (add surrounding context to make it '
            . 'unique) unless "replace_all" is set. To change many places at once — even across different '
            . 'files — pass an "edits" array of {path, old_string, new_string, replace_all?}; they are '
            . 'applied all-or-nothing, so if any one fails to match, none are written. Use write_file to '
            . 'CREATE a file or replace it whole.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative file to edit (single edit)'],
                'old_string' => ['type' => 'string', 'description' => 'Exact text to replace (must be unique unless replace_all)'],
                'new_string' => ['type' => 'string', 'description' => 'Replacement text (empty deletes old_string)'],
                'replace_all' => ['type' => 'boolean', 'description' => 'Replace every occurrence instead of requiring exactly one'],
                'edits' => [
                    'type' => 'array',
                    'description' => 'Several edits at once, across one or more files — applied all-or-nothing.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'old_string' => ['type' => 'string'],
                            'new_string' => ['type' => 'string'],
                            'replace_all' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function effects(): array
    {
        return [Effect::Write];
    }

    public function risk(): Risk
    {
        return Risk::Mutating;
    }

    public function handle(array $input): string
    {
        $edits = $this->collectEdits($input);

        if ($edits === []) {
            throw new ToolException('edit: give an edit (path + old_string + new_string), or an "edits" array of them');
        }

        // Phase 1: apply every edit to IN-MEMORY copies, keyed by the file's real path so two spellings of
        // the same file share one buffer. Any failure throws here — before a single byte is written — so
        // the whole call is all-or-nothing.
        $files = [];   // realPath => array{path: string, content: string, edits: int}

        foreach ($edits as $n => $edit) {
            $real = $this->workspace->resolveExisting($edit['path']);   // throws: missing / outside / secret

            if (!isset($files[$real])) {
                $files[$real] = ['path' => $edit['path'], 'content' => (string) file_get_contents($real), 'edits' => 0];
            }

            $files[$real]['content'] = $this->applyOne($edit, $files[$real]['content'], $n + 1);
            ++$files[$real]['edits'];
        }

        // Phase 2: everything matched — commit.
        foreach ($files as $real => $file) {
            if (file_put_contents($real, $file['content']) === false) {
                throw new ToolException("edit: could not write {$file['path']}");
            }
        }

        return $this->summary($files, \count($edits));
    }

    /**
     * @param array{path: string, old: string, new: string, all: bool} $edit
     *
     * @throws ToolException when the edit does not match cleanly — the message names edit #$n so a batch
     *                       call can be corrected
     */
    private function applyOne(array $edit, string $content, int $n): string
    {
        [$old, $new] = [$edit['old'], $edit['new']];

        if ($old === $new) {
            throw new ToolException("edit #{$n} ({$edit['path']}): old_string and new_string are identical — nothing to change");
        }

        $count = substr_count($content, $old);

        if ($count === 0) {
            throw new ToolException(
                "edit #{$n} ({$edit['path']}): old_string not found. It must match the file EXACTLY, including "
                . 'whitespace and indentation — read the file and copy the target text verbatim.',
            );
        }

        if ($edit['all']) {
            return str_replace($old, $new, $content);
        }

        if ($count > 1) {
            throw new ToolException(
                "edit #{$n} ({$edit['path']}): old_string is ambiguous — it occurs {$count} times. Add "
                . 'surrounding lines so it identifies ONE place, or pass replace_all to change them all.',
            );
        }

        $pos = strpos($content, $old);   // count === 1, so this is the only occurrence

        return substr_replace($content, $new, (int) $pos, \strlen($old));
    }

    /**
     * Normalize the input into a flat list of edits — whether it came as top-level fields (one edit) or an
     * `edits` array (many). Empty is returned as [] so the caller raises the single clear "give me an edit".
     *
     * @param array<string, mixed> $input
     *
     * @return list<array{path: string, old: string, new: string, all: bool}>
     */
    private function collectEdits(array $input): array
    {
        $batch = $input['edits'] ?? null;

        if (\is_array($batch) && $batch !== []) {
            $edits = [];

            foreach (array_values($batch) as $item) {
                if (!\is_array($item)) {
                    throw new ToolException('edit: each entry in "edits" must be an object with path/old_string/new_string');
                }

                $edits[] = $this->normalize($item);
            }

            return $edits;
        }

        if (trim((string) ($input['path'] ?? '')) === '' && (string) ($input['old_string'] ?? '') === '') {
            return [];
        }

        return [$this->normalize($input)];
    }

    /**
     * @param array<string, mixed> $edit
     *
     * @return array{path: string, old: string, new: string, all: bool}
     */
    private function normalize(array $edit): array
    {
        $path = trim((string) ($edit['path'] ?? ''));
        $old = (string) ($edit['old_string'] ?? '');

        if ($path === '') {
            throw new ToolException('edit: "path" is required for each edit');
        }

        if ($old === '') {
            throw new ToolException("edit ({$path}): \"old_string\" is required — to create a file use write_file");
        }

        return [
            'path' => $path,
            'old' => $old,
            'new' => (string) ($edit['new_string'] ?? ''),
            'all' => (bool) ($edit['replace_all'] ?? false),
        ];
    }

    /**
     * @param array<string, array{path: string, content: string, edits: int}> $files
     */
    private function summary(array $files, int $total): string
    {
        if (\count($files) === 1) {
            $file = reset($files);

            return "edited {$file['path']} ({$file['edits']} " . ($file['edits'] === 1 ? 'change' : 'changes') . ')';
        }

        $parts = [];

        foreach ($files as $file) {
            $parts[] = "{$file['path']} ({$file['edits']})";
        }

        return "applied {$total} edits across " . \count($files) . ' files: ' . implode(', ', $parts);
    }
}
