<?php

declare(strict_types=1);

namespace Claw\Trace;

/**
 * The live-delivery trace sink: persist through the {@see TraceStore} it composes, then publish the
 * persisted record to the {@see TraceBus} so subscribed SSE streams are pushed to with no polling.
 *
 * It owns the persistence (so there is no separate TraceStore in the sink list to order against): it
 * writes, then asks that same store for the row's `seq` — the autoincrement the record itself does not
 * carry but the dashboard resumes on — and hands the bus the typed record together with that seq.
 *
 * The durable write is the data destination, not a best-effort log, so this sink guards it itself: a
 * persistence failure must not vanish into {@see Tracer::emit}'s blanket swallow (that guard is there
 * for cosmetic sinks). We surface it loudly — on a channel independent of the failed store — yet stay
 * non-fatal, honouring the {@see TraceSinkInterface} contract that a sink never brings the run down.
 */
final readonly class LiveTraceSink implements TraceSinkInterface
{
    private const int JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param (\Closure(string, string): void)|null $publishRoom fans a row out to the run's cross-worker
     *        room (topic, json) so a WS client on any worker gets it; null keeps this SSE/in-process only
     */
    public function __construct(
        private TraceStore $store,
        private TraceBus $bus,
        private ?\Closure $publishRoom = null,
        private string $projectKey = '',
    ) {
    }

    public function write(TraceRecordInterface $record): void
    {
        try {
            $this->store->write($record);                        // persist first — the store owns the connection + seq
        } catch (\Exception $e) {
            // Tracer::emit would otherwise eat this; a lost durable record must not be silent.
            // Report on stderr (not the store that just failed) and skip the publish — never
            // announce a record we did not persist. Loud, but the run goes on.
            fwrite(STDERR, "claw: trace persistence failed, record dropped: {$e->getMessage()}\n");

            return;
        }

        $seq = $this->store->lastSeq();
        $this->bus->publish($record, $seq);                      // in-process push to SSE streams (same worker)

        if ($this->publishRoom !== null) {
            $topic = "project/{$this->projectKey}/run/{$record->runId()}/trace";
            ($this->publishRoom)($topic, \json_encode([
                'topic' => $topic,
                'kind' => 'trace',
                'seq' => $seq,
                'data' => TraceWire::row($record, $seq),
            ], self::JSON));
        }
    }
}
