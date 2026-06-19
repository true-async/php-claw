<?php

declare(strict_types=1);

namespace Claw\Http;

/**
 * Thin HTTP transport. Implementations handle retries on transport-level
 * conditions (network errors, 429, 5xx); application-level errors are left to
 * the caller via the returned status.
 */
interface HttpClientInterface
{
    /**
     * @param array<int, string> $headers raw header lines ("Name: value")
     *
     * @throws \Claw\Exceptions\HttpException on transport failure after retries
     */
    public function post(string $url, string $body, array $headers = []): HttpResponse;

    /**
     * @param array<int, string> $headers raw header lines ("Name: value")
     *
     * @throws \Claw\Exceptions\HttpException on transport failure after retries
     */
    public function get(string $url, array $headers = []): HttpResponse;
}
