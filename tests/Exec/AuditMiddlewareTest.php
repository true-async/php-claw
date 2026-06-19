<?php

declare(strict_types=1);

namespace Tests\Exec;

use Claw\Agent\ToolResultBlock;
use Claw\Exec\AuditMiddleware;
use Claw\Journal\JournalScope;
use Claw\Store\SessionStore;
use Claw\Tool\Registry;
use Claw\Tool\ToolCall;
use Testo\Assert;
use Testo\Test;
use Tests\Support\RecordingJournal;
use Tests\Support\StubAgentTool;
use Tests\Support\StubTool;

final class AuditMiddlewareTest
{
    #[Test]
    public function logsTheCallAndItsOutcome(): void
    {
        $path = sys_get_temp_dir() . '/claw-audit-' . uniqid('', true) . '.db';

        try {
            $store = new SessionStore($path);

            (new AuditMiddleware($store))->handle(
                new ToolCall('1', 'bash', ['command' => 'ls']),
                static fn (ToolCall $c): ToolResultBlock => new ToolResultBlock($c->id, 'output', false),
            );

            $trail = $store->auditTrail();
            Assert::same(count($trail), 1);
            Assert::true(str_contains($trail[0]['call'], 'bash'));
            Assert::same($trail[0]['result'], 'output');
            Assert::false($trail[0]['isError']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function recordsEachCallToTheJournalTellingAgentsFromTools(): void
    {
        $journal = new RecordingJournal();
        $registry = new Registry();
        $registry->add(new StubTool('bash'));
        $registry->add(new StubAgentTool('reviewer'));

        $audit = new AuditMiddleware(null, $journal, $registry, 'proj-1', 'iss-1', 'run-1');

        $audit->handle(
            new ToolCall('run-1.1', 'bash', ['command' => 'ls']),
            static fn (ToolCall $c): ToolResultBlock => new ToolResultBlock($c->id, 'out', false),
        );
        $audit->handle(
            new ToolCall('run-1.2', 'reviewer', ['code' => 'x']),
            static fn (ToolCall $c): ToolResultBlock => new ToolResultBlock($c->id, 'reviewed', false),
        );

        Assert::count($journal->recorded, 2);

        // the plain tool: name + exact params, with the full hierarchy it ran in
        $tool = $journal->recorded[0];
        Assert::same($tool->action, 'tool');
        Assert::same($tool->message, 'bash');
        Assert::same($tool->context['params'] ?? null, ['command' => 'ls']);
        Assert::same($tool->context['ok'] ?? null, true);
        Assert::same($tool->project, 'proj-1');
        Assert::same($tool->issue, 'iss-1');
        Assert::same($tool->workflow, 'run-1');

        // the agent, told apart from a plain tool
        $agent = $journal->recorded[1];
        Assert::same($agent->action, 'agent');
        Assert::same($agent->message, 'reviewer');

        // and the journal reads back by level
        Assert::count($journal->entries(JournalScope::Workflow, 'run-1'), 2);
        Assert::count($journal->entries(JournalScope::Issue, 'iss-1'), 2);
        Assert::count($journal->entries(JournalScope::Project, 'proj-1'), 2);
    }

    #[Test]
    public function withoutAStoreItJustPassesThrough(): void
    {
        $result = (new AuditMiddleware(null))->handle(
            new ToolCall('1', 'x', []),
            static fn (ToolCall $c): ToolResultBlock => new ToolResultBlock($c->id, 'ran', false),
        );

        Assert::same($result->content, 'ran');
    }
}
