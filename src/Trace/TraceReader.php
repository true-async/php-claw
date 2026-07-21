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
     * The run's OPEN GATE: the newest `question` with no `answer` pointing back at it, or null when the
     * run is not waiting on anyone.
     *
     * The journal is the durable half of the gate — the channel a run parks on is only the live wakeup,
     * and it dies with its process. So this is how anyone finds out that a run was waiting when the
     * lights went out: the ledger says Running and the ticket says WaitingHuman, and until now nothing
     * could tell that apart from a wait somebody is actually serving.
     *
     * @return ?array{id: int, prompt: string}
     */
    public function openGate(string $runId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT span_id, type, data FROM trace WHERE run_id = :run AND type IN (:q, :a) ORDER BY seq',
        );
        $stmt->execute(['run' => $runId, 'q' => 'question', 'a' => 'answer']);

        $open = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $data = json_decode((string) ($row['data'] ?? ''), true);
            $data = \is_array($data) ? $data : [];

            if ((string) ($row['type'] ?? '') === 'question') {
                $open[(int) ($row['span_id'] ?? 0)] = (string) ($data['prompt'] ?? '');

                continue;
            }

            unset($open[(int) ($data['ref'] ?? 0)]);   // an answer closes exactly the question it names
        }

        if ($open === []) {
            return null;
        }

        $id = array_key_last($open);

        return ['id' => (int) $id, 'prompt' => $open[$id]];
    }

    /**
     * The run's trace as an indented tree: ▶ opens a span, ◀ closes it, · is a point event. Rows
     * below $threshold are dropped, so the same density knob as the live console applies to history;
     * the default shows everything that was recorded.
     */
    public function render(string $runId, Level $threshold = Level::Debug, bool $color = false): string
    {
        $stmt = $this->pdo->prepare('SELECT depth, phase, type, data FROM trace WHERE run_id = :r AND level >= :lvl ORDER BY seq');
        $stmt->bindValue('r', $runId);
        $stmt->bindValue('lvl', $threshold->value, \PDO::PARAM_INT);
        $stmt->execute();

        return $this->renderRows($stmt->fetchAll(\PDO::FETCH_ASSOC), $color);
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
            $data = json_decode(TraceFormat::str($row, 'data'), true);
            $data = \is_array($data) ? $data : [];
            $lines[] = '- ' . TraceFormat::str($data, 'label') . ' (' . TraceFormat::str($data, 'kind') . '): ' . TraceFormat::str($data, 'value');
        }

        return $lines === [] ? 'No artifacts have been recorded in this workflow yet.' : implode("\n", $lines);
    }

    /**
     * The run's trace rows past a seq cursor — the dashboard's replay and `?since=` poll. `seq` is a
     * global monotonic autoincrement, so `seq > since` is a clean tail.
     *
     * @return list<array<string, mixed>>
     */
    public function tail(string $runId, int $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT seq, span_id, parent_id, depth, phase, type, level, data, created_at
             FROM trace WHERE run_id = :r AND seq > :s ORDER BY seq',
        );
        $stmt->execute(['r' => $runId, 's' => $since]);

        $rows = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'seq' => (int) $row['seq'],
                'spanId' => (int) $row['span_id'],
                'parentId' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'depth' => (int) $row['depth'],
                'phase' => (string) $row['phase'],
                'type' => (string) $row['type'],
                'level' => (int) $row['level'],
                'tsMs' => (int) $row['created_at'],
                'data' => json_decode((string) $row['data'], true),
            ];
        }

        return $rows;
    }

    /**
     * Token use summed over the run's model replies. Four numbers: the raw provider counts (in/out, which
     * re-count the resent history every turn), the cached subset of input, and the NORMALIZED total — the
     * cost-weighted equivalent ({@see \Claw\Agent\TokenPricing}) that is the meaningful figure to show.
     *
     * @return array{in: int, out: int, cached: int, normalized: int, costMicros: int}
     */
    public function tokens(string $runId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(json_extract(data, '$.usage.in')), 0)     AS tin,
                    COALESCE(SUM(json_extract(data, '$.usage.out')), 0)    AS tout,
                    COALESCE(SUM(json_extract(data, '$.usage.cached')), 0) AS tcached,
                    COALESCE(SUM(json_extract(data, '$.usage.norm')), 0)   AS tnorm,
                    COALESCE(SUM(json_extract(data, '$.usage.cost')), 0)   AS tcost
             FROM trace WHERE run_id = ? AND type = 'reply'",
        );
        $stmt->execute([$runId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'in' => (int) ($row['tin'] ?? 0),
            'out' => (int) ($row['tout'] ?? 0),
            'cached' => (int) ($row['tcached'] ?? 0),
            'normalized' => (int) ($row['tnorm'] ?? 0),
            'costMicros' => (int) ($row['tcost'] ?? 0),
        ];
    }

    /**
     * The run's artifacts as structured records (name / kind / ext / mime / body) — for the dashboard,
     * distinct from {@see artifacts()} which renders them as a console string. `ext`/`mime` describe the
     * content type so the UI can render it properly; older rows without them fall back to plain text.
     *
     * @return list<array<string, mixed>>
     */
    public function artifactRecords(string $runId): array
    {
        // Walk the run in order, tracking the open `step` spans so each artifact is tagged with the
        // step that produced it (the innermost step still open when the artifact event fired).
        $stmt = $this->pdo->prepare('SELECT span_id, phase, type, data FROM trace WHERE run_id = ? ORDER BY seq');
        $stmt->execute([$runId]);

        $openSteps = [];   // span_id => step name; insertion order = nesting, last is innermost
        $records = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $phase = (string) $row['phase'];
            $type = (string) $row['type'];

            if ($phase === 'enter' && $type === 'step') {
                $data = json_decode((string) $row['data'], true);
                $openSteps[(int) $row['span_id']] = \is_array($data) ? (string) ($data['name'] ?? '') : '';

                continue;
            }

            if ($phase === 'exit') {
                unset($openSteps[(int) $row['span_id']]);

                continue;
            }

            if ($type !== 'artifact') {
                continue;
            }

            $artifact = json_decode((string) $row['data'], true);

            if (!\is_array($artifact)) {
                continue;
            }

            // METADATA ONLY — no body. These records ride in the issue list, which the board holds
            // for every issue of every watched project and re-reads on each refresh; a run that wrote
            // a generated solver class or a few files put all of that in the payload, and none of it
            // is needed until someone opens an artifact. The body is fetched per artifact instead
            // ({@see body()}), by the index this loop assigns.
            $value = (string) ($artifact['value'] ?? '');
            $records[] = [
                'n' => \count($records),   // stable within a run: the order artifacts were recorded
                'name' => (string) ($artifact['label'] ?? ''),
                'kind' => (string) ($artifact['kind'] ?? 'file'),
                'ext' => (string) ($artifact['ext'] ?? ''),
                'mime' => (string) ($artifact['mime'] ?? ''),
                'meta' => '',
                'step' => $openSteps === [] ? '' : (string) end($openSteps),
                // A file artifact's value is its PATH, which is short and is what the viewer shows in
                // the header, so it stays. A text/evidence body does not.
                'path' => ($artifact['kind'] ?? '') === 'file' ? $value : '',
                'size' => \strlen($value),
                'source' => (string) ($artifact['source'] ?? ''),
                'note' => (string) ($artifact['note'] ?? ''),
            ];
        }

        return $records;
    }

    /**
     * One artifact's body, by its index within the run. Split from {@see artifactRecords()} so the
     * board can list artifacts without carrying their contents: a viewer asks for a body when someone
     * opens it, and never for the ones they do not.
     *
     * Null when the run has no artifact at that index.
     */
    public function artifactBody(string $runId, int $index): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT data FROM trace WHERE run_id = ? AND type = 'artifact' ORDER BY seq",
        );
        $stmt->execute([$runId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!isset($rows[$index])) {
            return null;
        }

        $artifact = json_decode((string) $rows[$index], true);

        return \is_array($artifact) ? (string) ($artifact['value'] ?? '') : null;
    }

    /** The workflow's name and the steps it has run so far (in order) — a quick map of the run. */
    public function describe(string $runId): string
    {
        $wf = $this->pdo->prepare("SELECT data FROM trace WHERE run_id = :r AND type = 'workflow' AND phase = 'enter' ORDER BY seq LIMIT 1");
        $wf->execute(['r' => $runId]);
        $row = $wf->fetch(\PDO::FETCH_ASSOC);
        $decoded = $row === false ? [] : json_decode(TraceFormat::str($row, 'data'), true);
        $data = \is_array($decoded) ? $decoded : [];
        $name = TraceFormat::str($data, 'name');

        $steps = $this->pdo->prepare("SELECT data FROM trace WHERE run_id = :r AND type = 'step' AND phase = 'enter' ORDER BY seq");
        $steps->execute(['r' => $runId]);
        $names = [];

        foreach ($steps->fetchAll(\PDO::FETCH_ASSOC) as $stepRow) {
            $decoded = json_decode(TraceFormat::str($stepRow, 'data'), true);
            $stepName = \is_array($decoded) ? TraceFormat::str($decoded, 'name') : '';

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
     * Render trace rows as the indented tree: ▶ opens a span, ◀ closes it, · a point event. With
     * $color on (a TTY), each line is tinted by its event type via {@see TraceFormat::paint()}.
     *
     * @param array<int, mixed> $rows
     */
    private function renderRows(array $rows, bool $color = false): string
    {
        $lines = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $depth = (int) ($row['depth'] ?? 0);
            $type = TraceFormat::str($row, 'type');
            $decoded = json_decode(TraceFormat::str($row, 'data'), true);
            $data = \is_array($decoded) ? $decoded : [];

            // Same one-line renderer as the live console, so history and live can never diverge.
            $head = TraceFormat::line(TraceFormat::str($row, 'phase'), $type, $data);
            $lines[] = str_repeat('  ', $depth) . TraceFormat::paint($type, $head, $color);
        }

        return implode("\n", $lines);
    }
}
