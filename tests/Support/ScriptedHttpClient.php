<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Exceptions\HttpException;
use Claw\Http\HttpClientInterface;
use Claw\Http\HttpResponse;

/**
 * Returns preset outcomes (responses or thrown HttpExceptions) in order and
 * counts calls — lets the retry decorator be tested without the network.
 */
final class ScriptedHttpClient implements HttpClientInterface
{
    /** @var array<array-key, HttpResponse|HttpException> */
    private array $outcomes;

    public int $calls = 0;

    public function __construct(HttpResponse|HttpException ...$outcomes)
    {
        $this->outcomes = $outcomes;
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        return $this->next();
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->next();
    }

    private function next(): HttpResponse
    {
        $this->calls++;

        $outcome = array_shift($this->outcomes);

        if ($outcome === null) {
            throw new \RuntimeException('ScriptedHttpClient: no more outcomes');
        }

        if ($outcome instanceof HttpException) {
            throw $outcome;
        }

        return $outcome;
    }
}
