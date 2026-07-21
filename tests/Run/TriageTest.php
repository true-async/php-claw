<?php

declare(strict_types=1);

namespace Tests\Run;

use Claw\Agent\AgentResponse;
use Claw\Agent\Message;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\Usage;
use Claw\Config;
use Claw\Project\ProjectStore;
use Claw\Run\Triage;
use Claw\Trace\TraceReader;
use Testo\Assert;
use Testo\Test;
use Tests\Support\ScriptedAgent;

final class TriageTest
{
    #[Test]
    public function theProjectManagerIsSHOWNTheShelfRatherThanHavingToAskForIt(): void
    {
        // Measured on a traced triage: `list_workflows` was never called, so `library` could never be
        // chosen. The reason is circular — the prompt said to call the tool before choosing library,
        // but a model with no reason to believe anything is on the shelf never chooses library, so it
        // never calls the tool. A capability reachable only by asking for it is one nobody asks for.
        $this->withProject(function (ProjectStore $store, Config $config, string $library): void {
            self::writeWorkflow($library, 'TrialBugFix', 'IssueType::Bug', 'Reproduces a defect and fixes it.');
            $issue = $store->addIssue('something is broken');

            // Two responses: it answers in prose both times, so nothing is recorded — this test is
            // about what the ProjectManager was SHOWN, not what it decided.
            $agent = new ScriptedAgent(
                new AgentResponse([new TextBlock('thinking')], [], StopReason::EndTurn, new Usage(1, 1), 'thinking'),
                new AgentResponse([new TextBlock('still thinking')], [], StopReason::EndTurn, new Usage(1, 1), 'still thinking'),
            );

            new Triage($store, $config, $agent)->analyse($issue);

            $brief = self::firstUserText($agent);
            Assert::true(str_contains($brief, 'TrialBugFix'));                    // the workflow is named
            Assert::true(str_contains($brief, 'for: bug'));                       // and what it serves
            Assert::true(str_contains($brief, 'Reproduces a defect and fixes it.'));
        });
    }

    #[Test]
    public function anEmptyShelfSaysSoRatherThanSayingNothing(): void
    {
        // Silence would read as "there might be something"; a model would then pick `library` and be
        // refused when recording it, having spent an exchange to learn what it could have been told.
        $this->withProject(function (ProjectStore $store, Config $config): void {
            $issue = $store->addIssue('something is broken');
            $agent = new ScriptedAgent(
                new AgentResponse([new TextBlock('thinking')], [], StopReason::EndTurn, new Usage(1, 1), 'thinking'),
                new AgentResponse([new TextBlock('thinking')], [], StopReason::EndTurn, new Usage(1, 1), 'thinking'),
            );

            new Triage($store, $config, $agent)->analyse($issue);

            Assert::true(str_contains(self::firstUserText($agent), 'none exist yet'));
        });
    }

    #[Test]
    public function theAnalysisIsRecordedSoTheDecisionCanBeReadBackLater(): void
    {
        // The costliest decision in the system used to leave no trace at all: whether the shelf was
        // opened, what was read, why it chose as it did. Every defect above was found by reading this.
        $this->withProject(function (ProjectStore $store, Config $config): void {
            $issue = $store->addIssue('something is broken');
            $agent = new ScriptedAgent(
                new AgentResponse([new TextBlock('a considered answer')], [], StopReason::EndTurn, new Usage(1, 1), 'a considered answer'),
                new AgentResponse([new TextBlock('a considered answer')], [], StopReason::EndTurn, new Usage(1, 1), 'a considered answer'),
            );

            new Triage($store, $config, $agent)->analyse($issue);

            $tree = new TraceReader($store->pdo())->render(Triage::traceId($issue));
            Assert::true(str_contains($tree, 'triage'));
            Assert::true(str_contains($tree, 'a considered answer'));
        });
    }

    /**
     * A re-triage must not be told to use a tool it was not given.
     *
     * `RecallTool` is registered only when `analyse()` is handed the failed run's id, and two of the
     * three doors do not pass one — the dashboard and the CLI both call `analyse($issue)`. The failure
     * history fires on any failed attempt, so it used to instruct a near-mandatory `recall` call ("and
     * it usually does") into a palette that had no such tool.
     */
    #[Test]
    public function theFailedRunIsOfferedOnlyWhereRecallIsActuallyOnThePalette(): void
    {
        $this->withProject(function (ProjectStore $store, Config $config): void {
            $issue = $store->addIssue('something that has been tried before');
            $store->setStrategy($issue->id, \Claw\Project\Strategy::Direct, 'small', false);
            $store->failStrategy($issue->id, 'the tests stayed red');

            $agent = new ScriptedAgent(
                new AgentResponse([new TextBlock('thinking')], [], StopReason::EndTurn, new Usage(1, 1), 'thinking'),
                new AgentResponse([new TextBlock('thinking')], [], StopReason::EndTurn, new Usage(1, 1), 'thinking'),
            );

            new Triage($store, $config, $agent)->analyse($issue);   // no run id -> no recall tool

            $brief = self::firstUserText($agent);
            Assert::true(str_contains($brief, 'the tests stayed red'));   // the history is still shown
            Assert::false(str_contains($brief, 'recall'));                // but the tool is not offered
        });
    }

    /** The ticket brief — the first user message the ProjectManager was handed. */
    private static function firstUserText(ScriptedAgent $agent): string
    {
        $text = '';

        foreach ($agent->requests[0]->messages as $message) {
            foreach ($message->content as $block) {
                if ($block instanceof TextBlock) {
                    $text .= $block->text;
                }
            }
        }

        return $text;
    }

    private static function writeWorkflow(string $dir, string $name, string $serves, string $description): void
    {
        file_put_contents($dir . '/' . $name . '.php', <<<PHP
            <?php

            namespace ClawWorkflow\\Library;

            use Claw\\Project\\IssueType;
            use Claw\\Workflow\\LibraryWorkflow;

            /**
             * {$description}
             */
            #[LibraryWorkflow({$serves})]
            final class {$name}
            {
            }

            PHP);
    }

    /** A registered project, a config pointing at an empty global library, and that library's path. */
    private function withProject(callable $body): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();
        $library = self::tempDir();

        try {
            ProjectStore::init($projectsDir, $folder);
            $store = ProjectStore::discover($projectsDir, $folder)
                ?? throw new \RuntimeException('the project just registered was not discoverable');

            $envFile = $projectsDir . '/test.env';
            file_put_contents(
                $envFile,
                "CLAW_AGENT=claude\nANTHROPIC_API_KEY=k\nCLAW_MODEL=test-model\nCLAW_LIBRARY={$library}\n",
            );

            $body($store, Config::load($envFile), $library);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
            self::rmrf($library);
        }
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-triage-' . uniqid('', true);
        mkdir($dir, 0o775, true);

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rmrf($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
