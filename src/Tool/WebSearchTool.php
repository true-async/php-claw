<?php

declare(strict_types=1);

namespace Claw\Tool;

use Claw\Exceptions\ToolException;
use Claw\Http\HttpClientInterface;

/**
 * Search the web by QUERY and get back ranked results — title, URL, snippet — for when the useful URL is
 * not known in advance. Distinct from {@see HttpTool} (`http_request`), which needs a URL you already
 * have: this is the "I don't know where to look yet" half, the search→fetch pair every capable agent
 * ships. READ-only (a network read; it changes nothing).
 *
 * The backend is DuckDuckGo's keyless HTML endpoint, parsed here — no API key, no account. That makes it
 * best-effort: it can be rate-limited or its markup can drift, in which case the tool says so rather than
 * inventing results.
 */
final readonly class WebSearchTool implements ToolInterface, DeferredToolInterface
{
    private const string ENDPOINT = 'https://html.duckduckgo.com/html/';

    public function __construct(
        private HttpClientInterface $http,
        private int $maxResults = 6,
    ) {
    }

    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Search the web for a query and return ranked results (title, URL, snippet). Use this when '
            . 'you do NOT already know the URL; use http_request to fetch a page you do. Optional '
            . '"max_results".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'What to search for'],
                'max_results' => ['type' => 'integer', 'description' => 'How many results to return (default 6)'],
            ],
            'required' => ['query'],
        ];
    }

    public function searchTags(): array
    {
        return ['web', 'search', 'internet', 'google', 'find', 'lookup', 'online', 'www'];
    }

    public function effects(): array
    {
        return [Effect::Read];
    }

    public function risk(): Risk
    {
        return Risk::Safe;
    }

    public function handle(array $input): string
    {
        $query = trim((string) ($input['query'] ?? ''));

        if ($query === '') {
            throw new ToolException('web_search: "query" is required');
        }

        $max = min(15, max(1, (int) ($input['max_results'] ?? $this->maxResults)));
        $url = self::ENDPOINT . '?q=' . rawurlencode($query);

        try {
            $response = $this->http->get($url, ['User-Agent: Mozilla/5.0 (compatible; claw/1.0)']);
        } catch (\Exception $e) {
            throw new ToolException("web_search: could not reach the search backend — {$e->getMessage()}");
        }

        if ($response->status !== 200) {
            throw new ToolException("web_search: the search backend returned HTTP {$response->status}");
        }

        $results = self::parse($response->body, $max);

        if ($results === []) {
            return "web_search: no results for \"{$query}\" (or the search backend's markup drifted)";
        }

        $lines = [];

        foreach ($results as $i => $result) {
            $line = ($i + 1) . ". {$result['title']}\n   {$result['url']}";
            $lines[] = $result['snippet'] === '' ? $line : "{$line}\n   {$result['snippet']}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * Pull result links (and their snippets, by position) out of the DuckDuckGo HTML page.
     *
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private static function parse(string $html, int $max): array
    {
        preg_match_all('/<a[^>]*class="[^"]*result__a[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s', $html, $links, PREG_SET_ORDER);
        preg_match_all('/<a[^>]*class="[^"]*result__snippet[^"]*"[^>]*>(.*?)<\/a>/s', $html, $snippets, PREG_SET_ORDER);

        $results = [];

        foreach ($links as $i => $link) {
            if (\count($results) >= $max) {
                break;
            }

            $url = self::realUrl($link[1]);
            $title = self::text($link[2]);

            if ($url === '' || $title === '') {
                continue;
            }

            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => isset($snippets[$i]) ? self::text($snippets[$i][1]) : '',
            ];
        }

        return $results;
    }

    /** DuckDuckGo wraps each result URL in a `/l/?uddg=<encoded>` redirect — unwrap it to the real URL. */
    private static function realUrl(string $href): string
    {
        if (preg_match('/[?&]uddg=([^&]+)/', $href, $m) === 1) {
            return urldecode($m[1]);
        }

        return str_starts_with($href, '//') ? "https:{$href}" : $href;
    }

    private static function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5));
    }
}
