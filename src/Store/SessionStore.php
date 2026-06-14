<?php

declare(strict_types=1);

namespace Claw\Store;

use Claw\Agent\ContentBlockInterface;
use Claw\Agent\Message;
use Claw\Agent\Role;
use Claw\Agent\TextBlock;
use Claw\Agent\ToolResultBlock;
use Claw\Agent\ToolUseBlock;
use Claw\Exceptions\ClawException;

/**
 * One SQLite file = one conversation's history. One file per chat means one
 * writer and no lock contention (the reason ARCHITECTURE.md keeps a db per
 * chat_id). Each Message is a row; the provider-neutral content blocks are
 * stored as JSON, so any backend's history round-trips unchanged.
 */
final class SessionStore
{
    private \PDO $pdo;

    public function __construct(string $path)
    {
        try {
            $this->pdo = new \PDO('sqlite:' . $path);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS messages (
                    seq     INTEGER PRIMARY KEY AUTOINCREMENT,
                    role    TEXT NOT NULL,
                    content TEXT NOT NULL
                )',
            );
            // Persisted "always allow" rules: a row means the tool runs without asking.
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS rules (name TEXT PRIMARY KEY)');
            // Audit log: one row per tool call (including blocked/denied ones).
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS audit (
                    id       INTEGER PRIMARY KEY AUTOINCREMENT,
                    call     TEXT NOT NULL,
                    is_error INTEGER NOT NULL,
                    result   TEXT NOT NULL
                )',
            );
        } catch (\PDOException $e) {
            throw new ClawException('SessionStore: cannot open ' . $path . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The full stored history, in insertion order.
     *
     * @return list<Message>
     */
    public function load(): array
    {
        $stmt = $this->pdo->query('SELECT role, content FROM messages ORDER BY seq');
        if ($stmt === false) {
            return [];
        }

        /** @var list<array{role: string, content: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn (array $row): Message => $this->decode($row['role'], $row['content']), $rows);
    }

    /** Append messages to the end of the stored history. */
    public function append(Message ...$messages): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO messages (role, content) VALUES (:role, :content)');
        if ($stmt === false) {
            throw new ClawException('SessionStore: failed to prepare insert');
        }

        foreach ($messages as $message) {
            $stmt->execute([
                'role' => $message->role->value,
                'content' => $this->encode($message->content),
            ]);
        }
    }

    /** Whether the user has previously chosen "always allow" for this tool. */
    public function isToolAllowed(string $tool): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM rules WHERE name = :name');
        if ($stmt === false) {
            return false;
        }

        $stmt->execute(['name' => $tool]);

        return $stmt->fetchColumn() !== false;
    }

    /** Remember an "always allow" rule for this tool. */
    public function allowTool(string $tool): void
    {
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO rules (name) VALUES (:name)');
        if ($stmt === false) {
            throw new ClawException('SessionStore: failed to prepare rule insert');
        }

        $stmt->execute(['name' => $tool]);
    }

    /** Record one tool call (its summary), the outcome flag, and the result text. */
    public function logToolCall(string $call, string $result, bool $isError): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO audit (call, is_error, result) VALUES (:call, :err, :result)');
        if ($stmt === false) {
            throw new ClawException('SessionStore: failed to prepare audit insert');
        }

        $stmt->execute(['call' => $call, 'err' => $isError ? 1 : 0, 'result' => $result]);
    }

    /**
     * The full audit trail, oldest first.
     *
     * @return list<array{call: string, isError: bool, result: string}>
     */
    public function auditTrail(): array
    {
        $stmt = $this->pdo->query('SELECT call, is_error, result FROM audit ORDER BY id');
        if ($stmt === false) {
            return [];
        }

        /** @var list<array{call: string, is_error: int, result: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'call' => $row['call'],
                'isError' => $row['is_error'] !== 0,
                'result' => $row['result'],
            ],
            $rows,
        );
    }

    /**
     * @param list<ContentBlockInterface> $content
     */
    private function encode(array $content): string
    {
        return json_encode(
            array_map($this->encodeBlock(...), $content),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeBlock(ContentBlockInterface $block): array
    {
        return match (true) {
            $block instanceof TextBlock => ['type' => 'text', 'text' => $block->text],
            $block instanceof ToolUseBlock => ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => $block->input],
            $block instanceof ToolResultBlock => ['type' => 'tool_result', 'tool_use_id' => $block->toolUseId, 'content' => $block->content, 'is_error' => $block->isError],
            default => throw new ClawException('SessionStore: cannot serialize ' . $block::class),
        };
    }

    private function decode(string $role, string $json): Message
    {
        /** @var list<array<string, mixed>> $blocks */
        $blocks = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new Message(Role::from($role), array_map($this->decodeBlock(...), $blocks));
    }

    /**
     * @param array<string, mixed> $block
     */
    private function decodeBlock(array $block): ContentBlockInterface
    {
        $type = isset($block['type']) && \is_string($block['type']) ? $block['type'] : '';

        return match ($type) {
            'text'        => new TextBlock($this->str($block, 'text')),
            'tool_use'    => new ToolUseBlock($this->str($block, 'id'), $this->str($block, 'name'), $this->obj($block, 'input')),
            'tool_result' => new ToolResultBlock($this->str($block, 'tool_use_id'), $this->str($block, 'content'), (bool) ($block['is_error'] ?? false)),
            default       => throw new ClawException('SessionStore: unknown content block type "' . $type . '"'),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function str(array $block, string $key): string
    {
        return isset($block[$key]) && \is_string($block[$key]) ? $block[$key] : '';
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private function obj(array $block, string $key): array
    {
        /** @var array<string, mixed> $value */
        $value = isset($block[$key]) && \is_array($block[$key]) ? $block[$key] : [];

        return $value;
    }
}
