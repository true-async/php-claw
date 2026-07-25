<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\HttpException;
use Claw\Exceptions\ToolException;
use Claw\Http\HttpClientInterface;
use Claw\Http\HttpResponse;
use Claw\Tool\HttpTool;
use Claw\Tool\Risk;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeHttpClient;

final class HttpToolTest
{
    #[Test]
    public function aGetReturnsTheStatusContentTypeAndBody(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, 'hello world', ['content-type' => 'text/plain']));
        $tool = new HttpTool($client);

        $result = $tool->handle(['url' => 'https://example.com/thing']);

        Assert::same($result, "HTTP 200 (text/plain)\n\nhello world");
        Assert::same($client->lastUrl, 'https://example.com/thing');   // it actually requested the given URL
        Assert::same($tool->risk(), Risk::Mutating);
        Assert::same($tool->name(), 'http_request');
    }

    #[Test]
    public function aPostSendsTheBodyAndHeaders(): void
    {
        $client = new FakeHttpClient(new HttpResponse(201, '{"ok":true}', ['content-type' => 'application/json']));
        $tool = new HttpTool($client);

        $tool->handle([
            'url' => 'https://api.example.com/items',
            'method' => 'post',   // case-insensitive
            'body' => '{"name":"milk"}',
            'headers' => ['Authorization' => 'Bearer secret', 'Content-Type' => 'application/json'],
        ]);

        Assert::same($client->lastUrl, 'https://api.example.com/items');
        Assert::same($client->lastBody, '{"name":"milk"}');
        Assert::true(\in_array('Authorization: Bearer secret', $client->lastHeaders, true));
        Assert::true(\in_array('Content-Type: application/json', $client->lastHeaders, true));
    }

    #[Test]
    public function anHttpErrorStatusIsDataNotAToolError(): void
    {
        // A 404/500 is the server's answer, not a failure of the call — it comes back for the model to read.
        $tool = new HttpTool(new FakeHttpClient(new HttpResponse(404, 'Not Found')));

        Assert::same($tool->handle(['url' => 'https://example.com/missing']), "HTTP 404\n\nNot Found");
    }

    #[Test]
    public function aMissingUrlIsAToolError(): void
    {
        $tool = new HttpTool(new FakeHttpClient(new HttpResponse(200, '')));

        Assert::true($this->rejected(fn () => $tool->handle([])));
        Assert::true($this->rejected(fn () => $tool->handle(['url' => '   '])));
    }

    #[Test]
    public function anUnsupportedMethodIsAToolError(): void
    {
        $tool = new HttpTool(new FakeHttpClient(new HttpResponse(200, '')));

        Assert::true($this->rejected(fn () => $tool->handle(['url' => 'https://example.com', 'method' => 'DELETE'])));
    }

    #[Test]
    public function aTransportFailureIsAToolError(): void
    {
        // DNS/TLS/timeout — the one thing that IS an error. It must surface as a ToolException, not leak
        // the transport's own exception type.
        $throwing = new class () implements HttpClientInterface {
            public function get(string $url, array $headers = []): HttpResponse
            {
                throw new HttpException('could not resolve host');
            }

            public function post(string $url, string $body, array $headers = []): HttpResponse
            {
                throw new HttpException('could not resolve host');
            }
        };

        Assert::true($this->rejected(fn () => new HttpTool($throwing)->handle(['url' => 'https://nope.invalid'])));
    }

    #[Test]
    public function aHugeBodyIsClipped(): void
    {
        $tool = new HttpTool(new FakeHttpClient(new HttpResponse(200, str_repeat('x', 5000))), maxBytes: 100);

        $result = $tool->handle(['url' => 'https://example.com']);

        Assert::true(str_contains($result, 'truncated at 100 bytes'));
        Assert::true(\strlen($result) < 300);   // the 5000-byte body did not flood the result
    }

    private function rejected(callable $fn): bool
    {
        try {
            $fn();

            return false;
        } catch (ToolException) {
            return true;
        }
    }
}
