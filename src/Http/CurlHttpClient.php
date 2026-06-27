<?php

declare(strict_types=1);

namespace Claw\Http;

use Claw\Exceptions\HttpException;
use Composer\CaBundle\CaBundle;

/**
 * Single HTTP request via curl. Non-blocking under the TrueAsync reactor: a
 * `curl_exec()` inside a coroutine is driven by the reactor and yields.
 *
 * No retries here — retries are cause-aware and live in the agent's send()
 * (AbstractAgent). Throws only on a transport failure; every HTTP status is
 * returned as data.
 */
final class CurlHttpClient implements HttpClientInterface
{
    private readonly string $caBundle;

    public function __construct(private readonly int $timeoutMs = 600_000)
    {
        // A CA bundle that exists on every OS: composer/ca-bundle finds the
        // system one (Linux/macOS) or falls back to a bundled cacert.pem
        // (Windows ships none for curl by default — hence the TLS failures).
        $this->caBundle = CaBundle::getSystemCaRootBundlePath();
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        return $this->send('POST', $url, $body, $headers);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->send('GET', $url, null, $headers);
    }

    /**
     * @param array<int, string> $headers
     */
    private function send(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        $responseHeaders = [];

        $handle = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $header) use (&$responseHeaders): int {
                $colon = strpos($header, ':');

                if ($colon !== false) {
                    $name = strtolower(trim(substr($header, 0, $colon)));
                    $responseHeaders[$name] = trim(substr($header, $colon + 1));
                }

                return strlen($header);
            },
        ];

        if (is_dir($this->caBundle)) {
            $options[CURLOPT_CAPATH] = $this->caBundle;
        } else {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($errno !== 0) {
            throw new HttpException("HTTP transport error: {$error}");
        }

        return new HttpResponse($status, (string) $raw, $responseHeaders);
    }
}
