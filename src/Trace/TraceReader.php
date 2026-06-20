<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * Reads a run's trace back from the project db ({@see TraceStore} wrote it) and renders the
 * recorded hierarchy — the same indented tree the live view showed, reconstructed from the rows.
 * The read side of the trace store; keeps the schema in one place by reusing {@see TraceStore}.
 */
final class TraceReader
{
    public function __construct(private readonly \PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        TraceStore::ensureTable($pdo);
    }

    /** The most recently traced run's id, or null when nothing has been traced yet. */
    public function latestRunId(): ?string
    {
        $stmt = $this->pdo->query('SELECT run_id FROM trace ORDER BY seq DESC LIMIT 1');
        $id = $stmt === false ? false : $stmt->fetchColumn();

        return \is_scalar($id) ? (string) $id : null;
    }

    /**
     * Recent runs (newest first), from the ledger — for the header and for picking one.
     *
     * @return list<array{id: string, issue: string, workflow: string, status: string}>
     */
    public function runs(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT id, issue_id, workflow, status FROM runs ORDER BY id DESC LIMIT :n');
        $stmt->bindValue('n', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $runs = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $runs[] = [
                'id' => $this->str($row, 'id'),
                'issue' => $this->str($row, 'issue_id'),
                'workflow' => $this->str($row, 'workflow'),
                'status' => $this->str($row, 'status'),
            ];
        }

        return $runs;
    }

    /** The run's trace as an indented tree: ▶ opens a span, ◀ closes it, · is a point event. */
    public function render(string $runId): string
    {
        $stmt = $this->pdo->prepare('SELECT depth, phase, type, data FROM trace WHERE run_id = :r ORDER BY seq');
        $stmt->execute(['r' => $runId]);

        $lines = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $depth = (int) ($row['depth'] ?? 0);
            $type = $this->str($row, 'type');
            $decoded = json_decode($this->str($row, 'data'), true);
            $data = \is_array($decoded) ? $decoded : [];

            $glyph = match ($this->str($row, 'phase')) {
                'enter' => '▶',
                'exit' => '◀',
                default => '·',
            };

            $lines[] = str_repeat('  ', $depth) . $glyph . ' ' . trim($type . ' ' . $this->brief($type, $data));
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $data */
    private function brief(string $type, array $data): string
    {
        return match ($type) {
            'workflow', 'step', 'tool', 'tool-result' => $this->str($data, 'name'),
            'ai' => trim($this->str($data, 'role') . ' (' . $this->str($data, 'model') . ')'),
            'turn' => '#' . $this->str($data, 'number'),
            'prompt' => $this->clip($this->str($data, 'text')),
            'reply' => $this->tokens($data) . $this->clip($this->str($data, 'text')),
            'note' => trim($this->str($data, 'action') . ' ' . $this->str($data, 'message')),
            default => '',
        };
    }

    /** @param array<string, mixed> $data */
    private function tokens(array $data): string
    {
        $usage = $data['usage'] ?? null;
        if (!\is_array($usage)) {
            return '';
        }

        return '[' . $this->str($usage, 'in') . '/' . $this->str($usage, 'out') . ' tok] ';
    }

    /** @param array<array-key, mixed> $row */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    private function clip(string $text, int $width = 80): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > $width ? mb_substr($text, 0, $width) . '…' : $text;
    }
}
