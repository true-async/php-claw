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
        $this->store->write($record);                            // persist first — the store owns the connection + seq
        $this->bus->publish($record, $this->store->lastSeq());   // then notify, with the record and its persisted seq
    }
}
