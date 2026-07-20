<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ClawException;
use Claw\Exceptions\ToolException;
use Claw\Permission\Decision;
use Claw\Permission\Policy;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStore;
use Claw\Project\Strategy;
use Claw\Project\StrategyOutcome;
use Claw\Tool\ProjectManagerTool;
use Claw\Tool\Risk;
use Testo\Assert;
use Testo\Test;

final class ProjectManagerToolTest
{
    #[Test]
    public function createIssueOpensATicketAndASubIssueUnderItsParent(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);

            $tool->handle(['action' => 'create_issue', 'title' => 'big thing', 'description' => 'the brief']);
            $tool->handle(['action' => 'create_issue', 'title' => 'part one', 'parent' => '1']);

            $root = $store->loadIssue('1');
            Assert::same($root->title, 'big thing');
            Assert::same($root->description, 'the brief');
            Assert::same($root->depth, 0);

            $child = $store->loadIssue('2');
            Assert::same($child->parent, '1');
            Assert::same($child->depth, 1);
        });
    }

    #[Test]
    public function decompositionIsRefusedOnceItHitsTheDepthCap(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);

            // Depths 0,1,2 are allowed; a child of depth 2 would be depth 3 and is refused.
            $tool->handle(['action' => 'create_issue', 'title' => 'd0']);
            $tool->handle(['action' => 'create_issue', 'title' => 'd1', 'parent' => '1']);
            $tool->handle(['action' => 'create_issue', 'title' => 'd2', 'parent' => '2']);
            Assert::same($store->loadIssue('3')->depth, ProjectStore::MAX_DEPTH);

            $refusal = $this->refusal($tool, ['action' => 'create_issue', 'title' => 'd3', 'parent' => '3']);

            Assert::true(str_contains($refusal, 'limit is ' . ProjectStore::MAX_DEPTH));
            // The refusal must say what to do instead, or the model just retries the same call.
            Assert::true(str_contains($refusal, 'direct, library or generate'));
            Assert::count($store->childIssues('3'), 0);   // nothing half-created
        });
    }

    #[Test]
    public function theCapsHoldWhenTheStoreIsReachedDirectlyRatherThanThroughTheTool(): void
    {
        // The caps are the STORE's, not the tool's: the CLI and the dashboard open issues through the
        // same addIssue(). A bound only the tool honoured would be no bound at all.
        $this->withStore(function (ProjectStore $store): void {
            $root = $store->addIssue('root');
            $child = $store->addIssue('child', '', $root->id);
            $grandchild = $store->addIssue('grandchild', '', $child->id);

            $threw = false;

            try {
                $store->addIssue('too deep', '', $grandchild->id);
            } catch (ClawException $e) {
                $threw = str_contains($e->getMessage(), 'limit is ' . ProjectStore::MAX_DEPTH);
            }

            Assert::true($threw);
            Assert::count($store->childIssues($grandchild->id), 0);
        });
    }

    #[Test]
    public function decompositionIsRefusedOnceItHitsTheBreadthCap(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'root']);

            for ($i = 1; $i <= 8; $i++) {
                $tool->handle(['action' => 'create_issue', 'title' => "part {$i}", 'parent' => '1']);
            }

            $refusal = $this->refusal($tool, ['action' => 'create_issue', 'title' => 'part 9', 'parent' => '1']);

            Assert::true(str_contains($refusal, 'limit is ' . ProjectStore::MAX_CHILDREN));
            Assert::count($store->childIssues('1'), ProjectStore::MAX_CHILDREN);
        });
    }

    #[Test]
    public function creatingASubIssueUnderAMissingParentIsRefusedAndWritesNothing(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);

            $refusal = $this->refusal($tool, ['action' => 'create_issue', 'title' => 'orphan', 'parent' => '404']);

            Assert::true(str_contains($refusal, 'not found'));
            Assert::count($store->allIssues(), 0);   // the rollback left nothing behind
        });
    }

    #[Test]
    public function aCallMissingItsRequiredArgumentIsRefusedReadably(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);

            Assert::true(str_contains(
                $this->refusal($tool, ['action' => 'create_issue']),
                "needs a 'title'",
            ));
            Assert::true(str_contains(
                $this->refusal($tool, ['action' => 'set_strategy', 'strategy' => 'direct', 'reason' => 'x']),
                "needs an 'issue' id",
            ));
            Assert::true(str_contains(
                $this->refusal($tool, ['action' => 'demolish_everything']),
                "unknown action 'demolish_everything'",
            ));
            Assert::true(str_contains($this->refusal($tool, []), "unknown action ''"));
        });
    }

    #[Test]
    public function setStrategyRecordsTheVerdictAndRejectsAnUnknownOne(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'a task']);

            $tool->handle([
                'action' => 'set_strategy',
                'issue' => '1',
                'strategy' => 'decompose',
                'reason' => 'too big for one run',
                'needs_human' => true,
            ]);

            $current = $store->currentStrategy('1');
            Assert::true($current !== null);
            Assert::same($current['strategy'], Strategy::Decompose);
            Assert::same($current['reason'], 'too big for one run');
            Assert::true($current['needsHuman']);

            // A typo is an error the model reads back and corrects, not a silent default.
            Assert::true(str_contains(
                $this->refusal($tool, ['action' => 'set_strategy', 'issue' => '1', 'strategy' => 'wing-it', 'reason' => 'x']),
                "unknown strategy 'wing-it'",
            ));

            // The verdict is reviewed, so it has to carry a justification.
            Assert::true(str_contains(
                $this->refusal($tool, ['action' => 'set_strategy', 'issue' => '1', 'strategy' => 'direct']),
                "needs a 'reason'",
            ));
        });
    }

    #[Test]
    public function reportFailureReopensTheIssueAndKeepsBothReasonsInTheLedger(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'a task']);
            $tool->handle(['action' => 'set_strategy', 'issue' => '1', 'strategy' => 'direct', 'reason' => 'looks small']);
            $store->setIssueStatus('1', IssueStatus::InProgress);

            $out = $tool->handle(['action' => 'report_failure', 'issue' => '1', 'reason' => 'needs a real parser']);

            Assert::true(str_contains($out, 'direct'));                            // names what failed
            Assert::same($store->loadIssue('1')->status, IssueStatus::Open);       // open for triage again

            $attempts = $store->strategyAttempts('1');
            Assert::count($attempts, 1);
            Assert::same($attempts[0]['outcome'], StrategyOutcome::Failed);
            // BOTH reasons survive: the next escalation is chosen from what we expected AND what broke.
            Assert::same($attempts[0]['reason'], 'looks small');
            Assert::same($attempts[0]['outcomeReason'], 'needs a real parser');

            // A second verdict is appended, so the history a retry escalates from survives.
            $tool->handle(['action' => 'set_strategy', 'issue' => '1', 'strategy' => 'generate', 'reason' => 'direct failed']);
            $attempts = $store->strategyAttempts('1');
            Assert::count($attempts, 2);
            Assert::same($attempts[0]['strategy'], Strategy::Direct);
            Assert::same($attempts[1]['strategy'], Strategy::Generate);
            Assert::same($attempts[1]['outcome'], StrategyOutcome::Pending);
        });
    }

    #[Test]
    public function aSecondFailureReportDoesNotRewriteTheFirstOne(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'a task']);
            $tool->handle(['action' => 'set_strategy', 'issue' => '1', 'strategy' => 'direct', 'reason' => 'small']);
            $tool->handle(['action' => 'report_failure', 'issue' => '1', 'reason' => 'the first failure']);

            $out = $tool->handle(['action' => 'report_failure', 'issue' => '1', 'reason' => 'a stray second report']);

            Assert::true(str_contains($out, 'no strategy in force'));
            $attempts = $store->strategyAttempts('1');
            Assert::count($attempts, 1);
            Assert::same($attempts[0]['outcomeReason'], 'the first failure');   // not overwritten
        });
    }

    #[Test]
    public function aRetryMustEscalatePastTheStrategyThatFailed(): void
    {
        // The rule was documented in three places and enforced in none, which made it a suggestion to
        // the model rather than a bound. Repeating a failed strategy fails the same way.
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'a task']);
            $tool->handle(['action' => 'set_strategy', 'issue' => '1', 'strategy' => 'generate', 'reason' => 'bespoke']);
            $tool->handle(['action' => 'report_failure', 'issue' => '1', 'reason' => 'the solver kept crashing']);

            // The same strategy again, and a cheaper one, are both refused...
            $again = $this->refusal($tool, ['action' => 'set_strategy', 'issue' => '1', 'strategy' => 'generate', 'reason' => 'one more go']);
            Assert::true(str_contains($again, 'does not escalate past'));
            Assert::true(str_contains($again, 'the solver kept crashing'));   // says what broke last time

            Assert::true(str_contains(
                $this->refusal($tool, ['action' => 'set_strategy', 'issue' => '1', 'strategy' => 'direct', 'reason' => 'try small']),
                'does not escalate past',
            ));

            // ...and only a strategy that does MORE is accepted.
            $tool->handle(['action' => 'set_strategy', 'issue' => '1', 'strategy' => 'decompose', 'reason' => 'split it']);
            $current = $store->currentStrategy('1');
            Assert::true($current !== null);
            Assert::same($current['strategy'], Strategy::Decompose);
        });
    }

    #[Test]
    public function needsHumanMovesTheTicketToWaitingHumanRatherThanJustBeingNoted(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'a task']);

            $tool->handle([
                'action' => 'set_strategy', 'issue' => '1', 'strategy' => 'decompose',
                'reason' => 'too big', 'needs_human' => true,
            ]);

            // The flag has to DO something, or it is a field nobody reads. WaitingHuman already means
            // exactly this, so the ticket lands in the column a person actually looks at.
            Assert::same($store->loadIssue('1')->status, IssueStatus::WaitingHuman);
        });
    }

    #[Test]
    public function theEscalationLadderEndsAtAPersonWithNoRoundCounter(): void
    {
        // Nothing counts retries. The store refuses any strategy that does not escalate past a failed
        // one, so once `decompose` — the top rank — has failed there is nothing left to escalate to,
        // and handing the ticket to a person is the only verdict that can still be recorded. The
        // ladder ends itself; a counter would be a second, weaker way of saying the same thing.
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'a task']);

            foreach (['direct', 'generate', 'decompose'] as $strategy) {
                $tool->handle(['action' => 'set_strategy', 'issue' => '1', 'strategy' => $strategy, 'reason' => 'next rung']);
                $tool->handle(['action' => 'report_failure', 'issue' => '1', 'reason' => "{$strategy} did not work"]);
            }

            // Every rung is now spent — nothing escalates past decompose, including decompose itself.
            foreach (['direct', 'library', 'generate', 'decompose'] as $strategy) {
                Assert::true(str_contains(
                    $this->refusal($tool, ['action' => 'set_strategy', 'issue' => '1', 'strategy' => $strategy, 'reason' => 'again']),
                    'does not escalate past',
                ));
            }

            // The one move left is the person, and it is still available.
            $store->setIssueStatus('1', IssueStatus::WaitingHuman);
            Assert::same($store->loadIssue('1')->status, IssueStatus::WaitingHuman);
        });
    }

    #[Test]
    public function aFailureReportNeverResurrectsATicketAPersonClosed(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'a task']);
            $tool->handle(['action' => 'set_strategy', 'issue' => '1', 'strategy' => 'direct', 'reason' => 'small']);
            $store->setIssueStatus('1', IssueStatus::Closed);   // a person ruled won't-fix

            $tool->handle(['action' => 'report_failure', 'issue' => '1', 'reason' => 'a straggler run reporting in']);

            Assert::same($store->loadIssue('1')->status, IssueStatus::Closed);
            // the attempt is still recorded — only the status is left alone
            Assert::same($store->strategyAttempts('1')[0]['outcome'], StrategyOutcome::Failed);
        });
    }

    #[Test]
    public function needsHumanReadsAStringifiedBooleanTheWayTheModelMeantIt(): void
    {
        // Models send "false" as a STRING despite the schema, and (bool) "false" is true — which would
        // silently flip whether a person is asked to approve.
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'a task']);

            $tool->handle([
                'action' => 'set_strategy', 'issue' => '1', 'strategy' => 'direct',
                'reason' => 'small', 'needs_human' => 'false',
            ]);
            $current = $store->currentStrategy('1');
            Assert::true($current !== null);
            Assert::false($current['needsHuman']);

            // and omitting it defaults to false
            $tool->handle([
                'action' => 'set_strategy', 'issue' => '1', 'strategy' => 'generate', 'reason' => 'bigger',
            ]);
            $current = $store->currentStrategy('1');
            Assert::true($current !== null);
            Assert::false($current['needsHuman']);
        });
    }

    #[Test]
    public function aCorruptLedgerRowComesBackAsARefusalNotAnUncaughtCrash(): void
    {
        // Only ToolException becomes a tool result; anything else escaping handle() kills the run.
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'a task']);
            $store->pdo()->exec(
                "INSERT INTO issue_strategy (issue_id, strategy, reason, needs_human, outcome, created_at)
                 VALUES (1, 'wing-it', 'hand-edited', 0, 'pending', 1)",
            );

            Assert::true(str_contains(
                $this->refusal($tool, ['action' => 'report_failure', 'issue' => '1', 'reason' => 'x']),
                "corrupt strategy 'wing-it'",
            ));
        });
    }

    #[Test]
    public function aParentIsHeldOpenWhileAnySiblingIsStillInFlight(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'root']);
            $tool->handle(['action' => 'create_issue', 'title' => 'first', 'parent' => '1']);
            $tool->handle(['action' => 'create_issue', 'title' => 'second', 'parent' => '1']);

            $tool->handle(['action' => 'close_issue', 'issue' => '2']);

            Assert::same($store->loadIssue('1')->status, IssueStatus::Open);   // 'second' is still open
        });
    }

    #[Test]
    public function aParentWhoseChildrenWereAllAbandonedIsClosedNotReportedDone(): void
    {
        // Every part closed as won't-fix must not add up to a parent that claims the work was done.
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'root']);
            $tool->handle(['action' => 'create_issue', 'title' => 'only part', 'parent' => '1']);

            $tool->handle(['action' => 'close_issue', 'issue' => '2', 'reason' => 'not worth it']);

            Assert::same($store->loadIssue('2')->status, IssueStatus::Closed);
            Assert::same($store->loadIssue('1')->status, IssueStatus::Closed);

            // But a child that genuinely landed settles the parent as Done.
            $tool->handle(['action' => 'create_issue', 'title' => 'other root']);
            $tool->handle(['action' => 'create_issue', 'title' => 'real work', 'parent' => '3']);
            $store->setIssueStatus('4', IssueStatus::Done);
            $store->settleAncestors('4');
            Assert::same($store->loadIssue('3')->status, IssueStatus::Done);
        });
    }

    #[Test]
    public function reopeningASubIssueUnsettlesTheParentItHadSettled(): void
    {
        // Otherwise the parent stays reported finished while live work sits under it.
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);
            $tool->handle(['action' => 'create_issue', 'title' => 'root']);
            $tool->handle(['action' => 'create_issue', 'title' => 'middle', 'parent' => '1']);
            $tool->handle(['action' => 'create_issue', 'title' => 'leaf', 'parent' => '2']);

            $store->setIssueStatus('3', IssueStatus::Done);
            $store->settleAncestors('3');
            Assert::same($store->loadIssue('2')->status, IssueStatus::Done);
            Assert::same($store->loadIssue('1')->status, IssueStatus::Done);

            $tool->handle(['action' => 'reopen_issue', 'issue' => '3']);

            Assert::same($store->loadIssue('3')->status, IssueStatus::Open);
            Assert::same($store->loadIssue('2')->status, IssueStatus::Open);   // walked the whole chain
            Assert::same($store->loadIssue('1')->status, IssueStatus::Open);
        });
    }

    #[Test]
    public function anActionOnAMissingIssueIsRefusedRatherThanWrittenNowhere(): void
    {
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);

            $refusal = $this->refusal($tool, [
                'action' => 'set_strategy', 'issue' => '404', 'strategy' => 'direct', 'reason' => 'x',
            ]);

            Assert::true(str_contains($refusal, 'not found'));
            Assert::count($store->strategyAttempts('404'), 0);
        });
    }

    #[Test]
    public function theToolIsMutatingSoADecompositionMeetsThePermissionGate(): void
    {
        // The "a person approves a decomposition" rule is not a mechanism of its own: it is this risk
        // level meeting the Policy that already exists. If this ever became Safe, that rule would
        // silently vanish, so it is asserted rather than assumed.
        $this->withStore(function (ProjectStore $store): void {
            $tool = new ProjectManagerTool($store);

            Assert::same($tool->risk(), Risk::Mutating);
            Assert::same(new Policy()->check($tool, ['action' => 'create_issue'])->decision, Decision::Confirm);
        });
    }

    /** Run $body against a real store in a throwaway project, cleaning up afterwards. */
    private function withStore(callable $body): void
    {
        $projectsDir = self::tempDir();
        $folder = self::tempDir();

        try {
            ProjectStore::init($projectsDir, $folder);
            $store = ProjectStore::discover($projectsDir, $folder);

            if ($store === null) {
                throw new \RuntimeException('the project just initialized could not be discovered');
            }

            $body($store);
        } finally {
            self::rmrf($projectsDir);
            self::rmrf($folder);
        }
    }

    /**
     * The message a refused call came back with — the tool reports a refusal by throwing, which the
     * executor turns into a tool result the model reads.
     *
     * @param array<string, mixed> $input
     */
    private function refusal(ProjectManagerTool $tool, array $input): string
    {
        try {
            $tool->handle($input);
        } catch (ToolException $e) {
            return $e->getMessage();
        }

        throw new \RuntimeException('the call was expected to be refused, but it succeeded');
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/claw-pm-' . uniqid('', true);
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
