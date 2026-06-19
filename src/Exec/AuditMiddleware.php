<?php

declare(strict_types=1);

namespace Claw\Exec;

use Claw\Agent\ToolResultBlock;
use Claw\Journal\JournalEntry;
use Claw\Journal\JournalInterface;
use Claw\Journal\JournalScope;
use Claw\Store\SessionStore;
use Claw\Tool\AgentToolInterface;
use Claw\Tool\Registry;
use Claw\Tool\ToolCall;

/**
 * Records every tool call — including blocked and denied ones — to the audit log, and,
 * when a journal is configured, into the workflow journal too: one entry per call with the
 * tool name and the exact parameters, told apart as an "agent" call or a plain "tool" call.
 * Sits outermost in the chain so it captures the verdict produced by the inner stages.
 * A no-op for whichever sink (audit store / journal) is absent.
 */
final readonly class AuditMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ?SessionStore $store = null,
        private ?JournalInterface $journal = null,
        private ?Registry $registry = null,
        private ?string $project = null,
        private ?string $issue = null,
        private ?string $workflow = null,   // the run id this executor serves
    ) {
    }

    public function handle(ToolCall $call, callable $next): ToolResultBlock
    {
        $result = $next($call);

        $this->store?->logToolCall($call->summary(), $result->content, $result->isError);
        $this->journal?->record($this->entry($call, $result));

        return $result;
    }

    /** One call as a journal entry — an "agent" action if the registry knows it as one, else "tool". */
    private function entry(ToolCall $call, ToolResultBlock $result): JournalEntry
    {
        $isAgent = $this->registry !== null
            && $this->registry->has($call->name)
            && $this->registry->get($call->name) instanceof AgentToolInterface;

        return new JournalEntry(
            JournalScope::Workflow,
            $isAgent ? 'agent' : 'tool',
            $call->name,
            ['params' => $call->input, 'ok' => !$result->isError],
            project: $this->project,
            issue: $this->issue,
            workflow: $this->workflow,
        );
    }
}
