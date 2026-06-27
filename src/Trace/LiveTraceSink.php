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
    public function __construct(
        private TraceStore $store,
        private TraceBus $bus,
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

        $this->bus->publish($record, $this->store->lastSeq());   // then notify, with the record and its persisted seq
    }
}
