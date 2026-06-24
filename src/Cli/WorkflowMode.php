<?php

declare(strict_types=1);

namespace Claw\Cli;

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
        if (\in_array('-c', $args, true) || \in_array('--create', $args, true)) {
            return $this->createProject($args);
        }
        if (\in_array('-i', $args, true) || \in_array('--issue', $args, true)) {
            return $this->createIssue($args);
        }

        return match ($args[0] ?? null) {
            'run' => $this->runIssue(\array_slice($args, 1)),
            'log' => $this->showHistory(\array_slice($args, 1)),
            default => $this->usage(),
        };
    }

    /**
     * Handle `claw -c [folder]`: initialize a project's state db under the app home.
     * The target is the first non-flag argument, defaulting to the current directory.
     *
     * @param list<string> $args
     */
    private function createProject(array $args): int
    {
        $appHome = $this->appHome();
        $target = Cli::firstPositional($args) ?? getcwd();
        if ($target === false) {
            fwrite(STDERR, "claw -c: cannot determine the project folder\n");

            return 1;
        }

        try {
            $project = (new ProjectStore($appHome . '/projects'))->init($target);
        } catch (ClawException $e) {
            fwrite(STDERR, 'claw -c: ' . $e->getMessage() . "\n");

            return 1;
        }

        fwrite(STDOUT, "Project '{$project->name}' initialized.\n");
        fwrite(STDOUT, "  folder: {$project->path}\n");
        fwrite(STDOUT, '  state:  ' . $appHome . '/projects/' . $project->id . ".db\n");

        return 0;
    }

    /**
     * Handle `claw -i "<title>"`: open an issue in the project rooted at the current
     * directory. The title is the first non-flag argument.
     *
     * @param list<string> $args
     */
    private function createIssue(array $args): int
    {
        $title = Cli::firstPositional($args);
        if ($title === null) {
            fwrite(STDERR, "claw -i: an issue title is required (usage: claw -i \"title\")\n");

            return 1;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "claw -i: cannot determine the project folder\n");

            return 1;
        }

        try {
            $issue = (new ProjectStore($this->appHome() . '/projects'))->addIssue($cwd, $title);
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
    private function runIssue(array $args): int
    {
        $issueId = Cli::firstPositional($args);
        if ($issueId === null) {
            fwrite(STDERR, "claw run: an issue id is required (usage: claw run <id>)\n");

            return 1;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "claw run: cannot determine the project folder\n");

            return 1;
        }

        $appHome = $this->appHome();
        $store = new ProjectStore($appHome . '/projects');

        try {
            $project = $store->load($cwd);
            $issue = $store->loadIssue($cwd, $issueId);
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
        $workflowStore = new WorkflowStore($appHome . '/projects/' . $project->id . '-workflows', $project->id);
        $projectDb = new \PDO('sqlite:' . $store->dbPath($project->id));   // shared by the state store + trace

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
            ->set(EnvKey::Agents, $config->agents);                  // named roles share this access, override only the model

        $solverName = 'Issue' . (string) preg_replace('/[^A-Za-z0-9]/', '', $issueId) . 'Solver';
        $solverClass = $workflowStore->classFor($solverName, true);
        $solverPath = $workflowStore->path($solverName, true);
        $solverNamespace = substr($solverClass, 0, (int) strrpos($solverClass, '\\'));

        // Resume an interrupted run (status still 'running') for this issue's solver, else start a new
        // one. The run id ties the ledger row, the trace, and the durable state snapshot together — so
        // resuming reuses it: the solver restores its saved state and re-runs only the unfinished tail.
        $runId = $store->resumableRun($cwd, $issue->id, $solverName);
        $resuming = $runId !== null;
        if ($runId === null) {
            $runId = $store->recordRun($cwd, $issue->id, $solverName);
        }
        $store->setIssueStatus($cwd, $issue->id, IssueStatus::InProgress);
        $tracer = new Tracer($runId, new TraceStore($projectDb), new ConsoleTraceSink(STDERR));
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
                $store->setRunStatus($cwd, $runId, 'failed');
                fwrite(STDERR, 'claw run: generation failed: ' . $e->getMessage() . "\n");

                return 1;
            }
            $tracer->exit($gen);

            if (!is_file($solverPath)) {
                $store->setRunStatus($cwd, $runId, 'failed');
                fwrite(STDERR, "claw run: no solver workflow was produced\n");

                return 1;
            }

            fwrite(STDOUT, "\n--- {$solverPath} ---\n" . (string) file_get_contents($solverPath) . "\n--- end ---\n\n");
            if (!$this->confirm('Run this workflow now?')) {
                $store->setRunStatus($cwd, $runId, 'generated');
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
            $store->setRunStatus($cwd, $runId, 'failed');
            fwrite(STDERR, 'claw run: run #' . $runId . ' failed: ' . $e->getMessage() . "\n");

            return 1;
        }
        $tracer->exit($solverSpan);

        $store->setRunStatus($cwd, $runId, 'done');
        fwrite(STDOUT, "Run #{$runId} finished for issue #{$issue->id}.\n");

        return 0;
    }

    /**
     * Handle `claw log [runId]`: print a run's recorded trace tree from the project db. Reads only —
     * no model, no API key. Defaults to the most recent run; lists recent runs for picking another.
     *
     * @param list<string> $args
     */
    private function showHistory(array $args): int
    {
        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, "claw log: cannot determine the project folder\n");

            return 1;
        }

        $store = new ProjectStore($this->appHome() . '/projects');

        try {
            $project = $store->load($cwd);
        } catch (ClawException $e) {
            fwrite(STDERR, 'claw log: ' . $e->getMessage() . "\n");

            return 1;
        }

        $reader = new TraceReader(new \PDO('sqlite:' . $store->dbPath($project->id)));

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
        $tree = $reader->render($runId);
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
            '  claw --session       start the interactive chat instead (the old mode)',
            '',
        ]) . "\n");

        return 1;
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
