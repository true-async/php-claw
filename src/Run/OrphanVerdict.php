<?php

declare(strict_types=1);

namespace Claw\Run;

use Claw\Project\IssueStatus;
use Claw\Project\ProjectStoreInterface;
use Claw\Trace\TraceReader;

/**
 * What to do with a run the ledger still calls Running when nothing is running it.
 *
 * At startup every such row is a leftover from a process that stopped. The three answers are not
 * interchangeable and getting one wrong is expensive in a different way each time, so the choice is
 * made here — away from the spawning, the echoing and the HTTP server — where it can be tested. That
 * separation is the whole reason this type exists: the decision needs no event loop, and it was
 * previously buried inside a method that does.
 */
enum OrphanVerdict: string
{
    /**
     * The run was at its human gate. Leave everything alone.
     *
     * Its question is in the journal and the ticket saying a person is expected is TRUE — the only
     * thing missing is a process, and answering brings one back. Settling it would throw away a
     * question somebody may be about to reply to.
     */
    case Waiting = 'waiting';

    /**
     * The run was interrupted mid-work. Start it again; it will resume rather than restart.
     *
     * Everything needed is already durable: the snapshot says which steps finished, the persisted
     * exchange says where the step it died in had got to, and the Running row is precisely what
     * {@see IssueRunner} reads as "resume this, do not open a new one". A restart is not a reason to
     * throw work away and pay for it twice.
     */
    case Resume = 'resume';

    /**
     * Nothing to resume. Mark the run failed and hand the ticket back.
     *
     * Only for a run that cannot be revived — its ticket is already Done, so the row is merely stale.
     */
    case Settle = 'settle';

    /**
     * Judge one run. $reader looks in the journal for an open gate; $store for the ticket's state.
     *
     * Deliberately takes no view on whether an agent is configured: that is a property of the moment
     * the caller tries to act, not of the run, and folding it in here would make the same run get
     * different verdicts for reasons that have nothing to do with it.
     */
    public static function forRun(ProjectStoreInterface $store, TraceReader $reader, string $runId, string $issueId): self
    {
        if ($reader->openGate($runId) !== null) {
            return self::Waiting;
        }

        return $store->loadIssue($issueId)->status === IssueStatus::Done ? self::Settle : self::Resume;
    }
}
