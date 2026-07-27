<?php

declare(strict_types=1);

namespace Claw\Cli;

use Claw\Agent\AgentFactory;
use Claw\Config;
use Claw\Exceptions\ClawException;
use Claw\Http\CurlHttpClient;
use Claw\Knowledge\KnowledgeBase;
use Claw\Project\Issue;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
use Claw\Project\Strategy;
use Claw\Run\ConsoleRunFrontend;
use Claw\Run\IssueRunner;
use Claw\Run\Triage;
use Claw\Server;
use Claw\ServerLocator;
use Claw\Trace\Level;
use Claw\Trace\TraceReader;

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
            'serve' => $this->serve(\array_slice($args, 1)),
            default => $this->usage(),
        };
    }

    /**
     * Handle `claw serve [--port N] [--host H]`: start the read-only dashboard JSON API
     * over every project's state db. Requires the TrueAsync server extension to be loaded:
     *   php -d extension=/path/to/true_async_server.so bin/claw serve
     *
     * @param list<string> $args
     */
    private function serve(array $args): int
    {
        $host = '127.0.0.1';
        $port = 8787;

        foreach ($args as $i => $arg) {
            if ($arg === '--host' && isset($args[$i + 1])) {
                $host = $args[$i + 1];
            } elseif (\str_starts_with($arg, '--host=')) {
                $host = \substr($arg, 7);
            } elseif ($arg === '--port' && isset($args[$i + 1])) {
                $port = (int) $args[$i + 1];
            } elseif (\str_starts_with($arg, '--port=')) {
                $port = (int) \substr($arg, 7);
            }
        }

        if (!\class_exists('TrueAsync\\HttpServer')) {
            fwrite(STDERR, "claw serve: the TrueAsync server extension is not loaded.\n");
            fwrite(STDERR, "  php -d extension=/path/to/true_async_server.so bin/claw serve\n");

            return 1;
        }

        // One server per workspace: two would write the same project dbs. A record left by a crash is
        // ignored — the guard is the live connection, not the file.
        $locator = new ServerLocator($this->appHome());
        $existing = $locator->locate();

        if ($existing !== null && $locator->alive($existing['host'], $existing['port'])) {
            fwrite(STDERR, 'claw serve: a server for this workspace is already running at '
                . "{$existing['host']}:{$existing['port']} (pid {$existing['pid']}).\n");

            return 1;
        }

        new Server($this->projectsDir(), $this->root, $this->appHome())->run($host, $port);

        return 0;
    }

    /**
     * Handle `claw -c [folder]`: register a project's state db under the app home. The target is
     * the first non-flag argument, defaulting to the current directory. This is the one command
     * that does not resolve an existing project — it creates one.
     *
     * `--no-kb` declines the knowledge base. It is a flag to REFUSE rather than one to ask for,
     * because the folder is what makes the tool exist at all and a base nobody can start filling is
     * how this subsystem went unused; the person who does not want a folder in their tree says so.
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

        $withKnowledgeBase = !\in_array('--no-kb', $args, true);

        try {
            $project = ProjectStore::init($this->projectsDir(), $target, '', $withKnowledgeBase);
        } catch (ClawException $e) {
            fwrite(STDERR, 'claw -c: ' . $e->getMessage() . "\n");

            return 1;
        }

        fwrite(STDOUT, "Project '{$project->name}' initialized.\n");
        fwrite(STDOUT, "  folder: {$project->path}\n");
        fwrite(STDOUT, '  state:  ' . $this->projectsDir() . '/' . $project->id . ".db\n");

        $notes = $withKnowledgeBase ? KnowledgeBase::create($project->path) : null;

        if ($notes !== null) {
            fwrite(STDOUT, "  notes:  {$notes}/\n");
        }

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
            $store = $this->resolve($projectDir);
            $issue = $store->addIssue($title);
        } catch (ClawException $e) {
            fwrite(STDERR, 'claw -i: ' . $e->getMessage() . "\n");

            return 1;
        }

        fwrite(STDOUT, "Issue #{$issue->id} opened: {$issue->title}\n");
        fwrite(STDOUT, '  project: ' . $issue->project . "\n");
        fwrite(STDOUT, '  status:  ' . $issue->status->name . "\n");

        // The ticket is already open and recorded — everything below is the second stage, and the
        // issue stands whether or not it succeeds. Run inline rather than detached: on a one-shot
        // command there is nothing left to watch the verdict arrive, so it is worth the wait here.
        fwrite(STDOUT, "  analysing…\n");
        $strategy = $this->triage($store, $issue);

        // Three outcomes, and "no strategy" is not one thing: the ProjectManager may have PARKED the
        // ticket for a person, which is a decision, not the absence of one.
        $triaged = $store->loadIssue($issue->id);
        $parked = $triaged->status === IssueStatus::WaitingHuman;
        $type = $triaged->type === null ? 'untyped' : $triaged->type->value;

        fwrite(STDOUT, match (true) {
            $strategy !== null => "  type: {$type}\n  strategy: {$strategy->value}\n",
            $parked => "  strategy: none — parked for a person (the ticket cannot be worked on as written)\n",
            default => "  strategy: not decided (analysis recorded nothing — run `claw run` to solve it anyway)\n",
        });

        return 0;
    }

    /**
     * The ProjectManager's analysis of a freshly opened ticket. Returns the strategy it recorded, or
     * null when there is no usable agent configured or the analysis produced nothing — neither is a
     * reason to fail the command, because the ticket itself is already safely open.
     */
    private function triage(ProjectStore $store, Issue $issue): ?Strategy
    {
        try {
            $config = Config::load($this->root . '/.env');
            $agent = AgentFactory::make($config, new CurlHttpClient());
        } catch (ClawException) {
            return null;
        }

        return $agent === null ? null : new Triage($store, $config, $agent)->analyse($issue);
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
            $issue = $store->loadIssue($issueId);
            $config = Config::load($this->root . '/.env');
        } catch (ClawException $e) {
            fwrite(STDERR, 'claw run: ' . $e->getMessage() . "\n");

            return 1;
        }

        $agent = AgentFactory::make($config, new CurlHttpClient());

        if ($agent === null) {
            fwrite(STDERR, "claw run: agent '{$config->agent}' is not wired yet.\n");

            return 1;
        }

        return new IssueRunner($this->projectsDir(), $store, $config, $agent, new ConsoleRunFrontend($verbosity))
            ->run($issue);
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

        $runs = $store->recentRuns();

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
        $color = stream_isatty(STDOUT) && getenv('NO_COLOR') === false;
        $tree = $reader->render($runId, $verbosity ?? Level::Debug, $color);
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
}
