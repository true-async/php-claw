<?php

declare(strict_types=1);

namespace Tests\Workflow;

use Claw\Exceptions\WorkflowException;
use Claw\Project\IssueType;
use Claw\Workflow\WorkflowStore;
use Testo\Assert;
use Testo\Test;

/**
 * The library side of {@see WorkflowStore}: what it offers the ProjectManager, and what it refuses to
 * offer silently.
 *
 * Every workflow written here has a class name used NOWHERE ELSE in the suite. PHP loads a class once
 * per process, so a name shared with another test resolves to whichever file was read first, and the
 * catalogue then reports that file's docblock — which passes or fails depending on test order.
 */
final class WorkflowLibraryTest
{
    #[Test]
    public function theCatalogueListsOnlyWhatWasMarkedAsChoosable(): void
    {
        $dir = self::tempDir();

        try {
            self::writeWorkflow($dir, 'Library', 'CataloguedBugFix', 'IssueType::Bug', 'Reproduces a defect, pins it with a failing test, then fixes it.');
            // A base class or a helper in the same folder is not an option on the shelf.
            self::writeWorkflow($dir, 'Library', 'CataloguedHelper', null, 'Something the others use.');

            $catalogue = WorkflowStore::library($dir)->catalogue();

            Assert::count($catalogue, 1);
            Assert::same($catalogue[0]['name'], 'CataloguedBugFix');
            Assert::same($catalogue[0]['class'], 'ClawWorkflow\Library\CataloguedBugFix');
            Assert::same($catalogue[0]['serves'], [IssueType::Bug]);
            Assert::true(str_contains($catalogue[0]['description'], 'failing test'));
        } finally {
            self::rmrf($dir);
        }
    }

    #[Test]
    public function aWorkflowOfferedWithNothingToChooseItByIsAnErrorNotAnEntry(): void
    {
        // A blank description is the failure mode this guards: the model would be picking off a list of
        // bare names, which is guessing with extra steps.
        $dir = self::tempDir();

        try {
            self::writeWorkflow($dir, 'Library', 'Nameless', 'IssueType::Chore', '');

            $threw = false;

            try {
                WorkflowStore::library($dir)->catalogue();
            } catch (WorkflowException $e) {
                $threw = str_contains($e->getMessage(), 'needs a docblock');
            }

            Assert::true($threw);
        } finally {
            self::rmrf($dir);
        }
    }

    #[Test]
    public function theTypeFiltersWhatIsOfferedSoAWrongPickIsNotOnTheList(): void
    {
        $dir = self::tempDir();

        try {
            self::writeWorkflow($dir, 'Library', 'FilteredBugFix', 'IssueType::Bug', 'Fixes a defect.');
            self::writeWorkflow($dir, 'Library', 'FilteredResearch', 'IssueType::Research, IssueType::Design', 'Reads around and reports.');

            $forBugs = WorkflowStore::offered([WorkflowStore::library($dir)], IssueType::Bug);
            $forResearch = WorkflowStore::offered([WorkflowStore::library($dir)], IssueType::Research);
            $forFeatures = WorkflowStore::offered([WorkflowStore::library($dir)], IssueType::Feature);

            Assert::same(array_keys($forBugs), ['FilteredBugFix']);
            Assert::same(array_keys($forResearch), ['FilteredResearch']);
            Assert::same($forFeatures, []);   // nothing ready-made fits; the caller must do the work
        } finally {
            self::rmrf($dir);
        }
    }

    #[Test]
    public function twoLibrariesHoldingTheSameNameDoNotShadowEachOther(): void
    {
        // The hazard the namespace segments exist for: one prefix between the shelves and the class
        // loaded would be whichever autoloader registered first, with nothing reporting the collision.
        // The dashboard holds every project open in one process, so this is not hypothetical.
        $global = self::tempDir();
        $project = self::tempDir();

        try {
            self::writeWorkflow($global, 'Library', 'Deploy', 'IssueType::Chore', 'The global one.');
            self::writeWorkflow($project, 'Project\\Pkey1', 'Deploy', 'IssueType::Chore', "The project's own.");

            $libraries = [WorkflowStore::library($global), WorkflowStore::projectLibrary($project, 'key1')];
            $offered = WorkflowStore::offered($libraries, IssueType::Chore);

            // One name, and the project's own is what it resolves to — it is passed last for that reason.
            Assert::count($offered, 1);
            Assert::same($offered['Deploy']['class'], 'ClawWorkflow\Project\Pkey1\Deploy');
            Assert::true(str_contains($offered['Deploy']['description'], "project's own"));

            // Both classes are nonetheless distinct and both really loaded — no redeclaration, no shadowing.
            Assert::true(class_exists('ClawWorkflow\Library\Deploy'));
            Assert::true(class_exists('ClawWorkflow\Project\Pkey1\Deploy'));
        } finally {
            self::rmrf($global);
            self::rmrf($project);
        }
    }

    #[Test]
    public function aFileThatDoesNotDeclareTheClassItsNameimpliesIsReported(): void
    {
        $dir = self::tempDir();

        try {
            file_put_contents($dir . '/Mismatched.php', "<?php\n\nnamespace ClawWorkflow\\Library;\n\nclass SomethingElse {}\n");

            $threw = false;

            try {
                WorkflowStore::library($dir)->catalogue();
            } catch (WorkflowException $e) {
                $threw = str_contains($e->getMessage(), 'does not declare');
            }

            Assert::true($threw);
        } finally {
            self::rmrf($dir);
        }
    }

    #[Test]
    public function theLibraryThatShipsWithClawIsWholeAndOffersWhatItSays(): void
    {
        // The guard for everything added to workflows/ from here on. `catalogue()` refuses an entry that
        // cannot be chosen — no docblock to judge it by, no type it serves, a class not named after its
        // file — so a workflow added carelessly fails here rather than being quietly invisible, or worse,
        // visible with nothing to pick it by.
        $shipped = WorkflowStore::library(\dirname(__DIR__, 2) . '/workflows');
        $catalogue = $shipped->catalogue();

        Assert::true($catalogue !== []);

        foreach ($catalogue as $entry) {
            Assert::true(trim($entry['description']) !== '');
            Assert::true($entry['serves'] !== []);
            Assert::true(class_exists($entry['class']));
        }

        // And the type filter really reaches the shipped shelf: the bug workflow is on offer for a bug
        // and absent from a kind of work it does not do.
        Assert::true(isset(WorkflowStore::offered([$shipped], IssueType::Bug)['FixBugWorkflow']));
        Assert::false(isset(WorkflowStore::offered([$shipped], IssueType::Research)['FixBugWorkflow']));

        // The shelf holds two kinds now, and the kind decides which verdict may name the entry: a
        // workflow is run as written, an approach is prose a solver is generated to follow. An entry
        // reported under the wrong kind would be recordable and unroutable — the exact defect that made
        // `library` quietly generate a bespoke solver before it was given an arm of its own.
        $bug = WorkflowStore::offered([$shipped], IssueType::Bug)['FixBugWorkflow'];
        Assert::same($bug['kind'], 'workflow');
        Assert::same($bug['recipe'], '');   // a workflow carries no recipe: its steps are the procedure

        $feature = WorkflowStore::offered([$shipped], IssueType::Feature);
        Assert::true(isset($feature['BuildFeatureStrategy']));
        Assert::same($feature['BuildFeatureStrategy']['kind'], 'strategy');
        Assert::true(str_contains($feature['BuildFeatureStrategy']['recipe'], 'BUILDING A FEATURE'));
        Assert::false(isset($feature['FixBugWorkflow']));   // still filtered by type
    }

    /**
     * Write a workflow file into a library folder. $serves is the attribute's argument list verbatim —
     * unpacking is not allowed in an attribute, so the cases are spelled out — or null for a class that
     * carries no attribute at all.
     */
    private static function writeWorkflow(string $dir, string $namespace, string $name, ?string $serves, string $description): void
    {
        $doc = $description === '' ? '' : "/**\n * {$description}\n */\n";
        $attribute = $serves === null ? '' : "#[LibraryWorkflow({$serves})]\n";

        file_put_contents($dir . '/' . $name . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace ClawWorkflow\\{$namespace};

            use Claw\\Project\\IssueType;
            use Claw\\Workflow\\LibraryWorkflow;

            {$doc}{$attribute}final class {$name}
            {
            }

            PHP);
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-library-' . uniqid('', true);
        mkdir($dir, 0o775, true);

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $entry) {
            @unlink((string) $entry);
        }

        @rmdir($dir);
    }
}
