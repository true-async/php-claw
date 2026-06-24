<?php

declare(strict_types=1);

namespace Claw\Cli;

use Claw\Agent\ConsoleSpeaker;
use Claw\Config;
use Claw\Exceptions\ClawException;
use Claw\Http\CurlHttpClient;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
use Claw\Tool\BashTool;
use Claw\Tool\DefineWorkflowTool;
use Claw\Tool\ListFilesTool;
use Claw\Tool\ReadFileTool;
use Claw\Tool\Registry;
use Claw\Tool\Workspace;
use Claw\Tool\WriteFileTool;
use Claw\Trace\ConsoleTraceSink;
use Claw\Trace\Level;
use Claw\Trace\Tracer;
use Claw\Trace\TraceReader;
use Claw\Trace\TraceStore;
use Claw\Workflow\Environment;
use Claw\Workflow\EnvKey;
use Claw\Workflow\GenerateIssueWorkflow;
use Claw\Workflow\SqliteStateStore;
use Claw\Workflow\WorkflowAbstract;
use Claw\Workflow\WorkflowStore;
use Claw\Workflow\WorkflowValidator;

/**
 * The default mode: drive a project's issues through generated solver workflows.
 *
 * Commands:
 *   claw -c [folder]   register a project (a state db in the app home)
 *   claw -i "<title>"  open an issue in the current project
 *   claw run <id>      generate/run the solver workflow for an issue
 *   claw log [runId]   print a run's recorded trace
 *
 * Every command but `-c` runs IN a project. The project is resolved once per invocation
 * ({@see resolve()}): an explicit `--project <dir>` / `CLAW_PROJECT`, else the current
 * directory — walking up to the nearest registered project, like git finding the repo
 * root. No project resolves -> a hard error, never a silent default.
 *
 * The setup/read commands (`-c`, `-i`, `log`) touch only the app's own state and need
 * no agent/API key, so they never load the full {@see Config}; `run` does.
 */
final class WorkflowMode
{
    /** @param string $root the install root: anchors the app home (state db, generated workflows). */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param list<string> $args the argv tail (no script name)
     */
    public function run(array $args): int
    {
        // Pull `--project <dir>` and the density flags out first so they may sit anywhere without
        // shadowing the command word or the issue id.
        [$args, $projectDir] = $this->extractProjectOption($args);
        [$args, $verbosity] = $this->extractVerbosity($args);

        if (\in_array('-c', $args, true) || \in_array('--create', $args, true)) {
            return $this->createProject($args);
        }
        if (\in_array('-i', $args, true) || \in_array('--issue', $args, true)) {
            return $this->createIssue($args, $projectDir);
        }

        return match ($args[0] ?? null) {
            'run' => $this->runIssue(\array_slice($args, 1), $projectDir, $verbosity),
            'log' => $this->showHistory(\array_slice($args, 1), $projectDir, $verbosity),
            default => $this->usage(),
        };
    }

    /**
     * Handle `claw -c [folder]`: register a project's state db under the app home. The target is
     * the first non-flag argument, defaulting to the current directory. This is the one command
     * that does not resolve an existing project — it creates one.
     *
     * @param list<string> $args
     */
    private function createProject(array $args): int
    {
        $target = Cli::firstPositional($args) ?? getcwd();
        if ($target === false) {
            fwrite(STDERR, "claw -c: cannot determine the project folder\n");

            return 1;
        }

        try {
            $project = ProjectStore::init($this->projectsDir(), $target);
        } catch (ClawException $e) {
            fwrite(STDERR, 'claw -c: ' . $e->getMessage() . "\n");

            return 1;
        }

        fwrite(STDOUT, "Project '{$project->name}' initialized.\n");
        fwrite(STDOUT, "  folder: {$project->path}\n");
        fwrite(STDOUT, '  state:  ' . $this->projectsDir() . '/' . $project->id . ".db\n");

        return 0;
    }

    /**
     * Handle `claw -i "<title>"`: open an issue in the resolved project. The title is the first
     * non-flag argument.
     *
     * @param list<string> $args
     */
    private function createIssue(array $args, ?string $projectDir): int
    {
        $title = Cli::firstPositional($args);
        if ($title === null) {
            fwrite(STDERR, "claw -i: an issue title is required (usage: claw -i \"title\")\n");

            return 1;
        }

        try {
            $issue = $this->resolve($projectDir)->addIssue($title);
        } catch (ClawException $e) {
            fwrite(STDERR, 'claw -i: ' . $e->getMessage() . "\n");

            return 1;
        }

        fwrite(STDOUT, "Issue #{$issue->id} opened: {$issue->title}\n");
        fwrite(STDOUT, '  project: ' . $issue->project . "\n");
        fwrite(STDOUT, '  status:  ' . $issue->status->name . "\n");

        return 0;
    }

    /**
     * Handle `claw run <id>`: start working on an issue. The default workflow does not solve
     * the issue directly — it GENERATES a solver workflow tailored to it (the project's
     * procedural memory), the human approves the generated code, and then the solver runs in
     * the project's real folder. A solver already generated for the issue is reused.
     *
     * Unlike the setup commands this needs a model, so it loads the full config here.
     *
     * @param list<string> $args
     */
    private function runIssue(array $args, ?string $projectDir, ?Level $verbosity): int
    {
        $issueId = Cli::firstPositional($args);
        if ($issueId === null) {
            fwrite(STDERR, "claw run: an issue id is required (usage: claw run <id>)\n");

            return 1;
        }

        try {
            $store = $this->resolve($projectDir);
            $project = $store->project();
            $issue = $store->loadIssue($issueId);
            $config = Config::load($this->root . '/.env');
        } catch (ClawException $e) {
            fwrite(STDERR, 'claw run: ' . $e->getMessage() . "\n");

            return 1;
        }

        $agent = Cli::makeAgent($config, new CurlHttpClient());
        if ($agent === null) {
            fwrite(STDERR, "claw run: agent '{$config->agent}' is not wired yet.\n");

            return 1;
        }

        // The palette acts on the REAL project folder: this run works on the user's repo.
        $workspace = new Workspace($project->path);
        $workflowStore = new WorkflowStore($this->projectsDir() . '/' . $project->id . '-workflows', $project->id);
        $projectDb = $store->pdo();   // the one open connection: shared by the state store + trace

        $registry = new Registry();
        $registry->add(new BashTool($project->path));
        $registry->add(new ReadFileTool($workspace));
        $registry->add(new WriteFileTool($workspace));
        $registry->add(new ListFilesTool($workspace));
        $registry->add(new DefineWorkflowTool($workflowStore, new WorkflowValidator()));

        $env = (new Environment())
            ->set(EnvKey::Worker, $agent)
            ->set(EnvKey::Registry, $registry)
            ->set(EnvKey::ModelId, $config->model)
            ->set(EnvKey::SystemPrompt, Cli::DEFAULT_SYSTEM)
            ->set(EnvKey::MaxHistory, $config->maxHistory)
            ->set(EnvKey::Store, new SqliteStateStore($projectDb))   // durable: a killed run resumes here
            ->set(EnvKey::Agents, $config->agents)                   // named roles share this access, override only the model
            ->set(EnvKey::Ask, new ConsoleSpeaker(STDIN, STDOUT));   // ask() reaches the human at the console (Request>)

        $solverName = 'Issue' . (string) preg_replace('/[^A-Za-z0-9]/', '', $issueId) . 'Solver';
        $solverClass = $workflowStore->classFor($solverName, true);
        $solverPath = $workflowStore->path($solverName, true);
        $solverNamespace = substr($solverClass, 0, (int) strrpos($solverClass, '\\'));

        // Resume an interrupted run (status still 'running') for this issue's solver, else start a new
        // one. The run id ties the ledger row, the trace, and the durable state snapshot together — so
        // resuming reuses it: the solver restores its saved state and re-runs only the unfinished tail.
        $runId = $store->resumableRun($issue->id, $solverName);
        $resuming = $runId !== null;
        if ($runId === null) {
            $runId = $store->recordRun($issue->id, $solverName);
        }
        $store->setIssueStatus($issue->id, IssueStatus::InProgress);
        $tracer = new Tracer($runId, new TraceStore($projectDb), new ConsoleTraceSink(STDERR, $verbosity ?? Level::Info));
        $env->set(EnvKey::Tracer, $tracer);
        if ($resuming) {
            fwrite(STDOUT, "Resuming run #{$runId} for issue #{$issue->id}…\n");
        }

        if (!is_file($solverPath)) {
            fwrite(STDOUT, "Generating a solver workflow for issue #{$issue->id}…\n");

            $gen = $tracer->enterWorkflow('generate-issue-workflow');
            try {
                new GenerateIssueWorkflow($env, $runId . '-gen', [
                    'solverName' => $solverName,
                    'solverNamespace' => $solverNamespace,
                    'solverTools' => ['read_file', 'write_file', 'list_files', 'bash'],
                ], $issue, $project)->run();
            } catch (\Throwable $e) {
                $tracer->exit($gen);
                $store->setRunStatus($runId, 'failed');
                fwrite(STDERR, 'claw run: generation failed: ' . $e->getMessage() . "\n");

                return 1;
            }
            $tracer->exit($gen);

            if (!is_file($solverPath)) {
                $store->setRunStatus($runId, 'failed');
                fwrite(STDERR, "claw run: no solver workflow was produced\n");

                return 1;
            }

            fwrite(STDOUT, "\n--- {$solverPath} ---\n" . (string) file_get_contents($solverPath) . "\n--- end ---\n\n");
            if (!$this->confirm('Run this workflow now?')) {
                $store->setRunStatus($runId, 'generated');
                fwrite(STDOUT, "Saved. Not run — review it, then `claw run {$issue->id}` again.\n");

                return 0;
            }
        } else {
            fwrite(STDOUT, "Reusing solver {$solverClass}.\n");
        }

        $solverSpan = $tracer->enterWorkflow($solverName);
        try {
            $solver = new $solverClass($env, $runId, [], $issue, $project);
            if (!$solver instanceof WorkflowAbstract) {
                throw new ClawException("{$solverClass} is not a workflow");
            }
            $solver->run();
        } catch (\Throwable $e) {
            $tracer->exit($solverSpan);
            $store->setRunStatus($runId, 'failed');
            fwrite(STDERR, 'claw run: run #' . $runId . ' failed: ' . $e->getMessage() . "\n");

            return 1;
        }
        $tracer->exit($solverSpan);

        $store->setRunStatus($runId, 'done');
        fwrite(STDOUT, "Run #{$runId} finished for issue #{$issue->id}.\n");

        return 0;
    }

    /**
     * Handle `claw log [runId]`: print a run's recorded trace tree from the project db. Reads only —
     * no model, no API key. Defaults to the most recent run; lists recent runs for picking another.
     *
     * @param list<string> $args
     */
    private function showHistory(array $args, ?string $projectDir, ?Level $verbosity): int
    {
        try {
            $store = $this->resolve($projectDir);
        } catch (ClawException $e) {
            fwrite(STDERR, 'claw log: ' . $e->getMessage() . "\n");

            return 1;
        }

        $reader = new TraceReader($store->pdo());

        $runs = $reader->runs();
        if ($runs === []) {
            fwrite(STDOUT, "No runs yet for this project.\n");

            return 0;
        }

        $runId = Cli::firstPositional($args) ?? $reader->latestRunId() ?? $runs[0]['id'];

        fwrite(STDOUT, "Runs:\n");
        $header = null;
        foreach ($runs as $run) {
            if ($run['id'] === $runId) {
                $header = $run;
            }
            $mark = $run['id'] === $runId ? '→' : ' ';
            fwrite(STDOUT, "  {$mark} #{$run['id']}  [{$run['status']}]  issue #{$run['issue']}  {$run['workflow']}\n");
        }

        $title = $header !== null
            ? "run #{$runId}  (issue #{$header['issue']}, {$header['workflow']}, {$header['status']})"
            : "run #{$runId}";
        $tree = $reader->render($runId, $verbosity ?? Level::Debug);
        fwrite(STDOUT, "\nTrace of {$title}:\n" . ($tree === '' ? '  (no trace recorded)' : $tree) . "\n");

        return 0;
    }

    /** Print the available commands. Shown when no command is given. */
    private function usage(): int
    {
        fwrite(STDERR, implode("\n", [
            'claw — per-issue workflow runner (default mode)',
            '',
            'Commands:',
            '  claw -c [folder]     register a project (a state db in the app home)',
            '  claw -i "<title>"    open an issue in the current project',
            '  claw run <id>        generate/run the solver workflow for an issue',
            '  claw log [runId]     print a run\'s recorded trace',
            '',
            'Options:',
            '  --project <dir>      act on the project at <dir> (default: walk up from cwd;',
            '                       CLAW_PROJECT sets it too)',
            '  -q, --quiet          console shows only milestones and errors',
            '  -v, --verbose        console shows every model turn, prompt and reply',
            '  --session            start the interactive chat instead (the old mode)',
            '',
        ]) . "\n");

        return 1;
    }

    /**
     * Resolve the project to act on, once: an explicit `--project <dir>` / `CLAW_PROJECT`, else
     * the current directory — then walk up to the nearest registered project and open one handle.
     *
     * @throws ClawException when the start dir is unknown or no project is found
     */
    private function resolve(?string $projectDir): ProjectStore
    {
        $start = $projectDir ?? (getenv('CLAW_PROJECT') ?: getcwd());
        if ($start === false) {
            throw new ClawException('cannot determine the project folder');
        }

        $store = ProjectStore::discover($this->projectsDir(), $start);
        if ($store === null) {
            throw new ClawException("not inside a registered project: {$start} (run: claw -c)");
        }

        return $store;
    }

    /**
     * Split `--project <dir>` / `--project=<dir>` (alias `-C <dir>`) out of the args, returning
     * the remaining args and the override directory (null if absent).
     *
     * @param list<string> $args
     *
     * @return array{0: list<string>, 1: ?string}
     */
    private function extractProjectOption(array $args): array
    {
        $rest = [];
        $dir = null;
        for ($i = 0, $n = \count($args); $i < $n; ++$i) {
            $arg = $args[$i];
            if ($arg === '--project' || $arg === '-C') {
                $dir = $args[$i + 1] ?? null;   // value is the next token
                ++$i;                            // consume it
                continue;
            }
            if (str_starts_with($arg, '--project=')) {
                $dir = substr($arg, \strlen('--project='));
                continue;
            }
            $rest[] = $arg;
        }

        return [$rest, ($dir === null || $dir === '') ? null : $dir];
    }

    /**
     * Split the density flags (`-q`/`--quiet`, `-v`/`--verbose`) out of the args, returning the rest
     * and the console threshold they pick — null when none is given, so each command keeps its own
     * default. Last flag wins.
     *
     * @param list<string> $args
     *
     * @return array{0: list<string>, 1: ?Level}
     */
    private function extractVerbosity(array $args): array
    {
        $rest = [];
        $level = null;
        foreach ($args as $arg) {
            if ($arg === '-q' || $arg === '--quiet') {
                $level = Level::Notice;
            } elseif ($arg === '-v' || $arg === '--verbose') {
                $level = Level::Debug;
            } else {
                $rest[] = $arg;
            }
        }

        return [$rest, $level];
    }

    /** Where the app keeps every project's state db. Anchored to the install, not the cwd. */
    private function projectsDir(): string
    {
        return $this->appHome() . '/projects';
    }

    /**
     * The application's home folder: where it keeps its own state. Anchored to the install,
     * not the cwd, since setup commands are usually run from an external project folder.
     */
    private function appHome(): string
    {
        $home = getenv('CLAW_WORKSPACE');

        return ($home === false || $home === '') ? $this->root . '/workspace' : $home;
    }

    /** Ask a yes/no question on the console; true only on an explicit yes. */
    private function confirm(string $question): bool
    {
        fwrite(STDOUT, $question . ' [y/N] ');
        $line = fgets(STDIN);

        return $line !== false && \in_array(strtolower(trim($line)), ['y', 'yes'], true);
    }
}
