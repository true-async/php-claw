# PLAN

Updated: 2026-08-16 · Active: S2 (S1's remainder waits on Sage; S6 is done and removed)

The stages below S0 were renumbered on 2026-08-14, when the control transport
changed from `ThreadChannel` to rooms. Only S0 had been closed, and nothing
outside this file referenced the old numbers.

## S0 — The runtime can stop  [done]

Goal: `claw serve` stops on demand, at the worker count it actually runs.
Done when: `graceful_shutdown()` ends a single-worker server.

- [x] S0.1 graceful_shutdown() at setWorkers(1)
      done: 10 clean exits in 10, against 10 hangs in 10 before
      tier: T2 · role: —
      handoff: cancellation woke start() without releasing the listeners, so a
        live libuv handle kept uv_run alive. start() now runs the stop teardown
        when it wakes while still running. true-async/server PR #149, test
        061-single-worker-graceful-shutdown.phpt.

## S1 — Rooms work in another thread  [in progress]

Upstream, in `/home/edmond/true-async-server` (`true-async/server`). Everything
php-claw does afterwards rides this, so it comes first.

Goal: a room is a two-way bus between any threads of the process, not a one-way
fan-out into WebSocket sockets.
Done when: a task in an `Async\ThreadPool` both publishes to a room and receives
from it, with no `ThreadChannel` anywhere.

### The design, as the code stands today

A room today is outbound only. `Room` holds `{ zval server_zv; zend_string *topic; }`
(`src/http_server_class.c:6036`) and reaches the hub through the server object.
`ws_topic_subscribe()` takes a `ws_session_t *` (`include/websocket/ws_topic_tree.h:70`),
so the only consumer of a topic is a WebSocket connection that called
`WebSocket::subscribe()`. Four changes make it two-way and thread-portable:

1. **The room carries the hub, not the server.** `server_zv` exists only to reach
   `server->topic_hub`; holding it means transferring an `HttpServer` — shells,
   clones, listeners — to move a room. Hold `topic_hub_t *` and take a reference
   (`topic_hub_addref`, added in PR #147). A room is then two fields: a pointer
   into persistent shared memory and a topic string.
2. **The room gets a `transfer_obj` handler.** The class is `@not-serializable`
   and registers no handler, so a task closure capturing a room receives an
   uninitialized object — the same failure a live `\PDO` has. COPY takes a hub
   reference and copies the topic into persistent memory; LOAD rebuilds the room
   in the destination thread from those two fields. This is the pattern PR #147
   applied to the hub: the reference is taken where the pointer is copied.
3. **Publishing needs no change.** `topic_hub_publish()` tolerates a thread with
   no `ws_local_t` — local delivery yields 0 and the fan-out over worker slots
   proceeds under `hub->admin` (`src/websocket/topic_hub.c:768`). A pool thread
   holding a room can publish today; 158 of 158 frames were measured through
   that path.
4. **Receiving is the new mechanism.** The tree's subscriber becomes a tagged
   target — a WS session, or a queue owned by a coroutine. A thread that wants to
   receive calls `topic_hub_attach()` for its slot, mailbox and interest filter,
   without which remote publishes never reach it. The mailbox drain hands a queue
   target its payload as a PHP-visible awaitable instead of writing wslay frames.

Shape of the API this yields:

```php
$room = $server->room('run/42/control');
$room->subscribe();
$msg = $room->recv(timeoutMs: 500);   // ?string, null on timeout
$room->unsubscribe();
```

**Control never uses `publish()`.** Its contract reports `dropped` for full
remote mailboxes, and a dropped control message is a run that never stops or a
person's answer that vanishes. `Room::send()` / `trySend()` exist for this — they
park a full target — and every command goes through them.

- [x] S1.1 A room carries the hub, not the server
      done: a room still publishes after the HttpServer object that minted it is
        released
      tier: T2 · role: Critic
      Critic 2026-08-14: the new test passes with or without the addref, because
        a use-after-free on the freed hub still returns 0/0/0. Measured instead:
        with the addref removed, valgrind reports Invalid reads and an unadorned
        run hangs; with it, 0 errors. The test says so in a comment, and the
        sanitizer lane it really wants moved to S1.5.
      Critic 2026-08-14: HttpServer::room() refused a mint that used to work
        (a room minted before start()), and its message named addWebSocketHandler,
        which does not create the hub. Accepted: minting before start() now
        creates the hub itself, and only a running server without one refuses.
      Critic 2026-08-14: stubs/HttpServer.php promised the room keeps the server
        alive — the exact sentence this step invalidates. Rewritten, and Room's
        own docblock now states that it outlives its server.
      Critic 2026-08-14: the knob snapshot cannot go stale — the config is locked
        at HttpServer construction. Accepted as a clause in the comment. Naming
        fixes taken (room_mint, room over r, != NULL); renaming `r` in the two
        pre-existing send paths declined — untouched code, and one of the two
        would have gone out of step with the other.
      handoff: room_object is { topic_hub_t *hub; zend_string *topic;
        room_retry_cfg_t retry; } — the hub reference is taken in room_mint() and
        dropped in room_free(). true-async/server PR #150, test
        068-room-outlives-server.phpt. run-tests loads the INSTALLED extension:
        pass -d extension_dir=<repo>/modules or the suite measures an old binary.
- [x] S1.6 A transit shell can release what it owns
      Numbered after the others, placed here because this is where the work runs:
        S1.2 cannot ship without it.
      done: valgrind reports zero definitely-lost bytes for a room transferred
        into a pool task
      tier: T2 · role: Sage (decided) → Critic
      Sage 2026-08-14: transfer_obj has TRANSFER and LOAD and no third kind, so
        C state a handler puts in the transit shell is unreachable at release —
        thread_release_transferred_object walks properties, drops the class name
        and pefrees, never free_obj. Two handlers already carry hand-made
        prostheses for the missing kind (the Closure snapshot special case in the
        walker, and http_server_release_worker_shell). Verdict: add
        ZEND_OBJECT_TRANSFER_RELEASE to the php-src fork's enum and dispatch it
        from the php-async walker, resolving the class by name without autoload;
        the handler frees only its C prefix and returns NULL. A hub registry was
        rejected: it moves the ownership question rather than answering it, into
        a subsystem whose correctness rests on there being no shared registry.
        Shipping the leak was rejected because a by-design leak hides future
        ones. Final.
      Two small PRs: php-src fork (enum member + contract comment), php-async
        (dispatch in thread_release_transferred_object). Retiring the two
        prostheses is a later step, not this one.
      handoff: true-async/php-src#20 (enum + the two Zend handlers refusing the
        kind) and true-async/php-async#232 (dispatch + three handlers). #232
        stays red until #20 lands: its runners build php-src from the true-async
        branch. Sage counted six transfer_obj handlers; there are eight —
        WeakReference and WeakMap live in Zend, and leaving them out killed six
        thread-transfer tests with Termsig. Merge order is php-src → php-async →
        server, and php-src goes into true-async first, true-async-stable after.

- [x] S1.2 A room transfers into a pool task
      done: a task submitted with a captured room publishes, and a WebSocket
        client subscribed to that topic receives it
      tier: T2 · role: Critic
      Critic 2026-08-14 (raised on S1.1): the room's topic is still a
        request-allocated zend_string, so a transferred room would free a
        source-thread string on the destination thread. Item 2 of this stage's
        design already requires the persistent copy — this is the step that owes
        it, and ws_cmd_t is the shape to follow. Done: TRANSFER writes a
        persistent copy, LOAD a thread-local one.
      Sage 2026-08-14: fit to ship only after three changes — the transit-shell
        leak (became S1.6), default_fn sized by the default rather than a literal
        sizeof (a declared property would have overflowed the memcpy), and the
        stub saying that subscriberCount() returns 0 from a thread that never
        attached, indistinguishable from an empty room. All three applied. Final.
      handoff: room_transfer_obj in src/http_server_class.c; test
        069-room-transfers-to-pool.phpt, which fails without the handler with
        "Room is uninitialized" rather than a crash — the S1.1 guard catches it.
        Commits 074be66 and 173f946 on feat/room-transfers; the PR waits for
        php-src#20, without which the server does not compile.
- [x] S1.3 A thread that is not an HTTP worker subscribes and receives
      done: (a) a pool task recv(500)s a message published from the main thread;
        (b) after unsubscribe() a second publish does not arrive and charges
        nothing to `dropped`; (c) two rooms subscribed in one thread survive each
        other — unsubscribing the first leaves the second receiving; (d)
        fuzz_ws_topic with the two server-subscriber opcodes runs a fixed corpus
        clean under ASAN
      tier: T2 · role: Critic
      Edmond 2026-08-14: rooms carry control, not a ledger poll. The polling
        alternative (a watchdog reading the run's stop flag every couple of
        seconds) was offered and declined.
      Critic 2026-08-14: the draft's "(A) parallel array leaves the session hot
        path untouched" is false — ws_node_is_empty counts sessions only
        (ws_topic_tree.c:282), so pruning frees a node a server subscriber still
        points at. Taking (B): one tagged subscriber, one copy of the
        mark/tombstone machinery, and topic_mark stays on ws_session_t so the
        expensive half of (B) is not paid.
      Sage 2026-08-14: (B) as a WIDENED ELEMENT — subs becomes an array of
        {ptr, kind} by value, tombstone stays ptr == NULL, and the hot loop gains
        one switch. Delivery to a server subscriber is one extern implemented in
        topic_hub.c, so the ring never enters the tree TU. The API lives on Room
        (subscribe/recv/unsubscribe/lostCount), no TopicSubscription class: the
        subscription is a field the transfer handler deliberately does not carry,
        so each thread's copy subscribes for itself. recv() without a
        subscription THROWS — returning null there is the false-Done shape this
        project has already paid for. A second concurrent recv() throws; one
        waiter per subscription. A subscriber does hear its own publishes;
        excluding the sender belongs in the payload, not in a knob nobody asked
        for. The attachment is ensured, never refcounted, and unsubscribe() never
        detaches — so S1.7 folds into this step, and a pool thread gains a retry
        queue as a side effect, which is what S2 needs for send(). Final.
      Sage 2026-08-14: S1.3's own test must use a TIMEOUT recv — with a timeout
        the waker's timer keeps the loop alive, so this step is testable before
        S1.8 lands. Final.
      Critic 2026-08-14 (on the code): six defects, four on the path this step
        serves. A parked recv held no reference, so unsubscribe() freed the
        subscription under it — measured as 9 invalid accesses, now 0. A
        cancellation landing in the same turn as a delivery returned before the
        payload was handled, destroying a control message and leaking it. recv(0)
        parked forever, because the engine spells "no timer" as 0 — the timeout
        is now signed, negative waits without a deadline. The BUSY check sat
        below the pop, so a second recv stole a parked receiver's message and the
        parked one returned a spurious null. lostCount() reset on unsubscribe
        against its own docblock — the counter moved onto the handle. All fixed
        and covered by test 071. Not taken: one payload copy per server
        subscriber inside the walk (a shared lazy payload belongs with the ring
        work in S1.10; the tree comment no longer claims the walk never
        allocates).
      handoff: ws_subscriber_ref_t {ptr,kind} in ws_topic_tree.c; ws_server_sub
        with the 64-entry ring in topic_hub.c; Room::subscribe/recv/unsubscribe/
        lostCount in http_server_class.c; topic_hub_thread_sweep() called from
        PHP_RSHUTDOWN(http_server). Commit dfcb780 on feat/room-receive.
        MEASUREMENT TRAP: valgrind sees none of this without USE_ZEND_ALLOC=0 —
        the arena hides every use-after-free in PHP-visible memory.

- [x] S1.8 A parked recv() is work, not a deadlock
      done: a pool task parked in recv() with no timeout received its message
        after 70 s; without the keepalive the same park is cancelled with
        DeadlockError 0.4 ms after parking
      tier: T2 · role: Critic
      Critic 2026-08-14: async_plain_event's start() is a no-op and the mailbox
        trigger is uv_unref'd, so a thread whose only coroutine waits on a room
        looks idle; the scheduler calls that a deadlock and cancels every waiter.
        topic_hub_count() escapes only because it always arms a timeout waker.
        A parked recv must ref the loop for its duration — reactor_pool.c:145 has
        the keepalive precedent.
      Sage 2026-08-14: the wake source IS the mailbox trigger, so park brackets
        thread_mailbox_keepalive(inbox, true/false) around the suspend, counted
        by a parked_recvs field on ws_local_t. The counter is mandatory: uv_ref
        is a flag rather than a counter, so naive bracketing with two parked
        recvs unrefs the loop while the second still waits, and that one is then
        cancelled as a deadlock. Release on the cancellation path too, before the
        rethrow. Idle threads stay mortal — the ref exists only while a recv is
        parked. Final.
      Sage 2026-08-14: measure the DeadlockError latency WITHOUT the keepalive
        first, then park past it with the keepalive — the cancellation fires in
        milliseconds once the loop is idle, so seconds prove it. Final.
      Sage 2026-08-14 (reviewing S1.3): the keepalive turns any "waits forever by
        mistake" into a permanent silent hang, where today it is a fast
        DeadlockError. The negative-timeout clamp went in with S1.3 for exactly
        that reason; check for other paths that park without a deadline before
        adding the keepalive.
      Audit of the deadline-less parks it asked for: send() parks with no
        timeout waker, but its deadline belongs to the retry drainer, whose
        timer is a real handle and is armed before the enqueue returns —
        topic_hub_send refuses rather than suspending when it cannot arm. The
        other one is WebSocket::recv() (php_websocket.c), held up by the
        connection's own io handle. Neither depended on the detector.
      Critic 2026-08-14 (on the code): a fatal error longjmps past the release,
        so the trigger stays ref'd and the worker reaches teardown with a live
        handle — the scheduler aborts the PROCESS on "The event loop must be
        stopped". Measured: SIGABRT 3 of 3 runs, and for a timed recv too, which
        did not have that failure mode before. Taken: zend_try around the
        suspend, the same guard HttpServer::start() carries, plus test 073.
      Critic 2026-08-14: the parked_recvs counter is dead weight — the reactor
        nests loop_ref_count itself (EVENT_START/STOP_PROLOGUE) and a mutant
        without the field passes 072. Sage's premise for making it mandatory
        ("uv_ref is a flag rather than a counter") is measurably false, so the
        field went out; a role's verdict is a statement, not proof.
      Critic 2026-08-14: local->inbox stayed dangling after the mailbox was
        freed, leaving the keepalive's NULL guard resting on an unstated
        invariant. Taken: detach NULLs it.
      Critic 2026-08-14: while a receiver is parked the whole thread loses its
        deadlock detector, and exit() waits for the park. Measured against the
        engine's own cross-thread primitive — a coroutine parked in
        Async\ThreadChannel::recv() refuses exit() identically — so this is the
        existing contract for a cross-thread waiter, not something rooms
        invented. Named in the PR rather than fixed here.
      handoff: ws_local_recv_keepalive() in topic_hub.c brackets the suspend and
        zend_try carries the release over a bailout; tests 072 (two receivers
        parked at once) and 073 (a fatal under a park). true-async/server PR
        #153. MEASUREMENT TRAP: a probe that formats a float inside a pool task
        loses 68 bytes to php-src's per-thread zend_strtod Bigint freelist,
        which nothing frees for a non-main thread — it is not this code, and it
        cost half a session's probes. Fixed upstream in true-async/php-src#21.
      Measured while chasing that: a pool built without `coroutine: true` runs a
        task synchronously and is not preemptible, so cancel() cannot reach it —
        documented, not a defect. With `coroutine: true` cancel() interrupts a
        task parked in recv() in milliseconds, which is what S2 needs.

- [x] S1.9 A dead task leaves no slot behind
      HALF DONE in S1.3: topic_hub_thread_sweep() exists and runs from
        PHP_RSHUTDOWN(http_server), because without it S1.3's own test leaked the
        mailbox, the attachment and the tree and left an unclosed libuv handle —
        so the order Sage gave (3 before 9) did not survive contact. What remains
        is the bailout half: the sweep still uses the ordinary detach, not the
        order Sage specified for a dead request (unlink first, mark retry entries
        abandoned, close subscriptions without firing their waiters).
      done: a task killed by a fatal error leaves no slot taken and no mailbox
        collecting messages nobody will read
      tier: T2 · role: Critic
      Critic 2026-08-14: every exit path detaches except zend_bailout, and that
        one is not a leak but contagion — the slot stays taken with a live inbox,
        so every later publish charges a dropped against it and every send parks
        and expires on it, for the life of the process. Needs a thread-shutdown
        sweep of ws_locals, not a PHP-level finally.
      Sage 2026-08-14: the sweep hangs off PHP_RSHUTDOWN(http_server) — a pool
        worker runs one request for its whole life, a bailout ends that request,
        and the server module's RSHUTDOWN runs before objects are freed and while
        async's loop still exists. Its order differs from the normal detach in
        three ways: unlink ws_local first, so a pending drain discards instead of
        walking a tree whose sessions may be dead; mark retry entries abandoned
        so no waiter fires; close subscriptions without firing their waiters —
        those events are request memory whose owners are gone. Final.
      Sage 2026-08-14 (reviewing S1.3): the sweep as shipped FIRES a parked
        recv's waiter through topic_hub_unsubscribe. On the normal path that is
        harmless — the scheduler has already settled every coroutine before
        RSHUTDOWN, measured clean under valgrind. After a bailout the coroutine
        is dead while its waiter event is live request memory, so the sweep may
        have upgraded that case from a taken slot to a possible use-after-free.
        The fire cannot simply be removed: test 071's unsubscribe-under-park
        depends on it. This step owes a no-fire close path for the dead-request
        case. Worth folding in: the sweep's correctness rests on this module's
        RSHUTDOWN running before async's, which a comment asserts and nothing
        enforces — a module dependency would make it structural.
      Sage 2026-08-14, open by his own admission: whether disposing the mailbox
        trigger after the scheduler is off completes cleanly cannot be proven
        from source. This step's phpt under valgrind settles it; if the close
        callbacks never run, free the mailbox without disposing the trigger and
        let async's loop teardown close the handle.
      done, second half: after the fatal, a publish and a send() from the main
        thread leave `dropped` and `retry_expired` unmoved, and the valgrind lane
        shows zero definitely-lost.
      done as measured: the slot goes and both counters stay put (test 074); the
        leak criterion was met on a payload balance counter instead of valgrind —
        64 persistent payloads per dead worker before, 0 after — because a worker
        killed by a fatal also leaks ~2.3 KB of php-async's own per-worker request
        memory (thread_pool_worker_handler, async_new_scope, resume_when), which a
        cleanly finishing worker does not. Zero definitely-lost after a fatal is
        therefore not reachable from this repository today.
      Critic 2026-08-14: the valgrind lane could not see any of this — USE_ZEND_ALLOC=0
        replaces the allocator that enforces memory_limit, so a test whose fatal is
        an exhausted limit produces no fatal there, passes, and measures nothing.
        Tests 073 and 074 now bail out through E_USER_ERROR.
      Critic 2026-08-14: the first version of test 074 passed on the unmodified
        build — it split the parked receiver and the unread messages across two
        rooms, so the refcount reached zero and the ring freed itself. Reshaped to
        the leaking form: the messages pile up in the mailbox of a worker that has
        stopped turning its reactor, and the teardown drains them into a ring whose
        receiver is dead.
      Critic 2026-08-14: Room::subscriberCount() had the same bailout hole as
        recv() — no zend_try, so the settle was skipped and the reply fired into a
        dead coroutine while a persistent ws_query_t leaked. Fixed with it.
      Critic 2026-08-14: the module dependency on true_async buys nothing it was
        justified by — async's RSHUTDOWN is inert and the reactor is torn down by
        ZEND_ASYNC_ENGINE_SHUTDOWN inside zend_deactivate(), several steps after
        every module's RSHUTDOWN. Removed, and the older comment claiming module
        order was load-bearing corrected to what was measured.
      Critic 2026-08-14: start()'s own bailout exit is a dead request by the same
        definition and still took the waking teardown. Taken.
      handoff: topic_hub_detach_ex(hub, request_over) in topic_hub.c; the sweep and
        start()'s bailout pass true, stop() and the failed-start paths false. Tests
        073/074. true-async/server PR #154.

- [x] S1.10 A control message that is lost says so
      BUILT IN S1.3: the ring, drop-oldest, the sub_overflow counter and a
        monotonic lostCount() exist and are proven locally by test 071 (70 into a
        64-deep ring leaves 6 lost, surviving an unsubscribe/subscribe pair).
      done: the same overflow across a thread boundary — a pool task that never
        recvs, a publisher on the main thread — leaves getRuntimeStats()
        .ws_sub_overflow == k with `dropped` unmoved, and recv() then yields the
        newest 64; plus the shared lazy payload S1.3 deferred here, so a publish
        to N server subscribers on one node copies the body once rather than N
        times (Sage: acceptable to defer, unacceptable to forget)
      tier: T2 · role: Critic
      Critic 2026-08-14: the draft drops on ring overflow into the existing
        `dropped` counter, which re-opens the hole the "control never uses
        publish()" rule closed: send() reports delivered=1 for a message the
        subscriber never sees, and the one counter that today means "add rate
        limiting" stops meaning that. Needs its own counter, a sticky lost count
        surfaced by recv(), and `delivered` documented as "reached a mailbox".
      Sage 2026-08-14: ring of 64, and overflow drops the OLDEST — for control
        the newest command is the authoritative one, and drop-newest would keep
        stale commands while discarding the fresh stop. The drain must never
        park. Loss is reported by a monotonic lostCount() that is never reset,
        rather than inline in recv(): two readers cannot then destroy each
        other's evidence. End-to-end acknowledgement stays php-claw's job — the
        run's ledger row settling for stop, the question row flipping for an
        answer; a second delivery protocol inside the extension would need its
        own loss audit. Build the counter and drop-oldest inside S1.3, where the
        ring is written; this step is the surfacing and the proof. Final.
      Critic 2026-08-14: the headline was false in the case nobody measured — a
        publish with both local receivers and a worker to post to allocated TWO
        bodies, because the local walk released its own and the fan-out made
        another. Fixed by seeding the fan-out with the walk's body.
      Critic 2026-08-14: the saving was a hand measurement no test could keep.
        Taken: ws_bodies joins the runtime stats and test 076 asserts one body
        per publish for all three shapes (nobody, local only, local and remote).
      Critic 2026-08-14: 075's 700 ms wait was a hope whose failure mode was a
        WRONG loss count rather than a timeout — at delay(1) it already flaked.
        Replaced with a go-ahead published last on the same mailbox, so FIFO
        makes "all 70 have landed" a guarantee; 9 runs of 9, three under load.
      Critic 2026-08-14: the walk carried both a should_deliver flag and the
        scratch, two spellings of one predicate that a NULL deref away from each
        other. Folded into one. The dead out-of-memory branch went too —
        pemalloc(_, 1) aborts rather than returning NULL.
      handoff: ws_topic_publish takes the walk's one-message scratch; the drain
        seeds it with the body the command already carries, so a cross-thread
        publish copies nothing on arrival. Measured 52 bodies -> 11 over ten
        publishes to four receivers. Tests 075 (loss across threads) and 076 (one
        body per publish). true-async/server PR #155.
- [x] S1.4 Control-grade delivery
      done: a full target parks the sender instead of dropping, the caller can
        tell delivery from timeout, and a send that reached no live worker is
        distinguishable from one that reached a room nobody has joined
      tier: T2 · role: Critic
      Critic 2026-08-14 (raised on S1.1): with the hub alive but every worker
        detached, publish() reports 0/0/0, trySend() returns true and send()
        returns OK with delivered=0 — a control message silently reaching
        nobody. The live-worker count is already walked in
        topic_hub_fanout_locked; the step now has to surface it. Accepted into
        this step's done-condition.
      Critic 2026-08-14 (architecture pass), three contract defects that belong
        to this step because they are all "what did the send actually do":
        (a) interval_ms is taken per call and applied once per THREAD — the first
        reliable send fixes the drainer cadence for the worker's life and a room
        configured with another value silently inherits it; take it at attach
        instead, so the surprise is unexpressible. (b) `delivered` counts remote
        mailboxes only, so a send whose subscribers are all local returns 0 having
        served them; the header, the stub and the enum comment all say otherwise.
        (c) neither status enum can say "cancelled", so the PHP layer re-reads
        EG(exception) in five places to tell a cancellation from a timeout — the
        hub already knows and should return it.
      Critic 2026-08-15 (on the code), the finding that reshaped the step: the
        first pass refused only when NO THREAD was attached, and a thread that
        sends is itself attached — so the refusal could not fire from a worker, a
        WebSocket handler or a run coroutine, which is the entire control-message
        population. A handler sending to a room whose subscriber had gone still
        got a silent 0. Taken: the reliable path refuses whenever it reached no
        target at all, keeping the two causes apart (NO_WORKERS — nothing is
        attached; NO_TARGETS — the workers are there, nobody has joined).
      Critic 2026-08-15: `delivered` sums two units — a remote worker is one
        target however many subscribers sit behind it, and the interest filter is
        a Bloom summary that can hit for a worker whose tree then matches nothing.
        The sum is what the step ordered, so it stays; the over-claims around it
        went (the stub no longer says a 0 means an empty room — a 0 is now a
        throw), and the header states what the number is not.
      Critic 2026-08-15: trySend()'s `false` claimed nothing was delivered, while
        the fan-out runs before the refusal. Doc corrected in both stubs and in
        docs/PLAN_RELIABLE_ROOM_PUBLISH.md, which still described the old
        publish() and trySend() contracts.
      Critic 2026-08-15, declined: ordering the CANCELLED branch after the
        entry's own verdict. It would let a cancelled-and-expired send throw
        RoomDeliveryException over a pending cancellation — the exact thing the
        removed EG(exception) checks prevented. Documented instead: a cancellation
        says nothing about the message, which stays on the retry queue.
      Critic 2026-08-15, declined: hub == NULL reported as NO_WORKERS. A hub that
        does not exist has no workers, and both PHP entry points refuse earlier
        with a clearer message.
      (a) is not observable from PHP and no test claims it is: the config is
        locked at HttpServer construction, so one server could only ever pass one
        interval. The parameter is gone from the two send signatures, which is the
        whole of the fix.
      handoff: topic_hub_publish returns topic_hub_publish_result_t {served,
        workers, posted, dropped}; the send result gained `workers` and the enum
        gained NO_WORKERS / NO_TARGETS / CANCELLED, recv gained CANCELLED. The
        cadence lives on the hub (topic_hub_create -> attach). CONTRACT CHANGES a
        caller sees: send() throws instead of returning 0, trySend() answers false
        there, publish()'s array gained `workers`, and send() counts local
        subscribers. Tests 061/062/074 asserted the old shapes and were updated;
        new test 077. true-async/server PR #156.
- [x] S1.5 The receiving thread detaches on every exit path
      NOTE: S1.11 waits on this step's sanitizer lane — it is the instrument that
        proves the park rewrite, so this step's ASAN-lane-or-admission decides
        whether S1.11 can be executed at all.
      done: a task that throws, is cancelled, or ends normally leaves no slot
        taken and no hub reference held
      tier: T2 · role: Critic
      Critic 2026-08-14 (raised on S1.1): "no hub reference held" is not
        observable from PHP — a leaked or double-dropped reference shows up only
        under a sanitizer, and the phpt suite runs unsanitized (ASAN is on the
        three fuzz targets only). This step owes either an ASAN lane over
        tests/phpt/websocket or an admission that its criterion is asserted
        rather than measured.
      Answered with valgrind rather than ASAN: an ASAN lane needs php-src itself
        rebuilt with the sanitizer, a second full build in CI, while valgrind needs
        an apt package and the options run-tests leaves out. Measured: with one
        topic_hub_release() removed from room_free() the lane reports the hub's
        21,632 bytes as definitely lost and exits 1; unmutated, nine room tests are
        clean in 23 s. run-tests -m ALONE proves nothing here — it wraps the binary
        in `valgrind -q --tool=memcheck` with no leak check, and the mutation
        passed under it.
      The three exit paths were already correct before this step — S1.9's sweep
        covers a throw and a cancellation as it covers a bailout. Measured with
        S1.4's new `workers` figure: the slot comes back within 50 ms in all
        three cases, and a later task on the same worker still subscribes,
        receives and is counted by a send.
      Fixed on the way: test 074's first line could have the doomed worker's fatal
        land inside it (two threads, one stream) — a race the valgrind lane widens
        until it is certain.
      Critic 2026-08-15 (on the code): the done-condition as written is not what
        the change proves, and the wording is the reason — "leaves no slot taken"
        reads per TASK, while the attachment belongs to the THREAD and the test
        asserts the slot is still taken after a task dies. Read it as: a task's
        exit gives back what the task took, the thread's exit gives back the slot.
      Critic 2026-08-15: `workers` cannot see what is left INSIDE an attachment,
        so a subscription leaked per task would pass every assertion. Taken: 078
        polls subscriberCount(), the figure such a leak moves. It found one on the
        spot — a task cancelled while parked keeps its room object, and its
        subscription, until the POOL closes. First written up as "only when another
        task follows"; re-measured 2026-08-15 with a destructor instead of the
        subscriber count (a scatter/gather snapshot that can answer mid-teardown)
        and that qualifier was wrong — a cancelled task's captured objects wait for
        close() either way, while a task that ends normally releases them as it
        goes. Reported as true-async/php-async#233 with a repro that needs no
        rooms; the test asserts what is ours, that the sweep gives them back.
      Critic 2026-08-15: half the lane's evidence was false — run-tests exports
        USE_ZEND_ALLOC=0 itself for valgrind runs and hardcodes the 300 s timeout,
        so only VALGRIND_OPTS is the missing knob. Corrected, along with the claim
        that the list was "all the room tests except two" (it is the ones that need
        no client connection).
      Critic 2026-08-15: a lane that runs nothing passed — run-tests exits 0 when
        every test skips, and dies with status 0 on a mistyped path. The script
        counts what ran; measured against an unusable extension dir, it now fails.
      Critic 2026-08-15: the exclusion of 073/074 hides the class the lane cannot
        see anyway. Measured: with the ring drop removed from the dead-request
        sweep the lane stays green under every leak-kind filter, and with the
        tracked allocator too. Taken, differently: ws_bodies_freed joins ws_bodies
        and the balance is asserted by 074 and 078 — proven by removing
        thread_mailbox_drain_pending, which turns 074 red with 201 allocated
        against 1 freed.
      Critic 2026-08-15: run-tests parallelises by default and caps at 2 under
        valgrind, so on a 2-core runner the lane would race two memcheck'd thread
        pools against their own recv deadlines. Taken: -j1, 44 s.
      handoff: tests/valgrind-rooms.sh holds the lane (VALGRIND_OPTS is the knob
        run-tests will not give you) and names what it does not see. The body
        balance ws_bodies/ws_bodies_freed is the instrument for that half. CI runs
        the lane on the release leg, last, so coverage still comes from the
        ordinary run — measured there at 22 s. New test 078.
        true-async/server PR #157.

OPEN, not a step until Edmond says so — the architecture pass of 2026-08-14 found
one cycle and one overgrown file. A WebSocket session subscribes by taking the
tree out of the hub and driving it directly, while a server receiver goes through
the hub; because the hub is off that path, the tree has to call BACK into it to
publish interest, and ws_topic_tree.h ends up declaring three functions that
topic_hub.c implements. Routing sessions through the hub is five call sites and no
lifetime change, and it is what makes extracting the retry queue (~700 lines with
its own timer and lock discipline), the receiver-with-a-ring and the interest
filter into their own units clean rather than another partial cut. Judged as the
first thing to change if only one thing changes.

- [~] S1.11 A park site cannot forget its own cleanup
      done: recv() and subscriberCount() park on an event whose start()/stop()
        forward edge-only to the inbox trigger and whose dispose releases what
        the parked coroutine held; the hand keepalive, both zend_try brackets
        and the by-hand query abandon are gone from topic_hub.c; tests 071-074
        pass unchanged and the S1.5 ASAN lane is clean.
      The sweep keeps drop_ring: post-fatal waker destruction upstream
        measurably leaks, so the attachment stays the payloads' owner.
      tier: T2 · role: Critic
      Sage 2026-08-14 (reviewing S1.8 and S1.9 as a whole): the mechanism is
        sound as a control bus, and the special cases share one root — the park's
        obligations live in the C frame of the parked coroutine, so they run only
        if that frame unwinds. The engine already owns an object with the right
        lifetime: the waker's event, whose start()/stop() the scheduler drives on
        suspend and resume and whose dispose runs on every death of a coroutine.
        Moving the obligations there makes forgetting them unexpressible, which
        is the same principle as keeping constraints in the API. Placed last in
        the stage: it must not be layered on an unmerged PR, and S1.5's lane is
        the instrument that proves it. Two teardown modes stay — whether a
        request can still run PHP is a binary fact, not bookkeeping. Final.
      HALF DONE 2026-08-15, PR #158: the reactor reference moved onto the park
        event (start takes it, stop gives it back, dispose gives it back if stop
        never came, waker owns the only reference through trans_event), so the hand
        keepalive and recv()'s zend_try are gone. 071-074 pass unchanged, the leak
        lane is clean, tests/phpt is 378/1 (the pre-existing h3 failure).
      MEASURED AGAINST THE STEP'S PREMISE: the obligations that outlive the resume
        CANNOT move onto the event. The scheduler stops the waker's events "as soon
        as the coroutine is ready to run" and cleans them BEFORE the coroutine's
        frame continues (async/scheduler.c, and the CLEAN_EVENTS on the fast return
        path), so anything the frame reads after the park is read after the event
        is gone. The first version gave the subscription to the event and test 071
        went red: `parked` was cleared one resume too early and a second recv()
        stole the message through the gap. The subscription and the scatter/gather
        query stay frame-owned, and subscriberCount() keeps its zend_try.
      Critic 2026-08-15 (on the code): sound, and three comments asserting
        invariants the code did not have. The event's own subscription reference
        was inert — the frame's is released last on every path, and a dead frame
        never releases at all — and it would turn lethal the day a sweep releases
        an abandoned frame's reference: the event's dispose would then free the
        ring, whose payload release writes ws_bodies_freed into a hub that may
        already be gone. Taken: the event borrows. Also taken: the latch reports
        whether the keepalive really held anything (it declines on a torn-down
        attachment), stop() got the NULL check its own dispose needs
        (zend_async_callbacks_free re-enters stop through the waker callback), and
        the true reason a stale `parked` is harmless is written down — every
        teardown sets `closed` and recv tests `closed` BEFORE `parked`, so that
        order is load-bearing. resume_when's refusal is no longer ignored, a park
        that never happened reports CLOSED rather than a false timeout, and the
        event carries info() so a stuck-loop report can name it.
      OPEN, and it is a design question rather than a defect: what owns an
        obligation that must survive BOTH a normal resume (where the frame still
        needs the object) and a death (where no frame will run)? Two references
        with two owners is what this PR does for the subscription; the query needs
        the same or a different shape. Sage's original verdict assumed one owner,
        which the measurement above rules out.
      Edmond 2026-08-15: this one goes to Sage as well, and later — not decided by
        the executor. Sage's ruling so far, given in passing while deciding S6: the
        zend_try stays and moves into the room core unchanged, because its cause is
        the engine's ordering and nothing short of an engine change removes it.
        Whether that is the final word is what the later call decides.
      Sage 2026-08-14, the risk for whoever executes it: the engine's waker
        discipline is NOT one stop per start — stop_waker_events on resume,
        waker_clean and waker destruction overlap, and waker_events_dtor
        deliberately does not stop. The new event must hold its own 0/1 latch and
        forward edges only, mirroring EVENT_START/STOP_PROLOGUE. Both mistakes are
        silent and unlike what they replace: one stop too many strips a sibling
        receiver's ref and it is cancelled as a deadlock 0.4 ms later — only test
        072's two parked receivers on one thread catch that, so it must not be
        simplified to one; one stop too few holds the loop forever and shows up
        as a hang at pool close, which reads as a flake.

## S2 — Runs leave the HTTP worker

php-claw. Goal: a run executes in its own thread, so an HTTP worker only serves
connections.
Done when: a run started over HTTP completes in a pool thread, answers a question
put to a person, and is visible live on both transports.

The gate and the completion watchdog are in this stage, not a later one: a run
that cannot be answered or cannot report its own death is not moved, only lost.

- [ ] S2.1 The pool exists and boots the autoloader
      done: a task submitted from a request handler returns true for
        class_exists(IssueRunner::class)
      tier: T2 · role: Critic
- [ ] S2.2 A run's task builds its own environment inside the thread
      done: a run started over POST reaches RunStatus::Done with trace rows
        written by the thread's own PDO, and the issue ends Done
      tier: T2 · role: Critic
- [ ] S2.3 The agent pre-flight stays in the worker
      done: an unwired agent still answers POST .../start with 500, and adoption
        still marks the run failed
      tier: T1 · role: —
- [ ] S2.4 A supervisor coroutine settles what the task could not
      done: a task killed by OOM leaves no run row reading running, and the
        issue leaves in-progress without waiting for the next serve
      tier: T2 · role: Critic
- [ ] S2.5 The human gate travels with the run
      done: POST .../answer wakes a pooled run parked at its gate, and a reply
        aimed at an already-answered question is refused by the run, not by the
        handler
      tier: T2 · role: Critic
- [ ] S2.6 Live trace reaches both transports
      done: a WebSocket subscriber and an SSE reader both see the run's records
        while it executes, and a dropped frame is healed from the journal
      tier: T2 · role: Critic
- [ ] S2.8 A dead pool worker is visible
      Upstream, true-async/php-async#231. ThreadPool tracks no liveness:
      getWorkerCount() returns a construction-time constant, a worker that dies
      touches nothing, and submit() goes on accepting work whose Futures never
      settle — a run that silently never finishes, which is the false-Done shape
      again. reload() already hangs on it, sizing its exit-token cohort from the
      stale count.
      done: a pool that lost a worker reports fewer live workers than it was
        constructed with, and a submit with no worker left fails instead of
        returning a Future nobody will settle
      tier: T2 · role: Critic
      Edmond 2026-08-14: queued.

- [ ] S2.7 Shutdown ends in-flight runs
      done: graceful_shutdown() with a run in the pool exits without leaving a
        run row reading running
      tier: T2 · role: —

## S3 — Control reaches a run from any worker

Goal: stop and answer work regardless of which worker served the request.
Done when: a command issued on one worker reaches a run owned by another.

- [ ] S3.1 A run subscribes to its own control room
      done: a command published to run/{id}/control is received inside the run's
        thread
      tier: T2 · role: Critic
- [ ] S3.2 The 409 guard moves to the ledger
      done: a concurrent start is refused with no in-memory map consulted
      tier: T2 · role: Critic
- [ ] S3.3 stop ends a pooled run
      done: POST .../stop returns 202 and the run's ledger row settles, with the
        issue back in Open
      tier: T2 · role: —

## S4 — The board becomes multi-producer

Goal: board frames carry an ordering key, so a bootstrap cannot overwrite a live
change. Issue #116; the design draft is dev/design/board-cursor.md.
Done when: a late subscriber's bootstrap and a concurrent live change apply in
the order they were derived, on both transports.

- [ ] S4.1 Correct the design draft against the runtime as built
      done: every claim it makes about the runtime is checked against a measured
        fact, and the ones that fail are replaced
      tier: T1 · role: —
- [ ] S4.2 BoardFeed: producerId, seq, tsMs, diff and no-op suppression
      done: unit tests cover dedup, gap detection and tombstones
      tier: T2 · role: Critic
- [ ] S4.3 The announcer emits beside the poll, and the poll is measured
      done: a board change reaches a client without a tick, and a write from
        `bin/claw run` still reaches it
      tier: T2 · role: Critic
- [ ] S4.4 SSE and the WS bootstrap emit the same frame shape
      done: a frame from either transport carries producerId, seq and tsMs
      tier: T2 · role: —

## S5 — setWorkers(n) is turned on

Goal: the dashboard serves on more than one thread without losing state.
Done when: reads scale with the worker count and nothing regresses at n=1.

- [ ] S5.1 Audit what per-worker memory the server still relies on
      done: a list of fields, each either moved or justified in writing
      tier: T2 · role: Critic
- [ ] S5.2 Turn setWorkers(n) on and measure
      done: read throughput at 1/2/4 workers recorded in dev/BENCHMARKS.md
      tier: T2 · role: —
