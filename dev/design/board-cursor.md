# Board cursor — a multi-worker numbered board feed (TODO 1d, folding in 1c)

## The problem

The board (a project's issue list) is pushed live over two transports: SSE
(`issuesStream()`) and a WebSocket room (`broadcastBoards()` + the `wsSubscribe()` bootstrap). A
run trace has one shared producer — `TraceBus` pushes, `TraceWire::row()` fixes the wire shape —
so a replayed row and a live one are byte-identical on both paths and every row carries a
monotonic `seq` the client de-dupes by. **The board has no equivalent.** `issuesStream()` and
`broadcastBoards()` each carry their own copy of poll-and-diff, and a board frame carries no
ordering key. That is the residual race left by 1b: a late subscriber's bootstrap dump and a
concurrent live change on the same socket have nothing to order them by, so a stale frame can
overwrite a fresh one.

## Two load-bearing facts

1. **The board seq cannot come from the database.** A board frame is a *derived* view
   (`Server::issues()`, `Server.php:653`) — issue row plus the latest run's done-count, token
   totals, artifacts and strategy — and it changes mostly from run activity, not from an
   `issues` row edit. There is no column to key off, the way `trace.seq` is a durable
   autoincrement.
2. **The server is multi-worker by design.** `enableRooms()` is on (`Server.php:129`) precisely
   so a room publish crosses worker threads (`setWorkers(n>1)`; the config + handler set are
   replicated per worker via `transfer_obj`). So there is no single in-process counter either: a
   socket handled in worker W runs against W's *own* replicated `Server`, with its own memory.

Together these kill the obvious design (one global counter, in memory or in the DB). Numbering
must be **per-producer**, and ordering across producers must ride a key every producer shares.
That key is the wall clock: worker threads share one process clock (`microtime`, as
`Tracer.php:231` already uses).

## The model: announce, not poll (this is where 1c folds in)

Under `workers>1` the poll model is incoherent: either every worker's poller redundantly derives
and emits every issue (N× the traffic), or one poller must be elected — and pool mode gives
nowhere clean to run it (a parent-spawned coroutine is not scheduled while `start()` awaits the
pool, and there is no worker-index API to elect a leader). Item 2 already made the server the
sole DB writer, so a board change now happens *inside* a server coroutine that can speak for
itself. So:

**The worker that makes a board-relevant change announces it** — derives the changed issue's
frame and publishes it to the project's room. No poll tick. This is exactly what TODO 1c
prescribes after item 2 ("the write announces itself in-process; the tick is gone"), and
multi-worker makes it not optional. `broadcastBoards()` is deleted.

## The wire contract — the `TraceWire` analog

Every board frame, from every producer and every transport, has one shape:

```
{ producerId: string, seq: int, tsMs: int, kind: 'issue'|'issue-removed', data: {...} }
```

- **`tsMs`** — the instant the frame's data was *derived* (when its producer read the DB). The
  **cross-producer freshness key**: all workers read the same committed DB, so a later read
  reflects fresher-or-equal state. The client keeps the max `tsMs` per issue id; a frame with a
  lower `tsMs` is stale and dropped.
- **`(producerId, seq)`** — the producer's own monotonic counter, `+1` per emitted frame,
  contiguous. Two jobs only: exact de-duplication (the same frame delivered twice), and loss
  detection (a gap in a producer's seq = a dropped frame → the client re-bootstraps, because
  room `publish()` drops on backpressure rather than blocking).
- **`producerId`** — opaque, generated per producer (`random_bytes`); no worker-index API
  exists and the client needs only to tell producers apart, not to number them.

`seq` orders *within* one producer; `tsMs` orders *across* producers. Neither alone is enough:
seq is incomparable across workers, and `tsMs` ties (same ms) need a stable tiebreak.

## Ordering — why this closes the 1b race

The only cross-producer contention on a single issue is **bootstrap-vs-live**: a client connects
in worker W, W derives issue 5 for the bootstrap while worker W2 (running issue 5's solver)
announces a live change to the room. Their seqs are incomparable. `tsMs` resolves it:

- The client subscribes to the room at t0 (receives frames published after t0), then W reads the
  DB for the bootstrap at `t_read > t0`.
- W2's live frame reflects a mutation committed at `t_mut`; its `tsMs ≈ t_mut` (W2's derive).
- If `t_mut < t_read`, the bootstrap already includes the mutation and is fresher → its higher
  `tsMs` wins. If `t_mut > t_read`, the live frame is fresher → its higher `tsMs` wins. Either
  way, max-`tsMs` picks the state that saw more of the DB. Correct regardless of arrival order.

A backstop keeps this simple: **at most one active run per issue** is already enforced (a
concurrent start is rejected 409, `Server.php:1140`). So an issue's *live* changes have a single
producer at a time (its run's worker); producers for one issue change only between runs, which
never overlap. Bootstrap-vs-live is the only two-producer moment on one issue, and `tsMs` is
built for exactly it.

## The client contract (lives in `true-async/php-claw-ui`, recorded here so both sides agree)

- Per issue id, keep the max `tsMs` applied; apply a frame iff its `tsMs` is greater (a
  `issue-removed` with a higher `tsMs` drops the card). Ties (equal `tsMs`) keep the existing
  state — benign, since a tie means two near-simultaneous reads of the same DB.
- Track per `producerId` the last `seq`; an exact `(producerId, seq)` already seen is a
  duplicate → drop. A gap in a producer's seq means a dropped frame → **re-subscribe**
  (re-bootstrap). Cheap and self-correcting.
- On every (re)connect, reset all per-issue and per-producer tracking. Producer counters are
  volatile and per-connection, so a fresh connection is a fresh baseline — this is also why a
  server restart needs no epoch: a restart drops the connection, and the reconnect resets.
- Consequently SSE is **not** resumable across a reconnect by `Last-Event-ID`; it re-bootstraps,
  as it does today.

## Producers

Every emission source is its own producer with its own `BoardFeed` (id + counter + last-sent):

1. **A worker's per-project announcer** (long-lived). On a board-relevant change in that worker
   it numbers the changed issue's frame and publishes to `project/{key}/issues`. `Server` holds
   it memoized per project, `announcer($key)`, exactly as it holds `store($key)` /
   `reader($key)`.
2. **A WS `subscribe`'s bootstrap** (ephemeral). Derives the full board from the DB, numbers each
   frame with `tsMs = now`, sends them directly to the socket (not the room — a direct `send`
   is not subject to room drop). Just another producer that emits the whole board once; `tsMs`
   orders it against everything live.
3. **An SSE connection** (per connection). Cannot join the room (rooms are a WS feature), so it
   stays a DB poller — but it reads the same shared DB, so it sees every worker's committed
   effect by construction, and stamps `tsMs` from the same clock, so its frames interleave
   correctly with any WS client's. It is the robust fallback precisely because it depends on
   nothing but the DB.

The unification 1d wanted is therefore at the **wire form + client contract + derivation code**
(`issueFrame()` + `BoardFeed` + the ordering rule), shared by all of the above — *not* a single
shared counter, which cannot exist across workers. Delivery differs (room push vs HTTP poll)
because it inherently must.

## Coalescing and debounce (1c's controls)

A run touches its issue's board many times a second (each token tick re-derives the totals).
The announcer must not emit a frame per touch. So the announcer **marks an issue dirty** and a
**debounced flush** (`debounceMs`) derives it once the changes settle — a burst coalesces into
one frame, while a lone change still lands in milliseconds. The periodic 2s tick is gone; the
flush is event-driven.

**The dirty signal should hook an existing funnel, not a new scattered rule.** A `markDirty()`
call sprinkled across every mutation site is precisely the "gate that exists on paper only"
this project keeps being bitten by (the false-Done family) — one forgotten call is a silently
stale card. Board-relevant change already flows through two funnels: run progress through
`TraceBus` (every record is published there already), and issue-row edits (create / status /
strategy) through the ledger. The announcer subscribes to `TraceBus` to mark a run's issue
dirty, and the few discrete row-edit sites mark dirty directly. Wiring this without a
forgettable rule is the implementation's first task, and its own note in the design if it grows.

## BoardFeed — the shape

```
final class BoardFeed   // one per producer
{
    // producerId (opaque, random at construction); a seq counter; last-sent snapshot per issue id.
    // stamp(kind, data) — private — returns ['producerId'=>…, 'seq'=>++n, 'tsMs'=>now, 'kind'=>…, 'data'=>…].

    /** Announcer: number one changed issue; [] if identical to its last emit (no-op suppression). */
    public function change(array $issue): array;   // [frame] | []

    /** Announcer: number a removal. */
    public function remove(int $id): array;        // [frame]

    /** Poller / bootstrap: diff a full derived board against last-sent, number changed + removed.
     *  The first call (empty last-sent) numbers everything — that is the bootstrap. */
    public function diff(array $issues): array;    // list<frame>
}
```

`change()` and `diff()` share `stamp()` and the last-sent map, so the diff — duplicated today
across `issuesStream()` and `broadcastBoard()` — lives in one place. `BoardFeed` holds no DB
handle: it is fed derived arrays and is a pure in-memory state machine, unit-testable without a
project on disk (where the ordering, dedup and tombstone rules get their tests).

## Integration (the diffs)

- **Delete** `broadcastBoards()` / `broadcastBoard()` and the server-global `$sent`. The poll is
  gone.
- **Add** `Server::announcer($key)` (memoized) and the dirty→debounce→flush mechanism; wire its
  dirty signal to `TraceBus` and the row-edit sites.
- **`wsSubscribe()`** board bootstrap — `new BoardFeed()` then `diff($this->issues($key))`, send
  each frame to the socket. Now carries `producerId`/`seq`/`tsMs`.
- **`issuesStream()`** (SSE) — a per-connection `BoardFeed`; on connect `diff(all)`, each tick
  `diff(all)` again (incremental via last-sent). The per-connection `$sentSnapshots` folds into
  `BoardFeed`. Keep `sendable()` slow-client handling (advance nothing on a skipped send), the
  `idleTicks` heartbeat, and the `catch (HttpServerException)` client-vanished handling.

## SOLID notes

- **SRP** — `BoardFeed` owns one producer's numbering + diff; `Server` owns derivation
  (`issueFrame`), transport, and the announcer/producer lifecycle. The diff lives once.
- **DIP** — `BoardFeed` depends on derived arrays pushed in, not on `ProjectStore`; pure and
  testable.
- **ISP / single-writer** — no longer a concern: there is no single writer to protect. Each
  producer owns its own `BoardFeed`; there is no shared mutable state to guard, so the
  by-convention rule the earlier draft worried about disappears.
- **CQS** — `change()` / `diff()` both mutate (advance seq, update last-sent) and return the
  frames. A deliberate exception: they are diffs ("apply, and tell me what changed"); splitting
  would force the caller to re-scan.
- **No interface.** One implementation; add a `BoardFeedInterface` only if a second numbering
  strategy ever appears.

## Open decisions (for Edmond)

1. **The dirty signal's exact wiring** — `TraceBus` subscription for run progress plus explicit
   marks at the row-edit sites. Reusing `TraceBus` avoids a scattered `markDirty`, but couples
   the announcer to the trace funnel. Agree the wiring before it is built (it is the one place a
   forgotten path becomes a stale card).
2. **`debounceMs` value** — small (a lone change must still feel live). A default around 100–200
   ms; name the number when we measure, not now.
3. **`tsMs` tie-break** — equal-ms frames for one issue keep the existing state. Sub-millisecond
   collisions on a single issue are near-impossible (one active producer), so no
   `(producerId)` tiebreak is added. Flag if this proves optimistic.
