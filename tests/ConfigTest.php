<?php

declare(strict_types=1);

namespace Tests;

use Claw\Config;
use Claw\Exceptions\ConfigException;
use Testo\Assert;
use Testo\Test;

final class ConfigTest
{
    #[Test]
    public function loadsEnvWithQuotesCommentsAndPrecedence(): void
    {
        $config = $this->load(implode("\n", [
            '# comment line',
            'CLAW_AGENT=openai-compatible',
            'export OPENAI_API_KEY="sk-test-123"',           // export prefix + double quotes
            'CLAW_BASE_URL=https://api.deepseek.com',
            "CLAW_MODEL='deepseek-chat'",                     // single quotes
            'CLAW_WORKSPACE=/tmp/ws',
        ]));

        Assert::same($config->channel, 'console');            // default
        Assert::same($config->agent, 'openai-compatible');
        Assert::same($config->apiKey, 'sk-test-123');
        Assert::same($config->baseUrl, 'https://api.deepseek.com');
        Assert::same($config->model, 'deepseek-chat');
        Assert::same($config->maxHistory, 0);                 // default: unbounded
        Assert::same($config->allowedChats, []);              // optional in console mode

        // Real environment variables override file values.
        putenv('CLAW_MODEL=env-model');
        Assert::same(Config::load($this->envFile('CLAW_AGENT=claude' . "\nANTHROPIC_API_KEY=k\nCLAW_MODEL=file"))->model, 'env-model');
        putenv('CLAW_MODEL');
    }

    #[Test]
    public function consoleModeDoesNotRequireTelegramConfig(): void
    {
        $config = $this->load("CLAW_AGENT=claude\nANTHROPIC_API_KEY=k\nCLAW_MODEL=m\n");

        Assert::same($config->channel, 'console');
        Assert::same($config->telegramToken, '');
        Assert::same($config->allowedChats, []);
    }

    #[Test]
    public function telegramChannelRequiresTokenAndAllowlist(): void
    {
        $this->assertError(
            "CLAW_CHANNEL=telegram\nCLAW_AGENT=claude\nANTHROPIC_API_KEY=k\nCLAW_MODEL=m\nCLAW_ALLOWED_CHATS=1\n",
            'TELEGRAM_BOT_TOKEN is required for the telegram channel',
        );
        $this->assertError(
            "CLAW_CHANNEL=telegram\nCLAW_AGENT=claude\nANTHROPIC_API_KEY=k\nCLAW_MODEL=m\nTELEGRAM_BOT_TOKEN=t\n",
            'CLAW_ALLOWED_CHATS must list at least one chat id for the telegram channel',
        );

        $config = $this->load("CLAW_CHANNEL=telegram\nCLAW_AGENT=claude\nANTHROPIC_API_KEY=k\nCLAW_MODEL=m\nTELEGRAM_BOT_TOKEN=t\nCLAW_ALLOWED_CHATS=42\n");
        Assert::same($config->channel, 'telegram');
        Assert::same($config->allowedChats, [42]);
        Assert::true($config->isChatAllowed(42));
        Assert::false($config->isChatAllowed(7));
    }

    #[Test]
    public function rejectsUnknownChannelAndAgent(): void
    {
        $this->assertError(
            "CLAW_CHANNEL=irc\nCLAW_AGENT=claude\nANTHROPIC_API_KEY=k\nCLAW_MODEL=m\n",
            "Unknown CLAW_CHANNEL 'irc', expected one of: console, telegram",
        );
        $this->assertError(
            "CLAW_AGENT=bogus\nANTHROPIC_API_KEY=k\nCLAW_MODEL=m\n",
            "Unknown CLAW_AGENT 'bogus', expected one of: claude, openai-compatible, gemini",
        );
    }

    #[Test]
    public function rejectsMissingApiKeyAndModel(): void
    {
        $this->assertError(
            "CLAW_AGENT=claude\nCLAW_MODEL=m\n",
            "Missing API key: set ANTHROPIC_API_KEY (or CLAW_API_KEY) for agent 'claude'",
        );
        $this->assertError(
            "CLAW_AGENT=claude\nANTHROPIC_API_KEY=k\n",
            'Missing required config: CLAW_MODEL',
        );
    }

    #[Test]
    public function rejectsNonNumericAllowlist(): void
    {
        $this->assertError(
            "CLAW_AGENT=claude\nANTHROPIC_API_KEY=k\nCLAW_MODEL=m\nCLAW_ALLOWED_CHATS=1,abc\n",
            "CLAW_ALLOWED_CHATS must be comma-separated integer chat ids, got 'abc'",
        );
    }

    private function load(string $envBody): Config
    {
        $this->clearEnv();
        $file = $this->envFile($envBody);

        try {
            return Config::load($file);
        } finally {
            @unlink($file);
        }
    }

    private function assertError(string $envBody, string $expectedMessage): void
    {
        $this->clearEnv();
        $file = $this->envFile($envBody);

        try {
            Config::load($file);
            Assert::same('no exception was thrown', $expectedMessage);
        } catch (ConfigException $e) {
            Assert::same($e->getMessage(), $expectedMessage);
        } finally {
            @unlink($file);
        }
    }

    private function envFile(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'claw_env_');
        file_put_contents($path, $body);

        return $path;
    }

    private function clearEnv(): void
    {
        foreach ([
            'CLAW_CHANNEL', 'CLAW_AGENT', 'CLAW_API_KEY', 'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY',
            'CLAW_BASE_URL', 'CLAW_MODEL', 'TELEGRAM_BOT_TOKEN', 'CLAW_ALLOWED_CHATS',
            'CLAW_WORKSPACE', 'CLAW_MAX_HISTORY', 'CLAW_TURN_TIMEOUT_MS',
        ] as $key) {
            putenv($key);
        }
    }
}
