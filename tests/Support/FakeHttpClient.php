<?php

declare(strict_types=1);

namespace Tests\Support;

use Claw\Http\HttpClientInterface;
use Claw\Http\HttpResponse;

/**
 * Records the last request and returns a preset response — lets agents be tested
 * end to end without the network.
 */
final class FakeHttpClient implements HttpClientInterface
{
    public ?string $lastUrl = null;

    public ?string $lastBody = null;

    /** @var array<int, string> */
    public array $lastHeaders = [];

    public function __construct(private readonly HttpResponse $response)
    {
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        $this->lastUrl = $url;
        $this->lastBody = $body;
        $this->lastHeaders = $headers;

        return $this->response;
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        return $this->response;
    }
}
