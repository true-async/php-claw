<?php

declare(strict_types=1);

namespace Claw\Http;

use Claw\Exceptions\HttpException;

/**
 * An HTTP response. Status codes (including 4xx/5xx) are data, not errors —
 * the caller decides how to interpret them.
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers lowercased header names
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            throw new HttpException("Invalid JSON response (HTTP {$this->status})");
        }

        return $data;
    }
}
