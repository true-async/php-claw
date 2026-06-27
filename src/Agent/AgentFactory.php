<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Config;
use Claw\Http\HttpClientInterface;

/**
 * Builds the agent named by the config, or null if that agent is not wired yet. Agents retry internally
 * (cause-aware), so callers pass a plain transport. Lives here, not in the CLI layer, so every entry
 * point (CLI run, dashboard server) composes its agent the same way.
 */
final class AgentFactory
{
    public static function make(Config $config, HttpClientInterface $http): ?AgentInterface
    {
        return match ($config->agent) {
            'claude' => new ClaudeAgent($http, $config->apiKey),
            'openai-compatible' => new OpenAiCompatibleAgent(
                $http,
                $config->apiKey,
                $config->baseUrl ?? 'https://api.deepseek.com',
            ),
            default => null,
        };
    }
}
