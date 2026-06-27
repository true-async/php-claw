<?php

declare(strict_types=1);

namespace Claw\Cli;

use function Async\spawn;

use Claw\Agent\AgentFactory;
use Claw\Chat\ConsoleChat;
use Claw\Chat\ConversationInterface;
use Claw\Chat\TelegramChat;
use Claw\Chat\TelegramClient;
use Claw\Config;
use Claw\Exceptions\ClawException;
use Claw\Http\CurlHttpClient;
use Claw\Session;
use Claw\Store\SessionStore;
use Claw\Tool\BashTool;
use Claw\Tool\DateTool;
use Claw\Tool\ListFilesTool;
use Claw\Tool\PhpEvalTool;
use Claw\Tool\ReadFileTool;
use Claw\Tool\Registry;
use Claw\Tool\ScheduleTool;
use Claw\Tool\Workspace;
use Claw\Tool\WriteFileTool;

/**
 * The interactive chat mode (the original Claw): one {@see Session} per conversation
 * over the console or Telegram. Reached with `claw --session`; the workflow mode is
 * the default.
 */
final class SessionMode
{
    /** @param string $root the install root: holds .env, CLAUDE.md and the workspace. */
    public function __construct(private readonly string $root)
    {
    }

    public function run(): int
    {
        try {
            $config = Config::load($this->root . '/.env');
        } catch (ClawException $e) {
            fwrite(STDERR, 'Config error: ' . $e->getMessage() . "\n");

            return 1;
        }

        // Workspace: the sandbox directory for the file/bash tools.
        if (!is_dir($config->workspace)) {
            mkdir($config->workspace, 0o775, true);
        }
        $workspaceDir = realpath($config->workspace);
        if ($workspaceDir === false) {
            fwrite(STDERR, "Cannot resolve workspace: {$config->workspace}\n");

            return 1;
        }

        // Transport is a single request; retries are cause-aware at the agent level.
        $http = new CurlHttpClient();

        $agent = AgentFactory::make($config, $http);   // agents retry internally (cause-aware)
        if ($agent === null) {
            fwrite(STDERR, "Agent '{$config->agent}' is not wired yet.\n");

            return 1;
        }

        $workspace = new Workspace($workspaceDir);

        $persona = $this->root . '/CLAUDE.md';
        $system = is_file($persona) ? (string) file_get_contents($persona) : Config::DEFAULT_SYSTEM;

        $chat = match ($config->channel) {
            'console' => new ConsoleChat(),
            'telegram' => new TelegramChat(
                new TelegramClient($http, $config->telegramToken),
                $config->isChatAllowed(...),   // authorization: drop anyone not on the allowlist
            ),
            default => null,
        };
        if ($chat === null) {
            fwrite(STDERR, "Channel '{$config->channel}' is not wired yet.\n");

            return 1;
        }

        // One SQLite file per conversation (keyed by its id), so history survives restarts.
        $sessionsDir = $workspaceDir . '/sessions';
        if (!is_dir($sessionsDir)) {
            mkdir($sessionsDir, 0o775, true);
        }

        // Build the per-conversation tool set + store and run its session. Tools are
        // per-conversation because `schedule` delivers reminders to that exact chat.
        $runSession = static function (ConversationInterface $conversation) use ($agent, $workspace, $workspaceDir, $system, $config, $sessionsDir): void {
            $tools = new Registry();
            $tools->add(new BashTool($workspaceDir));
            $tools->add(new ReadFileTool($workspace));
            $tools->add(new WriteFileTool($workspace));
            $tools->add(new ListFilesTool($workspace));
            $tools->add(new DateTool());
            $tools->add(new PhpEvalTool());
            $tools->add(new ScheduleTool($conversation->send(...)));

            $store = new SessionStore($sessionsDir . '/' . $conversation->id() . '.db');

            new Session(
                $conversation,
                $agent,
                $tools,
                $system,
                $config->model,
                $config->maxHistory,
                store: $store,
                toolTimeoutMs: $config->turnTimeoutMs,   // cap each tool run (kills a hung bash)
                workflowDir: $workspaceDir . '/workflows',   // enables define_workflow / run_workflow
            )->run();
        };

        if ($chat instanceof TelegramChat) {
            // Many chats: long-poll in the background, then one Session per authorized chat.
            spawn($chat->poll(...));
            for (;;) {
                $conversation = $chat->accept();
                spawn(static fn () => $runSession($conversation));
            }
        }

        // Console: a single conversation, run inline.
        $runSession($chat->accept());

        return 0;
    }
}
