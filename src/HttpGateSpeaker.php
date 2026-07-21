<?php

declare(strict_types=1);

namespace Claw;

use Async\Channel;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStoreInterface;
use Claw\Trace\Tracer;
use Claw\Trace\TraceReader;
use Claw\Workflow\WorkflowStateStoreInterface;

/**
 * The human tier of a server-driven run's ask channel.
 *
 * A gate is two things, and only one of them survives a restart. The DURABLE half is the journal: a
 * `question` row when the run asks, an `answer` row naming it when someone replies — these feed the
 * dashboard's chat and outlive everything. The LIVE half is an `Async\Channel` the run coroutine sleeps
 * on, which dies with its process.
 *
 * So this looks in the journal FIRST. A reply can arrive while nothing is serving the gate — the server
 * was restarted, the run was killed — and when the run is started again it finds that reply waiting
 * rather than asking the same question a second time and stranding the person who already answered.
 * Only when nothing is waiting does it record a new question and park.
 *
 * A cursor in the state store says which replies this run has already taken. Without it a resumed run
 * would either act twice on one answer or ask again for one sitting there answered — opposite failures,
 * both silent, which is why the position is written down rather than inferred.
 */
final readonly class HttpGateSpeaker implements SpeakerInterface
{
    /** @param Channel<string> $answers the live wakeup — POST .../answer sends the human reply here */
    public function __construct(
        private Tracer $tracer,
        private ProjectStoreInterface $store,
        private string $issueId,
        private Channel $answers,
        private WorkflowStateStoreInterface $state,
    ) {
    }

    public function name(): SpeakerRole
    {
        return SpeakerRole::Human;
    }

    public function reply(string $incoming): string
    {
        $runId = $this->tracer->runId();
        $reader = new TraceReader($this->store->pdo());
        $waiting = $reader->answeredAfter($runId, $this->state->loadGateCursor($runId));

        // Someone answered while this run was not here to hear it. Take it and carry on: asking again
        // would throw away a reply a person has already given, and leave them watching a question they
        // thought they had settled.
        if ($waiting !== null) {
            $this->state->saveGateCursor($runId, $waiting['id']);
            $this->store->setIssueStatus($this->issueId, IssueStatus::InProgress);

            return $waiting['text'];
        }

        $questionId = $this->tracer->question($incoming);
        $this->store->setIssueStatus($this->issueId, IssueStatus::WaitingHuman);

        $text = (string) $this->answers->recv();   // park the run here until POST .../answer sends the reply

        $this->state->saveGateCursor($runId, $questionId);
        $this->store->setIssueStatus($this->issueId, IssueStatus::InProgress);
        $this->tracer->answer($questionId, $text);

        return $text;
    }
}
