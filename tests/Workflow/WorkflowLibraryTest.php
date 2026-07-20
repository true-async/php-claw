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
 */
final class WorkflowLibraryTest
{
    #[Test]
    public function theCatalogueListsOnlyWhatWasMarkedAsChoosable(): void
    {
        $dir = self::tempDir();

        try {
            self::writeWorkflow($dir, 'Library', 'FixTheBug', 'IssueType::Bug', 'Reproduces a defect, pins it with a failing test, then fixes it.');
            // A base class or a helper in the same folder is not an option on the shelf.
            self::writeWorkflow($dir, 'Library', 'SharedHelper', null, 'Something the others use.');

            $catalogue = WorkflowStore::library($dir)->catalogue();

            Assert::count($catalogue, 1);
            Assert::same($catalogue[0]['name'], 'FixTheBug');
            Assert::same($catalogue[0]['class'], 'ClawWorkflow\Library\FixTheBug');
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
            self::writeWorkflow($dir, 'Library', 'FixTheBug', 'IssueType::Bug', 'Fixes a defect.');
            self::writeWorkflow($dir, 'Library', 'LookIntoIt', 'IssueType::Research, IssueType::Design', 'Reads around and reports.');

            $forBugs = WorkflowStore::offered([WorkflowStore::library($dir)], IssueType::Bug);
            $forResearch = WorkflowStore::offered([WorkflowStore::library($dir)], IssueType::Research);
            $forFeatures = WorkflowStore::offered([WorkflowStore::library($dir)], IssueType::Feature);

            Assert::same(array_keys($forBugs), ['FixTheBug']);
            Assert::same(array_keys($forResearch), ['LookIntoIt']);
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
