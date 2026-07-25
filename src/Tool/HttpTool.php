<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\HttpException;
use Claw\Exceptions\ToolException;
use Claw\Http\HttpClientInterface;

/**
 * Make an HTTP request to any URL and return the response — the one first-class door a solver has to the
 * network. It exists so a step that needs external data (an API, a page) reaches for a TYPED, inspectable
 * `http_request` instead of shelling out to `curl` through `bash`: the capability is explicit in the
 * palette, the call is recorded as itself in the trace, and a run that should NOT touch the network simply
 * does not carry this tool.
 *
 * GET and POST — the transport's two shapes; any URL, no allowlist. An HTTP status, INCLUDING 4xx/5xx, is
 * DATA and comes back in the result for the model to read; only a transport failure (DNS, TLS, a timeout)
 * is an error and surfaces as a {@see ToolException}. The body is clipped so a huge page cannot flood the
 * context.
 */
final readonly class HttpTool implements ToolInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private int $maxBytes = 100_000,
    ) {
    }

    public function name(): string
    {
        return 'http_request';
    }

    public function description(): string
    {
        return 'Make an HTTP request to any URL and return its status and body — use it to FETCH external '
            . 'data (an API, a web page) or to SEND data. `url` is required; `method` is GET (default) or '
            . 'POST; `headers` is an optional name→value map; `body` is the request body for POST. An HTTP '
            . 'status such as 404 or 500 comes back as DATA in the result (read the status line), not as an '
            . 'error; only a network failure fails the call.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'The absolute http/https URL to request.'],
                'method' => ['type' => 'string', 'enum' => ['GET', 'POST'], 'description' => 'HTTP method; defaults to GET.'],
                'headers' => [
                    'type' => 'object',
                    'description' => 'Request headers as a name→value map, e.g. {"Authorization": "Bearer …"}.',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'body' => ['type' => 'string', 'description' => 'Request body, sent with a POST.'],
            ],
            'required' => ['url'],
        ];
    }

    public function risk(): Risk
    {
        // Any URL is reachable and a POST changes state somewhere out there — the same class as the shell
        // it replaces for this job.
        return Risk::Mutating;
    }

    public function handle(array $input): string
    {
        $url = trim((string) ($input['url'] ?? ''));

        if ($url === '') {
            throw new ToolException("http_request: 'url' is required");
        }

        $method = strtoupper(trim((string) ($input['method'] ?? 'GET')));

        if ($method !== 'GET' && $method !== 'POST') {
            throw new ToolException("http_request: 'method' must be GET or POST, got '{$method}'");
        }

        $headers = $this->headerLines($input['headers'] ?? []);

        try {
            $response = $method === 'POST'
                ? $this->http->post($url, (string) ($input['body'] ?? ''), $headers)
                : $this->http->get($url, $headers);
        } catch (HttpException $e) {
            throw new ToolException("http_request: could not reach {$url} — {$e->getMessage()}");
        }

        $body = $response->body;
        $clipped = \strlen($body) > $this->maxBytes
            ? substr($body, 0, $this->maxBytes) . "\n… [truncated at {$this->maxBytes} bytes]"
            : $body;

        $type = $response->headers['content-type'] ?? '';
        $head = "HTTP {$response->status}" . ($type !== '' ? " ({$type})" : '');

        return "{$head}\n\n{$clipped}";
    }

    /**
     * Turn the model's `headers` — a name→value map, or a raw list of "Name: value" lines — into the
     * header lines the transport takes. Anything that is not an array is simply no headers.
     *
     * @return array<int, string>
     */
    private function headerLines(mixed $headers): array
    {
        if (!\is_array($headers)) {
            return [];
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = \is_int($name) ? (string) $value : $name . ': ' . (string) $value;
        }

        return $lines;
    }
}
