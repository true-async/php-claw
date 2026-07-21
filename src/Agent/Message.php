<?php

declare(strict_types=1);

namespace Claw\Agent;

/**
 * One conversation turn: a role plus a list of content blocks.
 */
final readonly class Message
{
    /**
     * @param list<ContentBlockInterface> $content
     */
    public function __construct(
        public Role  $role,
        public array $content,
    ) {
    }

    public static function userText(string $text): self
    {
        return new self(Role::User, [new TextBlock($text)]);
    }

    /**
     * The message as plain arrays, so a conversation can be written down and read back.
     *
     * It lives on the value object rather than in whichever store happens to need it, because more than
     * one does: the chat has always persisted its history, and now a workflow persists the exchange a
     * step is in the middle of, so an interrupted run can carry on rather than start that step again.
     * A codec private to one store is a codec the next caller writes a second time.
     *
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'content' => array_map(static fn (ContentBlockInterface $b): array => match (true) {
                $b instanceof TextBlock => ['type' => 'text', 'text' => $b->text],
                $b instanceof ToolUseBlock => ['type' => 'tool_use', 'id' => $b->id, 'name' => $b->name, 'input' => $b->input],
                $b instanceof ToolResultBlock => ['type' => 'tool_result', 'tool_use_id' => $b->toolUseId, 'content' => $b->content, 'is_error' => $b->isError],
                default => throw new \Claw\Exceptions\ClawException('Message: cannot serialize ' . $b::class),
            }, $this->content),
        ];
    }

    /**
     * Read a message back. An unknown block type throws rather than being dropped: a conversation with a
     * hole in it is worse than one that refuses to load, because the model would carry on from a history
     * that is quietly not what happened.
     *
     * @param array<string, mixed> $data
     *
     * @throws \Claw\Exceptions\ClawException
     */
    public static function fromArray(array $data): self
    {
        $blocks = [];
        $content = \is_array($data['content'] ?? null) ? $data['content'] : [];

        foreach ($content as $block) {
            if (!\is_array($block)) {
                throw new \Claw\Exceptions\ClawException('Message: malformed content block');
            }

            $str = static fn (string $k): string => \is_scalar($block[$k] ?? null) ? (string) $block[$k] : '';

            $blocks[] = match ((string) ($block['type'] ?? '')) {
                'text' => new TextBlock($str('text')),
                'tool_use' => new ToolUseBlock($str('id'), $str('name'), \is_array($block['input'] ?? null) ? $block['input'] : []),
                'tool_result' => new ToolResultBlock($str('tool_use_id'), $str('content'), (bool) ($block['is_error'] ?? false)),
                default => throw new \Claw\Exceptions\ClawException('Message: unknown content block type "' . (string) ($block['type'] ?? '') . '"'),
            };
        }

        return new self(Role::from((string) ($data['role'] ?? '')), $blocks);
    }
}
