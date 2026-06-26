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

    /**
     * The run's trace as an indented tree: ▶ opens a span, ◀ closes it, · is a point event. Rows
     * below $threshold are dropped, so the same density knob as the live console applies to history;
     * the default shows everything that was recorded.
     */
    public function render(string $runId, Level $threshold = Level::Debug): string
    {
        $stmt = $this->pdo->prepare('SELECT depth, phase, type, data FROM trace WHERE run_id = :r AND level >= :lvl ORDER BY seq');
        $stmt->bindValue('r', $runId);
        $stmt->bindValue('lvl', $threshold->value, \PDO::PARAM_INT);
        $stmt->execute();

        return $this->renderRows($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * One step's recorded subtree — its ai turns, tool calls and artifacts — as the same indented
     * tree {@see render()} produces, scoped to the LAST run of that step (a critic re-run replays the
     * name). For an agent recalling what a sibling step actually did.
     */
    public function stepHistory(string $runId, string $name): string
    {
        $bounds = $this->stepBounds($runId, $name);
        if ($bounds === null) {
            return "No step '{$name}' has run in this workflow yet.";
        }

        $stmt = $this->pdo->prepare('SELECT depth, phase, type, data FROM trace WHERE run_id = :r AND seq >= :a AND seq <= :b ORDER BY seq');
        $stmt->execute(['r' => $runId, 'a' => $bounds[0], 'b' => $bounds[1]]);

        return $this->renderRows($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Every call to a named tool in the run (input and result), oldest first. */
    public function toolHistory(string $runId, string $name): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT depth, phase, type, data FROM trace
             WHERE run_id = :r AND type IN ('tool', 'tool-result') AND json_extract(data, '$.name') = :n ORDER BY seq",
        );
        $stmt->execute(['r' => $runId, 'n' => $name]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === [] ? "No tool '{$name}' has been called in this workflow yet." : $this->renderRows($rows);
    }

    /** The run's artifacts, optionally only those a named step produced. */
    public function artifacts(string $runId, string $step = ''): string
    {
        if ($step !== '') {
            $bounds = $this->stepBounds($runId, $step);
            if ($bounds === null) {
                return "No step '{$step}' has run in this workflow yet.";
            }

            $stmt = $this->pdo->prepare("SELECT data FROM trace WHERE run_id = :r AND type = 'artifact' AND seq >= :a AND seq <= :b ORDER BY seq");
            $stmt->execute(['r' => $runId, 'a' => $bounds[0], 'b' => $bounds[1]]);
        } else {
            $stmt = $this->pdo->prepare("SELECT data FROM trace WHERE run_id = :r AND type = 'artifact' ORDER BY seq");
            $stmt->execute(['r' => $runId]);
        }

        $lines = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $data = json_decode($this->str($row, 'data'), true);
            $data = \is_array($data) ? $data : [];
            $lines[] = '- ' . $this->str($data, 'label') . ' (' . $this->str($data, 'kind') . '): ' . $this->str($data, 'value');
        }

        return $lines === [] ? 'No artifacts have been recorded in this workflow yet.' : implode("\n", $lines);
    }

    /** The most recent handoff — the baton the previous step left for this one (summary + findings). */
    public function handoff(string $runId): string
    {
        $stmt = $this->pdo->prepare("SELECT data FROM trace WHERE run_id = :r AND type = 'handoff' ORDER BY seq DESC LIMIT 1");
        $stmt->execute(['r' => $runId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return 'No previous step has handed off anything yet.';
        }

        $data = json_decode($this->str($row, 'data'), true);
        $data = \is_array($data) ? $data : [];

        return "Summary: {$this->str($data, 'summary')}\nFindings: {$this->str($data, 'findings')}";
    }

    /** The workflow's name and the steps it has run so far (in order) — a quick map of the run. */
    public function describe(string $runId): string
    {
        $wf = $this->pdo->prepare("SELECT data FROM trace WHERE run_id = :r AND type = 'workflow' AND phase = 'enter' ORDER BY seq LIMIT 1");
        $wf->execute(['r' => $runId]);
        $row = $wf->fetch(\PDO::FETCH_ASSOC);
        $data = $row === false ? [] : (json_decode($this->str($row, 'data'), true) ?: []);
        $name = \is_array($data) ? $this->str($data, 'name') : '';

        $steps = $this->pdo->prepare("SELECT data FROM trace WHERE run_id = :r AND type = 'step' AND phase = 'enter' ORDER BY seq");
        $steps->execute(['r' => $runId]);
        $names = [];
        foreach ($steps->fetchAll(\PDO::FETCH_ASSOC) as $stepRow) {
            $decoded = json_decode($this->str($stepRow, 'data'), true);
            $stepName = \is_array($decoded) ? $this->str($decoded, 'name') : '';
            if ($stepName !== '' && !\in_array($stepName, $names, true)) {
                $names[] = $stepName;
            }
        }

        return "Workflow: {$name}\nSteps run so far: " . ($names === [] ? '(none yet)' : implode(', ', $names));
    }

    /**
     * The [firstSeq, lastSeq] span of a step's last run, or null if it has not run. Tracer span ids are
     * unique within a run, so the matching close is found by span id.
     *
     * @return ?array{0: int, 1: int}
     */
    private function stepBounds(string $runId, string $name): ?array
    {
        $enter = $this->pdo->prepare(
            "SELECT seq, span_id FROM trace WHERE run_id = :r AND phase = 'enter' AND type = 'step'
             AND json_extract(data, '$.name') = :n ORDER BY seq DESC LIMIT 1",
        );
        $enter->execute(['r' => $runId, 'n' => $name]);
        $row = $enter->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $from = (int) ($row['seq'] ?? 0);
        $span = (int) ($row['span_id'] ?? 0);

        $exit = $this->pdo->prepare("SELECT seq FROM trace WHERE run_id = :r AND phase = 'exit' AND span_id = :s LIMIT 1");
        $exit->execute(['r' => $runId, 's' => $span]);
        $exitSeq = $exit->fetchColumn();

        return [$from, $exitSeq === false ? PHP_INT_MAX : (int) $exitSeq];
    }

    /**
     * Render trace rows as the indented tree: ▶ opens a span, ◀ closes it, · a point event.
     *
     * @param array<int, mixed> $rows
     */
    private function renderRows(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $depth = (int) ($row['depth'] ?? 0);
            $type = $this->str($row, 'type');
            $decoded = json_decode($this->str($row, 'data'), true);
            $data = \is_array($decoded) ? $decoded : [];

            $glyph = match ($this->str($row, 'phase')) {
                'enter' => '▶',
                'exit' => '◀',
                default => '·',
            };

            // Same one-line renderer as the live console, so history and live can never diverge.
            $lines[] = str_repeat('  ', $depth) . $glyph . ' ' . trim($type . ' ' . TraceFormat::summary($type, $data));
        }

        return implode("\n", $lines);
    }

    /** @param array<array-key, mixed> $row */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
