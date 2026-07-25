<?php

declare(strict_types=1);

namespace Tests\Run;

use Claw\Agent\AgentResponse;
use Claw\Agent\Message;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\ToolUseBlock;
use Claw\Agent\Usage;
use Claw\Config;
use Claw\Project\ProjectStore;
use Claw\Project\Strategy;
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

            Assert::true(str_contains(self::firstUserText($agent), 'The shelf holds nothing for this kind of ticket'));
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
     * A verdict recorded before the loop died is still the verdict.
     *
     * Seen live under four concurrent triages: the model recorded the strategy in its first turn,
     * then the NEXT model call died. The catch returned null past a verdict already in force, and
     * the CLI told the operator nothing was recorded while the ledger said otherwise — and the
     * swallowed exception appeared nowhere, so "the model never decided" and "the transport died"
     * were indistinguishable from outside.
     */
    #[Test]
    public function aVerdictRecordedBeforeTheLoopDiedIsStillTheVerdict(): void
    {
        $this->withProject(function (ProjectStore $store, Config $config): void {
            $issue = $store->addIssue('something is broken');
            $record = new ToolUseBlock('t1', 'project_manager', [
                'action' => 'set_strategy',
                'issue' => $issue->id,
                'type' => 'feature',
                'strategy' => 'direct',
                'reason' => 'small and clear',
            ]);
            $agent = new ScriptedAgent(
                new AgentResponse([$record], [$record], StopReason::ToolUse, new Usage(1, 1)),
                new \RuntimeException('the transport died mid-loop'),
            );

            $strategy = new Triage($store, $config, $agent)->analyse($issue);

            Assert::same(Strategy::Direct, $strategy);

            $tree = new TraceReader($store->pdo())->render(Triage::traceId($issue));
            Assert::true(str_contains($tree, 'the transport died mid-loop'));
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

    /**
     * The verdict has a tie-break, and the type does not prescribe a procedure.
     *
     * Both were found by a critic and then confirmed live. The `bug` type used to read "the work has a
     * fixed shape: reproduce it, pin it with a failing test, fix it, watch the test go green" — a
     * procedure, which only a shelf entry or a generated solver can provide, so a model reading it
     * seriously could never choose `direct` for a bug. Two lines later the same prompt said to judge the
     * type from what the ticket asks for and not from its size, and called `direct` the default for
     * anything small. The two pulled opposite ways.
     *
     * Live evidence: a ticket whose fix was one expression — a lost unary minus — came back `library`.
     *
     * And "anything small" is an adjective, not a test. Two verdicts could fit one ticket with nothing
     * saying which won.
     */
    #[Test]
    public function theStrategyListSaysHowToChooseBetweenTwoThatFit(): void
    {
        $this->withProject(function (ProjectStore $store, Config $config): void {
            $issue = $store->addIssue('something small is broken');
            $agent = new ScriptedAgent(
                new AgentResponse([new TextBlock('thinking')], [], StopReason::EndTurn, new Usage(1, 1), 'thinking'),
                new AgentResponse([new TextBlock('thinking')], [], StopReason::EndTurn, new Usage(1, 1), 'thinking'),
            );

            new Triage($store, $config, $agent)->analyse($issue);
            $system = $agent->requests[0]->system;

            // An operational test for `direct`, not an adjective: can one agent, in one continuous
            // context, carry it from start to finish.
            Assert::true(str_contains($system, 'ONE agent, in ONE continuous context'));

            // And a stated order for when two verdicts both fit, which is the common case for a small
            // bug: `direct` and `library` would each have been defensible.
            Assert::true(str_contains($system, 'CHEAPEST FIRST'));
            Assert::true(str_contains($system, 'when two fit, take the earlier one'));

            // The type describes the ticket; it does not smuggle in a procedure.
            Assert::false(str_contains($system, 'pin it with a failing test'));
            Assert::true(str_contains($system, 'The type never decides the strategy'));
        });
    }

    /**
     * Workability is judged by whether the ticket can be ACTED ON, not by whether it parses.
     *
     * The taxonomy used to list only degenerate cases — nonsense, a bare word, self-contradiction, an
     * absent subject. The tickets that actually waste a solver read perfectly well: "make it faster"
     * with no target, a request with two readings that mean different work, or a request for behaviour
     * the project already has, where a solver happily "implements" what exists, watches the tests pass
     * and closes the ticket having changed nothing.
     *
     * And the escape hatch had to widen without becoming a shrug: one reasonable reading is judgement,
     * which is the job. Parking is for when no reading survives contact with the code.
     */
    #[Test]
    public function aTicketThatReadsWellButCannotBeFinishedIsStillUnworkable(): void
    {
        $this->withProject(function (ProjectStore $store, Config $config): void {
            $issue = $store->addIssue('make it faster');
            $agent = new ScriptedAgent(
                new AgentResponse([new TextBlock('thinking')], [], StopReason::EndTurn, new Usage(1, 1), 'thinking'),
                new AgentResponse([new TextBlock('thinking')], [], StopReason::EndTurn, new Usage(1, 1), 'thinking'),
            );

            new Triage($store, $config, $agent)->analyse($issue);
            $system = $agent->requests[0]->system;

            Assert::true(str_contains($system, 'HOW ANYONE WOULD KNOW it was done'));
            Assert::true(str_contains($system, 'no observable criterion'));
            Assert::true(str_contains($system, 'behaviour the project ALREADY HAS'));

            // Judgement, not a shrug: one reasonable reading is taken and named.
            Assert::true(str_contains($system, 'take it and say in your reason which reading you took'));

            // And the one-call rule counts ACCEPTED verdicts, not calls — the tool refuses by design,
            // so a model that made one refused call had satisfied the old wording while settling nothing.
            Assert::true(str_contains($system, 'has been ACCEPTED'));
            Assert::true(str_contains($system, 'A REFUSED call settles nothing'));
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
