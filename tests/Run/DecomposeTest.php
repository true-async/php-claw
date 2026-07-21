<?php

declare(strict_types=1);

namespace Tests\Run;

use Claw\Agent\AgentResponse;
use Claw\Agent\StopReason;
use Claw\Agent\TextBlock;
use Claw\Agent\Usage;
use Claw\Config;
use Claw\Project\ProjectStore;
use Claw\Run\Decompose;
use Testo\Assert;
use Testo\Test;
use Tests\Support\ScriptedAgent;

final class DecomposeTest
{
    /**
     * The model is told what the caps ARE, and told them from the constants that enforce them.
     *
     * It used to be told neither. `ProjectStore` refuses a ninth child and a third level, and the prompt
     * said only "if a call is refused because a cap was reached, stop — the remaining work belongs in the
     * pieces you already opened". That last sentence is not something a model can make true: no action
     * edits a sub-issue once it exists, so the tail of the ticket was dropped, by instruction. A model
     * that knows the limit can count before its first call instead of discovering it halfway through.
     */
    #[Test]
    public function theSplitterIsToldTheRealCapsItWillBeHeldTo(): void
    {
        $this->withProject(function (ProjectStore $store, Config $config): void {
            $issue = $store->addIssue('a ticket too big for one run');
            $agent = new ScriptedAgent(self::says('thinking'));

            new Decompose($store, $config, $agent)->split($issue);

            $system = $agent->requests[0]->system ?? '';
            Assert::true(str_contains($system, 'AT MOST ' . ProjectStore::MAX_CHILDREN . ' SUB-ISSUES'));
            Assert::true(str_contains($system, 'at most ' . ProjectStore::MAX_DEPTH . ' deep'));

            // And the sentence that told it the leftovers were already covered is gone.
            Assert::false(str_contains($system, 'belongs in the pieces you already opened'));
        });
    }

    /**
     * A piece has an upper bound as well as a lower one. The good-piece checklist said a piece must be
     * worth a ticket and finishable alone, and never that it must fit in ONE RUN — which is the whole
     * reason this pass exists. Combined with "prefer FEWER, larger pieces", the cheapest compliant split
     * was two enormous halves, each of which is judged too big in its turn.
     */
    #[Test]
    public function aPieceMustAlsoBeSmallEnoughToSolveInOneRun(): void
    {
        $this->withProject(function (ProjectStore $store, Config $config): void {
            $issue = $store->addIssue('a ticket too big for one run');
            $agent = new ScriptedAgent(self::says('thinking'));

            new Decompose($store, $config, $agent)->split($issue);

            Assert::true(str_contains($agent->requests[0]->system ?? '', 'SOLVED IN ONE RUN'));
        });
    }

    /**
     * The splitter is given ONE action, and refused the rest — not asked politely to avoid them.
     *
     * It opens sub-issues; each is triaged afterwards by a pass that has the shelf and the escalation
     * ledger this one does not. Handed the whole `project_manager` surface — which its own description
     * advertises — a model that has just opened eight tickets is one helpful impulse from setting
     * strategies on them, closing the parent, or parking it when the split feels hard. None of those
     * are decisions it knows enough to make here, and a rule in a prompt is not what stops it.
     */
    #[Test]
    public function theSplitterIsOfferedOnlyTheActionItNeeds(): void
    {
        $this->withProject(function (ProjectStore $store, Config $config): void {
            $issue = $store->addIssue('a ticket too big for one run');
            $agent = new ScriptedAgent(self::says('thinking'));

            new Decompose($store, $config, $agent)->split($issue);

            // The schema hides everything else, so the model is not told the others exist.
            $spec = $agent->requests[0]->tools[0] ?? null;
            Assert::true($spec !== null);
            Assert::same($spec->inputSchema['properties']['action']['enum'] ?? null, ['create_issue']);

            // And an action remembered from another pass is refused at the door, not merely unlisted.
            $tool = new \Claw\Tool\ProjectManagerTool($store, only: ['create_issue']);
            $refused = '';

            try {
                $tool->handle(['action' => 'close_issue', 'issue' => $issue->id]);
            } catch (\Claw\Exceptions\ToolException $e) {
                $refused = $e->getMessage();
            }

            Assert::true(str_contains($refused, 'not available in this pass'));

            // The one it does need still works.
            $tool->handle(['action' => 'create_issue', 'title' => 'a piece', 'parent' => $issue->id]);
            Assert::count($store->childIssues($issue->id), 1);
        });
    }

    private static function says(string $text): AgentResponse
    {
        return new AgentResponse([new TextBlock($text)], [], StopReason::EndTurn, new Usage(1, 1), $text);
    }

    /** A registered project and a config pointing at it. */
    private function withProject(callable $body): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            ProjectStore::init($projectsDir, $folder);
            $store = ProjectStore::discover($projectsDir, $folder)
                ?? throw new \RuntimeException('the project just registered was not discoverable');

            $envFile = $projectsDir . '/test.env';
            file_put_contents($envFile, "CLAW_AGENT=claude\nANTHROPIC_API_KEY=k\nCLAW_MODEL=test-model\n");

            $body($store, Config::load($envFile));
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-decompose-' . uniqid('', true);
        mkdir($dir, 0o775, true);

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rmrf($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
