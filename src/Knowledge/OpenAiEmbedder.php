<?php

declare(strict_types=1);

namespace Claw\Knowledge;

use Claw\Exceptions\ClawException;
use Claw\Http\HttpClientInterface;

/**
 * Embeddings from an OpenAI-compatible `/embeddings` endpoint — the same base URL and key the agent
 * already uses, because a project that can talk to a model can talk to its embedder.
 *
 * NARROW VECTORS ON PURPOSE. Search is a brute-force scan (see {@see KnowledgeIndex}), so the width of
 * a vector is the cost of every query: measured, 10 000 chunks scan in 218 ms at 256 dimensions and
 * 1332 ms at 1536. `text-embedding-3-small` shortens natively through the `dimensions` parameter — a
 * supported mode, not a truncation — so this asks for 256 and gets a store that stays usable without a
 * vector index to maintain.
 *
 * @internal to {@see KnowledgeBase}, apart from being CONSTRUCTED at the composition root — the base URL
 *           and the key are configuration, and configuration is the run pipeline's to hold.
 */
final readonly class OpenAiEmbedder implements EmbedderInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
        private string $apiKey,
        private string $model = 'text-embedding-3-small',
        private int $dimensions = 256,
    ) {
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $response = $this->http->post(
            rtrim($this->baseUrl, '/') . '/embeddings',
            (string) json_encode([
                'model' => $this->model,
                'input' => $texts,
                'dimensions' => $this->dimensions,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
        );

        if (!$response->isOk()) {
            throw new ClawException("embeddings: the provider answered {$response->status}");
        }

        $data = $response->json()['data'] ?? null;

        if (!\is_array($data)) {
            throw new ClawException('embeddings: the provider returned no data');
        }

        // BY INDEX, not by arrival. The API documents an `index` field precisely because the order of
        // the array is not promised, and a batch reordered silently would attach every chunk's vector to
        // a different chunk — a base that retrieves confidently and wrongly, with nothing to see.
        $vectors = [];

        foreach ($data as $entry) {
            if (!\is_array($entry) || !\is_array($entry['embedding'] ?? null)) {
                throw new ClawException('embeddings: an entry carried no vector');
            }

            $at = \is_int($entry['index'] ?? null) ? $entry['index'] : \count($vectors);
            $vectors[$at] = array_values(array_map(floatval(...), $entry['embedding']));
        }

        ksort($vectors);
        $ordered = array_values($vectors);

        if (\count($ordered) !== \count($texts)) {
            throw new ClawException(
                'embeddings: asked for ' . \count($texts) . ' vectors and got ' . \count($ordered),
            );
        }

        return $ordered;
    }
}
