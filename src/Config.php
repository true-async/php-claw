<?php

declare(strict_types=1);

namespace Claw;

use Claw\Exceptions\ConfigException;

/**
 * Immutable application configuration, loaded from a .env file with the process
 * environment taking precedence.
 *
 * Secrets are held in memory on this object only — they are never written to the
 * process environment, so they are not inherited by the bash tool's subprocesses.
 */
final class Config
{
    /** The fallback system prompt when no project CLAUDE.md persona is present. */
    /**
     * The persona for the INTERACTIVE CHAT, and only that: {@see \Claw\Cli\SessionMode} uses it when the
     * project ships no CLAUDE.md of its own. A person is typing, so a concise assistant is what is wanted.
     *
     * It is deliberately NOT a system-wide default, and the run path deliberately sets no system prompt at
     * all. Under an autonomous pipeline these two sentences work against the thing the pipeline is for:
     * "helpful assistant" is the eager-to-please persona behind every success this project was told about
     * and did not get, and "be concise" is an instruction to summarise — which is the exact shape of the
     * step that recorded "All tests passed." while the suite was erroring. A step's own prompt says what
     * that step must do; nothing above it needs to add a character.
     */
    public const CHAT_PERSONA = 'You are Claw, a helpful coding assistant. Be concise. '
        . 'Use the tools to inspect and change files and run commands in the workspace.';

    private const DEFAULT_CHANNEL = 'console';

    private const CHANNELS = ['console', 'telegram'];

    private const DEFAULT_AGENT = 'claude';

    private const AGENTS = ['claude', 'openai-compatible', 'gemini'];

    /** Per-agent default env var holding the API key. */
    private const API_KEY_VARS = [
        'claude' => 'ANTHROPIC_API_KEY',
        'openai-compatible' => 'OPENAI_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
    ];

    private const DEFAULT_WORKSPACE = './workspace';

    private const DEFAULT_MAX_HISTORY = 0;   // 0 = unbounded; the model's context window is the limit

    private const DEFAULT_TURN_TIMEOUT_MS = 300_000;

    private const DEFAULT_LIMIT = 0;   // 0 = no limit, for every budget cap below

    /**
     * The GLOBAL library of ready-made workflows: the ones offered to every project. A path rather
     * than a folder inside the app home, because these are written and reviewed by people and belong
     * wherever they keep such things; a missing folder is simply an empty library.
     *
     * Unset, it is the folder that SHIPS with claw, resolved against this file — not against the
     * current directory, which would mean the built-in workflows existed or not depending on where
     * the command happened to be run from.
     */
    private const DEFAULT_LIBRARY = 'workflows';

    /**
     * @param list<int>             $allowedChats telegram-only authorization allowlist (empty in console mode)
     * @param array<string, string> $agents       named agent roles -> model id (CLAW_AGENT_<ROLE>); all share
     *                                            the default access, overriding only the model for that role
     */
    private function __construct(
        public readonly string $channel,
        public readonly string $agent,
        public readonly string $apiKey,
        public readonly ?string $baseUrl,
        public readonly string $model,
        public readonly string $telegramToken,
        public readonly array $allowedChats,
        public readonly string $workspace,
        public readonly int $maxHistory,
        public readonly int $turnTimeoutMs,
        public readonly array $agents = [],
        public readonly int $budgetTokens = 0,
        public readonly int $budgetSeconds = 0,
        public readonly int $turnTokens = 0,
        public readonly int $turnSeconds = 0,
        public readonly string $library = self::DEFAULT_LIBRARY,
    ) {
    }

    /**
     * Load configuration. Reads $path (if present), then lets real environment
     * variables override file values. Throws on missing/invalid required keys.
     */
    public static function load(string $path = '.env'): self
    {
        $file = self::readDotenv($path);

        $get = static function (string $key) use ($file): ?string {
            $env = getenv($key);

            if ($env !== false && $env !== '') {
                return $env;
            }

            return $file[$key] ?? null;
        };

        $require = static function (string $key) use ($get): string {
            $value = $get($key);

            if ($value === null || $value === '') {
                throw new ConfigException("Missing required config: {$key}");
            }

            return $value;
        };

        $channel = $get('CLAW_CHANNEL') ?? self::DEFAULT_CHANNEL;

        if (!\in_array($channel, self::CHANNELS, true)) {
            throw new ConfigException(
                "Unknown CLAW_CHANNEL '{$channel}', expected one of: " . implode(', ', self::CHANNELS)
            );
        }

        $agent = $get('CLAW_AGENT') ?? self::DEFAULT_AGENT;

        if (!\in_array($agent, self::AGENTS, true)) {
            throw new ConfigException(
                "Unknown CLAW_AGENT '{$agent}', expected one of: " . implode(', ', self::AGENTS)
            );
        }

        $keyVar = self::API_KEY_VARS[$agent];
        $apiKey = $get($keyVar) ?? $get('CLAW_API_KEY');

        if ($apiKey === null || $apiKey === '') {
            throw new ConfigException(
                "Missing API key: set {$keyVar} (or CLAW_API_KEY) for agent '{$agent}'"
            );
        }

        // Telegram credentials are optional in console mode, required for telegram.
        $telegramToken = $get('TELEGRAM_BOT_TOKEN') ?? '';
        $allowedRaw = $get('CLAW_ALLOWED_CHATS');
        $allowedChats = ($allowedRaw === null || $allowedRaw === '') ? [] : self::parseChatIds($allowedRaw);

        if ($channel === 'telegram') {
            if ($telegramToken === '') {
                throw new ConfigException('TELEGRAM_BOT_TOKEN is required for the telegram channel');
            }

            if ($allowedChats === []) {
                throw new ConfigException('CLAW_ALLOWED_CHATS must list at least one chat id for the telegram channel');
            }
        }

        return new self(
            channel: $channel,
            agent: $agent,
            apiKey: $apiKey,
            baseUrl: $get('CLAW_BASE_URL'),
            model: $require('CLAW_MODEL'),
            telegramToken: $telegramToken,
            allowedChats: $allowedChats,
            workspace: $get('CLAW_WORKSPACE') ?? self::DEFAULT_WORKSPACE,
            maxHistory: (int) ($get('CLAW_MAX_HISTORY') ?? self::DEFAULT_MAX_HISTORY),
            turnTimeoutMs: (int) ($get('CLAW_TURN_TIMEOUT_MS') ?? self::DEFAULT_TURN_TIMEOUT_MS),
            agents: self::parseAgents($file),
            budgetTokens: (int) ($get('CLAW_BUDGET_TOKENS') ?? self::DEFAULT_LIMIT),
            budgetSeconds: (int) ($get('CLAW_BUDGET_SECONDS') ?? self::DEFAULT_LIMIT),
            turnTokens: (int) ($get('CLAW_TURN_TOKENS') ?? self::DEFAULT_LIMIT),
            turnSeconds: (int) ($get('CLAW_TURN_SECONDS') ?? self::DEFAULT_LIMIT),
            library: $get('CLAW_LIBRARY') ?? \dirname(__DIR__) . '/' . self::DEFAULT_LIBRARY,
        );
    }

    /**
     * Parse named agent roles from `CLAW_AGENT_<ROLE>=<model>` keys (file + real env, env winning)
     * into a lower-cased role -> model map. The default access/key is shared; only the model varies
     * per role, so a workflow can route a step with `ai(..., agent: 'reviewer')`.
     *
     * @param array<string, string> $file
     *
     * @return array<string, string>
     */
    private static function parseAgents(array $file): array
    {
        $prefix = 'CLAW_AGENT_';
        $merged = $file;                       // file is the base; real env overrides it

        foreach (getenv() as $key => $value) {
            $merged[$key] = $value;
        }

        $agents = [];

        foreach ($merged as $key => $value) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            // CLAW_AGENT_WORKER_SMART -> role 'worker-smart': env vars carry '_', roles read as '-'
            // so a workflow routes a call with the readable `ai(..., agent: 'worker-smart')`.
            $role = str_replace('_', '-', strtolower(substr($key, \strlen($prefix))));
            $model = trim($value);

            if ($role !== '' && $model !== '') {
                $agents[$role] = $model;
            }
        }

        return $agents;
    }

    public function isChatAllowed(int $chatId): bool
    {
        return \in_array($chatId, $this->allowedChats, true);
    }

    /**
     * Parse a minimal .env file into a key => value map. Lines starting with `#`
     * and blank lines are skipped; surrounding single/double quotes are stripped.
     *
     * @return array<string, string>
     */
    private static function readDotenv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new ConfigException("Cannot read env file: {$path}");
        }

        $out = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $eq = strpos($line, '=');

            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $value = self::stripQuotes(trim(substr($line, $eq + 1)));
            $out[$key] = $value;
        }

        return $out;
    }

    private static function stripQuotes(string $value): string
    {
        $len = \strlen($value);

        if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
            return substr($value, 1, $len - 2);
        }

        return $value;
    }

    /**
     * @return list<int>
     */
    private static function parseChatIds(string $raw): array
    {
        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (!ctype_digit(ltrim($part, '-'))) {
                throw new ConfigException(
                    "CLAW_ALLOWED_CHATS must be comma-separated integer chat ids, got '{$part}'"
                );
            }

            $ids[] = (int) $part;
        }

        if ($ids === []) {
            throw new ConfigException('CLAW_ALLOWED_CHATS is empty: no chat is authorized to use the bot');
        }

        return $ids;
    }
}
