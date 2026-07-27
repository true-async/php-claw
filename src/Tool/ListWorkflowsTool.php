<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ClawException;
use Claw\Exceptions\ToolException;
use Claw\Project\IssueType;
use Claw\Workflow\WorkflowStore;

/**
 * The ready-made workflows the ProjectManager may choose from, for one kind of work.
 *
 * It exists because the `library` strategy asks a question nothing could answer: the ProjectManager
 * was told a tested workflow might fit the ticket, and shown no workflows. Choosing off an invisible
 * shelf is guessing, and the verdict it produced routed to a bespoke solver anyway.
 *
 * The type is a required argument rather than something read from the ticket, because the ticket is
 * not typed yet at this point in the triage: the ProjectManager decides the type, asks what serves it,
 * and then records both in the single `project_manager` call it is allowed. Recording a workflow that
 * does not serve the type it recorded is refused there, so the two answers cannot drift apart.
 */
final readonly class ListWorkflowsTool implements ToolInterface
{
    /** @param list<WorkflowStore> $libraries global first, then the project's own — the later wins a name clash */
    public function __construct(private array $libraries)
    {
    }

    public function name(): string
    {
        return 'list_workflows';
    }

    public function description(): string
    {
        return 'List the ready-made, tested workflows available for one kind of work, with what each is for. '
            . "Call it before choosing the 'library' strategy — that strategy means one of THESE fits the "
            . 'ticket as it stands, and it can only be recorded by naming one. An empty list means nothing '
            . 'ready-made fits and the work must be done another way.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => ['bug', 'feature', 'refactor', 'design', 'research', 'chore'],
                    'description' => 'the kind of work to list workflows for',
                ],
            ],
            'required' => ['type'],
        ];
    }

    public function effects(): array
    {
        return [Effect::Read];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $type = IssueType::parse((string) ($input['type'] ?? ''));

        try {
            $offered = WorkflowStore::offered($this->libraries, $type);
        } catch (ClawException $e) {
            // A library that cannot be read is reported, not silently treated as empty: an empty list is
            // itself an answer here ("nothing ready-made fits"), and a broken shelf must not impersonate it.
            throw new ToolException('list_workflows: ' . $e->getMessage(), 0, $e);
        }

        if ($offered === []) {
            return "No ready-made workflow serves a '{$type->value}' ticket. Choose another strategy.";
        }

        $lines = [];

        foreach ($offered as $entry) {
            $lines[] = "- {$entry['name']}\n  " . str_replace("\n", "\n  ", $entry['description']);
        }

        return "Ready-made workflows for a '{$type->value}' ticket — quote the name back to choose one:\n\n"
            . implode("\n\n", $lines);
    }
}
