<?php

declare(strict_types=1);

namespace Tests\Tool;

use Claw\Exceptions\ToolException;
use Claw\Http\HttpResponse;
use Claw\Tool\Effect;
use Claw\Tool\WebSearchTool;
use Testo\Assert;
use Testo\Test;
use Tests\Support\FakeHttpClient;

final class WebSearchToolTest
{
    private const string DDG_HTML = <<<'HTML'
        <div class="result results_links">
          <a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fmilk&amp;rut=abc">Milk Prices Today</a>
          <a class="result__snippet" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fmilk">Cheap <b>milk</b> near you.</a>
        </div>
        <div class="result results_links">
          <a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.org%2Fdairy&amp;rut=def">Dairy Index</a>
          <a class="result__snippet" href="#">The national dairy index.</a>
        </div>
        HTML;

    #[Test]
    public function parsesTitlesUnwrappedUrlsAndSnippets(): void
    {
        $tool = new WebSearchTool(new FakeHttpClient(new HttpResponse(200, self::DDG_HTML)));

        $out = $tool->handle(['query' => 'milk prices']);

        Assert::true(str_contains($out, 'Milk Prices Today'));
        Assert::true(str_contains($out, 'https://example.com/milk'));   // uddg redirect unwrapped + decoded
        Assert::true(str_contains($out, 'Cheap milk near you'));        // tags stripped, entities decoded
        Assert::true(str_contains($out, 'Dairy Index'));
    }

    #[Test]
    public function itQueriesTheSearchEndpointWithTheEncodedQuery(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, self::DDG_HTML));

        new WebSearchTool($client)->handle(['query' => 'milk prices']);

        Assert::true(str_contains((string) $client->lastUrl, 'q=milk%20prices'));
    }

    #[Test]
    public function maxResultsCapsTheList(): void
    {
        $out = new WebSearchTool(new FakeHttpClient(new HttpResponse(200, self::DDG_HTML)))
            ->handle(['query' => 'milk', 'max_results' => 1]);

        Assert::true(str_contains($out, 'Milk Prices Today'));
        Assert::false(str_contains($out, 'Dairy Index'));   // capped to one

        Assert::same(new WebSearchTool(new FakeHttpClient(new HttpResponse(200, '')))->effects(), [Effect::Read]);
    }

    #[Test]
    public function anEmptyQueryIsAToolError(): void
    {
        $threw = false;

        try {
            new WebSearchTool(new FakeHttpClient(new HttpResponse(200, '')))->handle(['query' => '  ']);
        } catch (ToolException $e) {
            $threw = str_contains($e->getMessage(), '"query" is required');
        }

        Assert::true($threw);
    }

    #[Test]
    public function aNon200FromTheBackendIsAToolError(): void
    {
        $threw = false;

        try {
            new WebSearchTool(new FakeHttpClient(new HttpResponse(429, 'rate limited')))->handle(['query' => 'x']);
        } catch (ToolException $e) {
            $threw = str_contains($e->getMessage(), 'HTTP 429');
        }

        Assert::true($threw);
    }
}
