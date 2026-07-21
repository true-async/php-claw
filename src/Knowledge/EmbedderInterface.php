<?php

declare(strict_types=1);

namespace Claw\Knowledge;

/**
 * Turns text into a vector. The one part of the knowledge base that costs money and waits on a network,
 * and therefore the one worth putting behind a seam: a test drives it with something deterministic, and
 * a different provider — or a local model — replaces it without touching anything above.
 *
 * DIMENSIONS ARE THE IMPLEMENTATION'S TO DECIDE, and they are a real decision rather than a detail.
 * Search here is a brute-force scan (see `dev/design/knowledge-base.md`), so the width of the vector is
 * the cost of every query: measured on this machine, 10 000 chunks scan in 218 ms at 256 dimensions and
 * 1332 ms at 1536. `text-embedding-3-small` returns a shortened vector natively through its `dimensions`
 * parameter, so a narrow one is a supported mode and not a truncation.
 */
interface EmbedderInterface
{
    /**
     * Embed a batch of texts, in order. Batched because the round trip dominates: a note of twenty
     * chunks is one request, not twenty.
     *
     * @param list<string> $texts
     *
     * @return list<list<float>> one vector per input, in the same order
     *
     * @throws \Claw\Exceptions\ClawException when the provider fails or answers unusably
     */
    public function embed(array $texts): array;

    /** How wide this embedder's vectors are — the store checks it, because a mixed index is a broken one. */
    public function dimensions(): int;
}
