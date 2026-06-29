<?php

declare(strict_types=1);

namespace Claw;

use Async\Channel;
use Claw\Agent\SpeakerInterface;
use Claw\Agent\SpeakerRole;
use Claw\Project\IssueStatus;
use Claw\Project\ProjectStoreInterface;
use Claw\Trace\Tracer;

/**
 * The human tier of a server-driven run's ask channel. When the supervisor agent escalates
 * (replies ESCALATE), this records the question in the trace journal — so the dashboard shows the
 * gate and a chat row — flips the issue to WaitingHuman, then PARKS the run coroutine on a channel
 * until {@see Server} feeds it the human's reply from `POST .../answer`. The reply is recorded as an
 * `answer` row and handed back to the run, which resumes.
 *
 * Two mechanisms, split on purpose (see docs/dashboard-server-plan.md §3.4): the question/answer
 * trace rows are the DURABLE record (they survive a restart and feed chat), the channel is only the
 * LIVE wakeup. A restart loses the channel but not the journal, so the gate stays visible and the run
 * resumes from its snapshot back into a fresh gate.
 */
final readonly class HttpGateSpeaker implements SpeakerInterface
{
    /** @param Channel<string> $answers the live wakeup — POST .../answer sends the human reply here */
    public function __construct(
        private Tracer $tracer,
        private ProjectStoreInterface $store,
        private string $issueId,
        private Channel $answers,
    ) {
    }

    public function name(): SpeakerRole
    {
        return SpeakerRole::Human;
    }

    public function reply(string $incoming): string
    {
        $questionId = $this->tracer->question($incoming);
        $this->store->setIssueStatus($this->issueId, IssueStatus::WaitingHuman);

        $text = (string) $this->answers->recv();   // park the run here until POST .../answer sends the reply

        $this->store->setIssueStatus($this->issueId, IssueStatus::InProgress);
        $this->tracer->answer($questionId, $text);

        return $text;
    }
}
