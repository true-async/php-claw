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
 * The bus carries the typed {@see TraceRecordInterface} together with its persisted `seq` — the wire
 * formatting belongs to the SSE edge ({@see \Claw\Server}), not here. Delivery is best-effort and
 * non-blocking ({@see publish()} uses sendAsync), so a slow or vanished subscriber can never stall the
 * run. A dropped event leaves a gap the SSE handler heals from the db by seq, and a reconnect replays
 * from `Last-Event-ID` — the durable journal, not this bus, is the source of truth.
 */
final class TraceBus
{
    /** Per-subscriber buffer: a burst of records queues here rather than blocking the run. */
    private const int CAPACITY = 1024;

    /** @var array<string, array<int, Channel<array{0: TraceRecordInterface, 1: int}>>> runId → spl_object_id → channel */
    private array $subscribers = [];

    /**
     * Subscribe to a run's live trace. Returns the channel to recv `[record, seq]` pairs on, plus an
     * unsubscribe closure the caller MUST run when it stops listening (so the topic does not leak).
     *
     * @return array{0: Channel<array{0: TraceRecordInterface, 1: int}>, 1: \Closure(): void}
     */
    public function subscribe(string $runId): array
    {
        /** @var Channel<array{0: TraceRecordInterface, 1: int}> $channel */
        $channel = new Channel(self::CAPACITY);
        $id = spl_object_id($channel);   // the channel is its own identity — no counter to keep
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
     * Push one persisted record (with its db seq) to every subscriber of its run. Non-blocking: a full
     * buffer (a slow client) drops it rather than suspend the run — the SSE handler heals the gap by seq.
     */
    public function publish(TraceRecordInterface $record, int $seq): void
    {
        foreach ($this->subscribers[$record->runId()] ?? [] as $channel) {
            $channel->sendAsync([$record, $seq]);
        }
    }
}
