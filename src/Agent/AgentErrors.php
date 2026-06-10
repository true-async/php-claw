<?php

declare(strict_types=1);

namespace Claw\Agent;

use Claw\Exceptions\AgentException;
use Claw\Exceptions\AuthException;
use Claw\Exceptions\BadRequestException;
use Claw\Exceptions\ContextLengthException;
use Claw\Exceptions\HttpException;
use Claw\Exceptions\OverloadedException;
use Claw\Exceptions\QuotaExceededException;
use Claw\Exceptions\RateLimitException;
use Claw\Exceptions\ServerErrorException;
use Claw\Exceptions\TransportException;
use Claw\Http\HttpResponse;

/**
 * Normalizes a failed provider response (status + body `error.type` + headers)
 * into a typed AgentException, so retry and the bot can react to the cause.
 * Both Anthropic and OpenAI-compatible bodies use `error.{type,message}`.
 */
final class AgentErrors
{
    public static function fromTransport(HttpException $e): TransportException
    {
        return new TransportException('Agent transport error: ' . $e->getMessage(), 0, $e);
    }

    public static function fromResponse(HttpResponse $response): AgentException
    {
        $data = json_decode($response->body, true);
        $errorType = is_array($data) ? ($data['error']['type'] ?? null) : null;
        $message = is_array($data) && isset($data['error']['message'])
            ? (string) $data['error']['message']
            : "HTTP {$response->status}";

        return self::classify($response->status, $errorType, $message, self::retryAfterMs($response->headers));
    }

    public static function classify(int $status, ?string $errorType, string $message, int $retryAfterMs): AgentException
    {
        // Context overflow can arrive as a generic 400/invalid_request, so match
        // the message regardless of status before the cause-by-status mapping.
        if (self::isContextOverflow($message)) {
            return new ContextLengthException($message);
        }

        // Provider error.type is the most precise signal when present.
        switch ($errorType) {
            case 'overloaded_error':
                return new OverloadedException($message);
            case 'insufficient_quota':
            case 'insufficient_balance':
            case 'billing_error':
                return new QuotaExceededException($message, $retryAfterMs);
            case 'rate_limit_error':
                return new RateLimitException($message, $retryAfterMs);
            case 'authentication_error':
            case 'permission_error':
                return new AuthException($message);
            case 'invalid_request_error':
            case 'not_found_error':
                return new BadRequestException($message);
        }

        return match (true) {
            $status === 401, $status === 403 => new AuthException($message),
            $status === 402 => new QuotaExceededException($message, $retryAfterMs),
            $status === 429 => new RateLimitException($message, $retryAfterMs),
            $status === 529 => new OverloadedException($message),
            $status >= 500 => new ServerErrorException($message),
            \in_array($status, [400, 404, 413, 422], true) => new BadRequestException($message),
            default => new AgentException($message),
        };
    }

    private static function isContextOverflow(string $message): bool
    {
        $message = strtolower($message);
        foreach (['context length', 'context window', 'maximum context', 'prompt is too long', 'too many tokens', 'reduce the length'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function retryAfterMs(array $headers): int
    {
        $value = $headers['retry-after'] ?? '';

        return ctype_digit($value) ? ((int) $value) * 1000 : 0;
    }
}
