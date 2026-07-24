<?php

declare(strict_types=1);

namespace Claw\Workflow;

use Claw\Exceptions\WorkflowException;
use Claw\Project\IssueType;

/**
 * Where workflows live on disk, and the only door for writing them.
 *
 * A store has a shared scope and a private per-session one. Several stores are alive at once — the
 * dashboard holds every registered project open in one process — so each store's shared scope carries
 * its OWN namespace segment. That is not tidiness: with one segment between them, two folders holding
 * a workflow of the same name would resolve to whichever autoloader registered first, and nothing
 * would report it.
 *
 *   solvers  -> ClawWorkflow\Solvers\P<key>\<Name>    @ <appHome>/<key>-workflows/Common/<Name>.php
 *   session  -> ClawWorkflow\Session\S<sid>\<Name>    @ <base>/Session/S<sid>/<Name>.php
 *   library  -> ClawWorkflow\Library\<Name>           @ <CLAW_LIBRARY>/<Name>.php
 *   project  -> ClawWorkflow\Project\P<key>\<Name>    @ <project>/.claw/workflows/<Name>.php
 *
 * The two libraries are read here and written by people; `Common` (the DIRECTORY) is where a run's
 * GENERATED solver lands. Its namespace used to be `Common` too, shared by every project — renaming
 * was once declined to spare the files already on disk, but the collision stopped being hypothetical:
 * see {@see solvers()}. A solver generated before the rename declares the old namespace and reports
 * itself as mismatched; it is regenerated on the next run.
 *
 * A workflow file is pulled in by a plain namespace->path autoloader, so each class is included
 * exactly once per process.
 */
final class WorkflowStore
{
    private const string NS_ROOT = 'ClawWorkflow';

    /** @var array<string, true> base dirs already wired to the autoloader (per process). */
    private static array $autoloaded = [];

    private readonly string $sessionSegment;

    /**
     * @param string  $sharedSegment the namespace segment of this store's SHARED scope. It is a
     *                               parameter because several stores are alive in one process — a
     *                               project's generated solvers, the global library, and each project's
     *                               own — and two of them holding a class of the same name would
     *                               otherwise resolve to whichever autoloader was registered first,
     *                               silently. Distinct segments make that collision impossible.
     * @param ?string $sharedDir     where that scope's files are, when it is not the default
     *                               `<baseDir>/Common`. A library's files sit directly in the folder
     *                               the user configured; nothing should force them into a subdirectory
     *                               named after our namespace.
     */
    public function __construct(
        private readonly string $baseDir,
        string $sessionId,
        private readonly string $sharedSegment = 'Common',
        private readonly ?string $sharedDir = null,
    ) {
        $this->sessionSegment = 'S' . self::sanitize($sessionId);
        $this->registerAutoloader();
    }

    /**
     * Where a project keeps its OWN ready-made workflows: inside the project itself, so they are
     * versioned with the code they serve, travel with a checkout, and can be read and edited by the
     * person whose repository it is.
     */
    public const string PROJECT_LIBRARY = '.claw/workflows';

    /**
     * Both shelves a ticket can be solved off, in the order a name clash is resolved: the global
     * library first, the project's own second and winning.
     *
     * @return list<self>
     */
    public static function libraries(string $globalDir, string $projectPath, string $projectKey): array
    {
        return [
            self::library($globalDir),
            self::projectLibrary($projectPath . '/' . self::PROJECT_LIBRARY, $projectKey),
        ];
    }

    /**
     * The GLOBAL library: hand-written, tested workflows every project can be offered, at the path
     * `CLAW_LIBRARY` points to. Read-only in practice — it is filled by people, not by runs.
     */
    public static function library(string $dir): self
    {
        return new self($dir, '', 'Library', $dir);
    }

    /**
     * A PROJECT's own library, living inside that project's repository, so it is versioned with the
     * code it serves and travels with a checkout. Keyed by project because the dashboard holds every
     * registered project open in one process.
     */
    public static function projectLibrary(string $dir, string $projectKey): self
    {
        return new self($dir, '', 'Project\\P' . self::sanitize($projectKey), $dir);
    }

    /**
     * A project's GENERATED solvers, keyed by project for the same reason {@see projectLibrary()} is —
     * and this one was seen failing live, not hypothesised: two projects each generated a solver for
     * THEIR issue #2, both declaring `ClawWorkflow\Common\Issue2Solver`, and whichever loaded first ran
     * for both — one project's run implemented the other project's feature inside the wrong repository.
     */
    public static function solvers(string $projectsDir, string $projectKey): self
    {
        return new self(
            $projectsDir . '/' . $projectKey . '-workflows',
            $projectKey,
            'Solvers\\P' . self::sanitize($projectKey),
        );
    }

    /**
     * Persist a workflow's PHP code: the only sanctioned way to write into the
     * workflow folders. Returns the file path.
     *
     * @throws WorkflowException on an invalid name or a write failure
     */
    public function write(string $name, string $code, bool $shared = false): string
    {
        $this->assertValidName($name);

        $dir = $shared ? $this->commonDir() : $this->sessionDir();

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new WorkflowException("cannot create workflow directory: {$dir}");
        }

        $path = $this->path($name, $shared);

        if (file_put_contents($path, $code) === false) {
            throw new WorkflowException("cannot write workflow: {$path}");
        }

        return $path;
    }

    /** Where a workflow's file lives (or would live), given its scope. */
    public function path(string $name, bool $shared = false): string
    {
        $this->assertValidName($name);

        return ($shared ? $this->commonDir() : $this->sessionDir()) . '/' . $name . '.php';
    }

    /** The fully-qualified class name a workflow must declare, given its scope. */
    public function classFor(string $name, bool $shared = false): string
    {
        $this->assertValidName($name);

        return $this->namespaceFor($shared) . '\\' . $name;
    }

    /** The namespace a workflow declares, given its scope — the package its {@see classFor()} lives in. */
    public function namespaceFor(bool $shared): string
    {
        return $shared
            ? self::NS_ROOT . '\\' . $this->sharedSegment
            : self::NS_ROOT . '\\Session\\' . $this->sessionSegment;
    }

    /**
     * The ready-made workflows this store offers, with everything the ProjectManager chooses by. Only
     * classes marked {@see LibraryWorkflow} are listed — a library folder also holds base classes and
     * helpers, and being in a directory is not a decision to be choosable.
     *
     * Reflection means LOADING each class, so a file that cannot be loaded is not skipped quietly: a
     * library nobody can see the broken half of is worse than one that says what is wrong with it. The
     * same goes for a missing description — an entry the model cannot judge is an entry it picks blind.
     *
     * Loading also means a class is read ONCE per process: were two shelves to hold the same
     * fully-qualified name, both would report whichever file was read first. That is what the per-shelf
     * namespace segments prevent, and it is why they are not cosmetic.
     *
     * @return list<array{name: string, class: string, description: string, serves: list<IssueType>, kind: string, recipe: string}>
     *
     * @throws WorkflowException
     */
    public function catalogue(): array
    {
        $entries = [];

        foreach (glob($this->commonDir() . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                continue;   // not a class file we could have written; not ours to complain about
            }

            $class = $this->classFor($name, true);

            if (!class_exists($class)) {
                throw new WorkflowException(
                    "workflow library: {$file} does not declare {$class} — the class must be named after "
                    . 'its file and live in that folder\'s namespace',
                );
            }

            $reflection = new \ReflectionClass($class);
            $workflow = $reflection->getAttributes(LibraryWorkflow::class)[0] ?? null;
            $strategy = $reflection->getAttributes(GenerationStrategy::class)[0] ?? null;

            if ($workflow === null && $strategy === null) {
                continue;   // present but not offered: a base class, a helper, something half-written
            }

            // A shelf holds two kinds of entry and they are not interchangeable: one is code that runs
            // as written, the other is prose the generator reads before writing a solver. Claiming both
            // would leave the run path guessing which, so it is an error rather than a precedence rule.
            if ($workflow !== null && $strategy !== null) {
                throw new WorkflowException(
                    "workflow library: {$class} is marked both LibraryWorkflow and GenerationStrategy — "
                    . 'it must be one or the other: a workflow is run, an approach is read',
                );
            }

            $description = self::describe($reflection);
            $serves = ($workflow ?? $strategy)->newInstance()->serves;
            $kind = $workflow !== null ? 'workflow' : 'strategy';

            if ($description === '' || $serves === []) {
                throw new WorkflowException(
                    "workflow library: {$class} is offered but cannot be chosen — it needs a docblock "
                    . 'describing when to use it, and at least one issue type in its attribute',
                );
            }

            $recipe = $kind === 'strategy' ? self::recipeOf($reflection) : '';

            $entries[] = [
                'name' => $name,
                'class' => $class,
                'description' => $description,
                'serves' => $serves,
                'kind' => $kind,
                'recipe' => $recipe,
            ];
        }

        return $entries;
    }

    /**
     * What may be offered for a ticket of this type, gathered from several libraries and keyed by the
     * name the ProjectManager will quote back.
     *
     * The type is the filter, and it is the reason a wrong pick is not available rather than merely
     * discouraged: a workflow that does not serve this kind of work never reaches the list, so it
     * cannot be chosen off it. Later stores WIN a name clash — the caller passes the global library
     * first and the project's own second, because a project that ships a workflow under a name the
     * global library also uses meant its own.
     *
     * @param list<self> $stores
     *
     * @return array<string, array{name: string, class: string, description: string, serves: list<IssueType>, kind: string, recipe: string}>
     *
     * @throws WorkflowException
     */
    public static function offered(array $stores, IssueType $type): array
    {
        $offered = [];

        foreach ($stores as $store) {
            foreach ($store->catalogue() as $entry) {
                if (\in_array($type, $entry['serves'], true)) {
                    $offered[$entry['name']] = $entry;
                }
            }
        }

        return $offered;
    }

    /**
     * A strategy's recipe: the `RECIPE` constant on the class. This is the text handed to the generator,
     * as distinct from the docblock, which only says WHEN to choose this approach — the ProjectManager
     * reads the latter to pick, the generator reads the former to write.
     *
     * A strategy without one is an error rather than an empty recipe: it would be chosen off the shelf
     * on the strength of its description and then contribute nothing, which is indistinguishable at the
     * far end from `generate` — the expensive path this exists to avoid taking blindly.
     *
     * @param \ReflectionClass<object> $class
     *
     * @throws WorkflowException
     */
    private static function recipeOf(\ReflectionClass $class): string
    {
        $recipe = $class->hasConstant('RECIPE') ? $class->getConstant('RECIPE') : null;

        if (!\is_string($recipe) || trim($recipe) === '') {
            throw new WorkflowException(
                "workflow library: {$class->getName()} is a generation strategy but has no RECIPE "
                . 'constant — the approach itself is the whole point of the entry',
            );
        }

        return $recipe;
    }

    /**
     * A class's docblock as prose. Annotation lines are dropped at the first one: everything below
     * `@param` is written for a static analyser, and pasting it into a prompt spends tokens on syntax
     * the reader has no use for.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function describe(\ReflectionClass $class): string
    {
        $doc = $class->getDocComment();

        if ($doc === false) {
            return '';   // comments stripped (opcache.save_comments=0) reads the same as none written
        }

        $lines = [];

        foreach (preg_split('/\R/u', $doc) ?: [] as $line) {
            $line = trim((string) preg_replace('#^\s*(/\*\*+|\*+/|\*)#', '', $line));

            if (str_starts_with($line, '@')) {
                break;
            }

            $lines[] = $line;
        }

        return trim(implode("\n", $lines));
    }

    /** The short class name — the segment after the last namespace separator of a fully-qualified name. */
    public static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    private function commonDir(): string
    {
        return $this->sharedDir ?? $this->baseDir . '/Common';
    }

    private function sessionDir(): string
    {
        return $this->baseDir . '/Session/' . $this->sessionSegment;
    }

    /**
     * Map this store's two scopes to their two directories. Each scope registers its OWN namespace
     * prefix rather than assuming the file path mirrors the namespace, which is what lets a library
     * keep its files directly in the configured folder. The class name is built only from validated
     * identifiers, so no path traversal is possible.
     */
    private function registerAutoloader(): void
    {
        // Keyed by scope, not by directory alone: two stores may share a base and differ in segment.
        $key = $this->baseDir . '|' . $this->sharedSegment;

        if (isset(self::$autoloaded[$key])) {
            return;
        }
        self::$autoloaded[$key] = true;

        $scopes = [
            $this->namespaceFor(true) . '\\' => $this->commonDir(),
            $this->namespaceFor(false) . '\\' => $this->sessionDir(),
        ];

        spl_autoload_register(static function (string $class) use ($scopes): void {
            foreach ($scopes as $prefix => $dir) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $path = $dir . '/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

                if (is_file($path)) {
                    require $path;
                }

                return;
            }
        });
    }

    /** @throws WorkflowException */
    private function assertValidName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new WorkflowException("invalid workflow name '{$name}'");
        }
    }

    private static function sanitize(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $id) ?? '_';
    }
}
