<?php

declare(strict_types=1);

namespace Claw\Trace;

use Async\Channel;

/**
 * An in-process pub/sub for live trace, one topic per run id. A run's {@see LiveTraceSink} publishes
 * each persisted record here; the dashboard's SSE handlers subscribe and are pushed to — so the live
 * stream needs no polling. Only runs executing IN this server process publish; a stream over any other
 * run simply gets no live events (it still replays the journal from the db).
 *
 * Delivery is best-effort and non-blocking ({@see publish()} uses sendAsync), so a slow or vanished
 * subscriber can never stall the run. A dropped event leaves a gap the SSE handler heals from the db by
 * seq (it backfills when it sees a discontinuity), and a reconnect replays from `Last-Event-ID` anyway —
 * the durable journal, not this bus, is the source of truth.
 */
final class TraceBus
{
    /** Per-subscriber buffer: a burst of records queues here rather than blocking the run. */
    private const int CAPACITY = 1024;

    /** @var array<string, array<int, Channel<array<string, mixed>>>> runId → subscriberId → channel */
    private array $subscribers = [];

    private int $nextId = 0;

    /**
     * Subscribe to a run's live trace. Returns the channel to recv formatted rows on, plus an
     * unsubscribe closure the caller MUST run when it stops listening (so the topic does not leak).
     *
     * @return array{0: Channel<array<string, mixed>>, 1: \Closure(): void}
     */
    public function subscribe(string $runId): array
    {
        $id = $this->nextId++;
        /** @var Channel<array<string, mixed>> $channel */
        $channel = new Channel(self::CAPACITY);
        $this->subscribers[$runId][$id] = $channel;

        $unsubscribe = function () use ($runId, $id): void {
            unset($this->subscribers[$runId][$id]);
            if (($this->subscribers[$runId] ?? []) === []) {
                unset($this->subscribers[$runId]);
            }
        };

        return [$channel, $unsubscribe];
    }

    /**
     * Push one formatted trace row to every subscriber of a run. Non-blocking: a full buffer (a slow
     * client) drops the row rather than suspend the run — the SSE handler backfills the gap from the db.
     *
     * @param array<string, mixed> $row the same shape {@see \Claw\Server} serializes a trace db row to
     */
    public function publish(string $runId, array $row): void
    {
        foreach ($this->subscribers[$runId] ?? [] as $channel) {
            $channel->sendAsync($row);
        }
    }
}
