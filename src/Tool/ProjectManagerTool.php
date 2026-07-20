<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ClawException;
use Claw\Exceptions\ToolException;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStoreInterface;
use Claw\Project\Strategy;

/**
 * The ProjectManager's hands on the ticket ledger: open an issue (including a sub-issue of one too
 * big to solve in a single run), record HOW an issue is to be solved, report that a strategy did not
 * work, and close or reopen a ticket.
 *
 * One tool with an `action` discriminator rather than five tools — the same shape {@see RecallTool}
 * uses — so the whole ledger surface is one entry in the model's palette instead of crowding it.
 *
 * {@see Risk::Mutating}, which is the point: {@see \Claw\Permission\Policy} already routes every
 * mutating call to a human confirmation, so "a decomposition must be approved by a person" needs no
 * mechanism of its own. It falls out of the permission layer that already exists.
 *
 * The rules this tool appears to enforce — the decomposition caps and the escalation order — are NOT
 * implemented here. They live in {@see \Claw\Project\ProjectStore}, with the data, because this tool
 * is not the only door: the CLI and the dashboard open issues through the same store. A bound that
 * only one caller honours is not a bound. This class translates a refusal into something a model can
 * act on, and nothing more.
 */
final readonly class ProjectManagerTool implements ToolInterface
{
    public function __construct(private ProjectStoreInterface $store)
    {
    }

    public function name(): string
    {
        return 'project_manager';
    }

    public function description(): string
    {
        return 'Manage this project\'s tickets. '
            . "action='create_issue' (title, optional description, optional parent) opens an issue — pass "
            . 'parent to open it as a SUB-ISSUE of a task too big to solve in one run; '
            . "action='set_strategy' (issue, strategy, reason, optional needs_human) records HOW an issue "
            . 'is to be solved — strategy is one of direct (one agent, a localized change), library (a '
            . 'ready-made workflow fits), generate (write a bespoke solver), decompose (split into '
            . 'sub-issues); '
            . "action='needs_human' (issue, reason) parks the ticket for a PERSON — use it when the ticket "
            . 'cannot be worked on as written (it says nothing actionable, contradicts itself, or asks about '
            . 'something the project does not have) or when the call is not yours to make. This is a real '
            . 'answer, not a failure: better than guessing a strategy for a ticket nobody can act on; '
            . "action='report_failure' (issue, reason) says the current strategy did not work, so the "
            . 'issue is triaged again and the next attempt must escalate; '
            . "action='close_issue' (issue, optional reason) and action='reopen_issue' (issue) settle a "
            . 'ticket. Decomposition is capped in depth and breadth; a refusal means pick another strategy.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create_issue', 'set_strategy', 'needs_human', 'report_failure', 'close_issue', 'reopen_issue'],
                    'description' => 'what to do to the ticket ledger',
                ],
                'issue' => ['type' => 'string', 'description' => 'the issue id to act on; not used by create_issue'],
                'title' => ['type' => 'string', 'description' => "the new issue's title; required for create_issue"],
                'description' => ['type' => 'string', 'description' => 'the new issue\'s body — the full task brief'],
                'parent' => [
                    'type' => 'string',
                    'description' => 'id of the issue this is a part of; omit for a top-level issue',
                ],
                'strategy' => [
                    'type' => 'string',
                    'enum' => ['direct', 'library', 'generate', 'decompose'],
                    'description' => 'how the issue is to be solved; required for set_strategy',
                ],
                'reason' => ['type' => 'string', 'description' => 'why — the justification for this decision'],
                'needs_human' => [
                    'type' => 'boolean',
                    'description' => 'true if a person must approve before the work runs',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Mutating;
    }

    public function handle(array $input): string
    {
        $action = $this->text($input, 'action');

        // Every store failure — a refused cap, a missing issue, a corrupt ledger row, a busy database —
        // is turned into a ToolException here, because that is the ONLY exception the executor converts
        // into a tool result. Anything else escaping this method kills the whole run instead of coming
        // back to the model as text it can read and act on.
        try {
            return match ($action) {
                'create_issue' => $this->createIssue($input),
                'set_strategy' => $this->setStrategy($input),
                'report_failure' => $this->reportFailure($input),
                'close_issue' => $this->closeIssue($input),
                'needs_human' => $this->needsHuman($input),
                'reopen_issue' => $this->reopenIssue($input),
                default => throw new ToolException(
                    "project_manager: unknown action '{$action}' (use create_issue|set_strategy|needs_human"
                    . '|report_failure|close_issue|reopen_issue)',
                ),
            };
        } catch (ToolException $e) {
            throw $e;
        } catch (ClawException | \PDOException $e) {
            throw new ToolException('project_manager: ' . $e->getMessage());
        }
    }

    /**
     * Open an issue, or a sub-issue when `parent` is given. The depth and breadth caps are the store's;
     * a refusal arrives as a ClawException and {@see handle()} hands it back to the model as text.
     *
     * @param array<string, mixed> $input
     */
    private function createIssue(array $input): string
    {
        $title = $this->text($input, 'title');

        if ($title === '') {
            throw new ToolException("project_manager: action='create_issue' needs a 'title'");
        }

        $parent = $this->text($input, 'parent');
        $parent = $parent === '' ? null : $parent;
        $issue = $this->store->addIssue($title, $this->text($input, 'description'), $parent);

        return $parent === null
            ? "Opened issue #{$issue->id}: {$issue->title}"
            : "Opened issue #{$issue->id} as a sub-issue of #{$parent} (depth {$issue->depth}): {$issue->title}";
    }

    /** @param array<string, mixed> $input */
    private function setStrategy(array $input): string
    {
        $issueId = $this->requireIssue($input, 'set_strategy');
        $reason = $this->text($input, 'reason');

        if ($reason === '') {
            throw new ToolException(
                "project_manager: action='set_strategy' needs a 'reason' — the verdict is reviewed, so say why",
            );
        }

        $strategy = Strategy::parse($this->text($input, 'strategy'));
        $needsHuman = $this->flag($input, 'needs_human');
        $this->store->setStrategy($issueId, $strategy, $reason, $needsHuman);

        return "Issue #{$issueId} will be solved by: {$strategy->value}"
            . ($needsHuman ? ' (a person must approve first).' : '.');
    }

    /**
     * Park a ticket for a person, with the reason. The honest answer to a ticket that cannot be
     * worked on as written: the alternative was recording a strategy nobody believes and letting a
     * worker burn money inventing the task — measured once at $0.13 on a ticket reading "ку".
     *
     * No strategy is recorded, because there is no way to solve it yet; the reason is what the person
     * reads. The ticket keeps whatever a run later gives it, once the person has said what it means.
     *
     * @param array<string, mixed> $input
     */
    private function needsHuman(array $input): string
    {
        $issueId = $this->requireIssue($input, 'needs_human');
        $reason = $this->text($input, 'reason');

        if ($reason === '') {
            throw new ToolException(
                "project_manager: action='needs_human' needs a 'reason' — it is what the person will read",
            );
        }

        $this->store->setIssueStatus($issueId, IssueStatus::WaitingHuman);

        return "Issue #{$issueId} is waiting for a person: {$reason}";
    }

    /** @param array<string, mixed> $input */
    private function reportFailure(array $input): string
    {
        $issueId = $this->requireIssue($input, 'report_failure');
        $reason = $this->text($input, 'reason');

        if ($reason === '') {
            throw new ToolException(
                "project_manager: action='report_failure' needs a 'reason' — the next attempt is chosen from it",
            );
        }

        // Read the verdict BEFORE failing it, so the reply names what actually broke. failStrategy
        // returns false when there was nothing in force — a second report in a row, or an issue that
        // was never triaged — and saying so is more useful than a confirmation that did nothing.
        $current = $this->store->currentStrategy($issueId);

        if ($current === null || !$this->store->failStrategy($issueId, $reason)) {
            return "Issue #{$issueId} has no strategy in force to fail — it was never triaged, or its last "
                . 'attempt was already reported failed. Record a strategy with set_strategy first.';
        }

        return "Issue #{$issueId} failed under {$current['strategy']->value} and is open for triage again. "
            . 'The next strategy must do MORE than the one that failed — a cheaper or equal one is refused.';
    }

    /** @param array<string, mixed> $input */
    private function closeIssue(array $input): string
    {
        $issueId = $this->requireIssue($input, 'close_issue');
        $this->store->setIssueStatus($issueId, IssueStatus::Closed);

        // A closed sub-issue may have been the last one its parent was waiting on.
        $this->store->settleAncestors($issueId);
        $reason = $this->text($input, 'reason');

        return "Issue #{$issueId} closed" . ($reason === '' ? '.' : ": {$reason}");
    }

    /** @param array<string, mixed> $input */
    private function reopenIssue(array $input): string
    {
        $issueId = $this->requireIssue($input, 'reopen_issue');
        $this->store->setIssueStatus($issueId, IssueStatus::Open);

        // Its parent may have been settled BECAUSE this was the last child to land; leaving it settled
        // would report a finished ticket with live work under it.
        $this->store->reopenAncestors($issueId);

        return "Issue #{$issueId} reopened.";
    }

    /**
     * The issue id an action acts on, checked to exist — a typo'd id would otherwise write a strategy
     * for a ticket nobody will ever read. A missing issue raises a ClawException that {@see handle()}
     * turns into a readable refusal.
     *
     * @param array<string, mixed> $input
     *
     * @throws ToolException
     */
    private function requireIssue(array $input, string $action): string
    {
        $issueId = $this->text($input, 'issue');

        if ($issueId === '') {
            throw new ToolException("project_manager: action='{$action}' needs an 'issue' id");
        }
        $this->store->loadIssue($issueId);

        return $issueId;
    }

    /**
     * A boolean argument. Not a plain cast: models routinely send `"false"` as a STRING despite the
     * schema, and `(bool) "false"` is true — which would silently demand human approval, or worse,
     * silently stop demanding it.
     *
     * @param array<string, mixed> $input
     */
    private function flag(array $input, string $key): bool
    {
        return filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOL);
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        return \is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    }
}
