# Handoff — 2026-08-16

## Continue from

`dev/PLAN.md` outranks this file. Stage **S6 — Rooms without WebSocket**, which the plan
places BEFORE S2, next step **S6.3** (the core moves to its own name). The work is upstream,
in `/home/edmond/true-async-server`, not in php-claw.

S6.3 is a `git mv` of the pub/sub core into `src/room/` and `include/room/` with `room_*`
prefixes, and its own criterion says the diff is mechanical. What does NOT move: the PHP
classes, the `setWs*` config, the `ws_*` keys in `getRuntimeStats()` and the test hook — a
contract php-claw and the tests depend on. Watch the sed: those `ws_*` stat keys are string
literals sitting next to identifiers with the same prefix.

## Landed this session, both merged into true-async/server main

- **#162** (S6.1) a session subscribes through the hub — `topic_hub_tree()` is gone from the
  public header and from the repository; the hub answers with a status enum instead of
  handing out its tree.
- **#163** (S6.2) one kind of subscriber in a node — `room`-shaped now:
  `ws_topic_receiver_t {ops, id, mark, filters}` embedded in `ws_session_t` and
  `ws_server_sub_t`, reached by `offsetof`; the node array is `ws_topic_receiver_t **` with
  NULL as the tombstone; `ws_topic_tree.h` names no `ws_session_t` and `topic_hub.c` does not
  include `ws_session.h`.

## Open, and who owns it

1. **`php_room.c` has no step.** The stage's shape paragraph names it — the Room class out of
   `http_server_class.c` — but no step's `done:` covers it, and S6.3's criterion explicitly
   wants a mechanical diff, which a 600-line extraction is not. Edmond decides: its own step,
   folded into S6.4, or dropped.
2. **S1.11's remaining half** — `topic_hub_count()` keeps its `zend_try`. Edmond assigned this
   to Sage, LATER, not to the executor.
3. **The run-control primitive** — Sage ruled run control does not belong on a room; it becomes
   its own primitive of the room core after S6.4. Until then `run/{id}/control` stays on a room.
4. **`server/h3/045-h3-reload-reactor-pool`** — the one red test in `tests/phpt`, red before any
   of this work. Fix the trailing `%A` or leave it? Still unanswered.

## Verified state, measured on merged main

- `tests/phpt`: 380 passed, 1 failed, 1 warned. The failure is the h3 reload test above; the
  warn is an XFAIL section on a test that passes. Both pre-existing.
- `tests/phpt/websocket`: 77 passed, 4 skipped, 0 failed — three consecutive runs.
- `tests/valgrind-rooms.sh`: 9 tests, 0 leaked, ~42 s.
- `fuzz_ws_topic`: 522 184 runs / 61 s; `fuzz_ws_frame`: 601 229 runs / 31 s. Clean under
  ASAN+UBSAN.

## Traps this session paid for

- **CI built neither WebSocket fuzz target**, so `fuzz_ws_topic` sat uncompiled from S1.10 to
  S6.1 without anyone noticing. Both are built and run in the embedded fuzz stage now.
- **The leak lane cannot see a dead-request teardown leak**: it excludes tests 073 and 074, the
  two that reach one with a live subscription. S6.2 leaked a filter list there and the lane
  stayed green.
- **Do not benchmark under a concurrent valgrind run.** `fuzz_ws_frame` measured 1 exec/s that
  way and ~19k exec/s alone, and the wrong figure reached a CI comment before it was corrected.
- After a `gh pr merge --delete-branch` the shell lands on `main`. Two commits went there
  before the next branch existed; they were moved and `main` reset to `origin/main`.

## Commands and paths

- Build: `cd /home/edmond/true-async-server && make -j12`.
- Suite: `TEST_PHP_EXECUTABLE=/home/edmond/php-src/sapi/cli/php
  /home/edmond/php-src/sapi/cli/php run-tests.php -q
  -d extension_dir=/home/edmond/true-async-server/modules tests/phpt`.
- Leak lane: `RUN_TESTS=/home/edmond/php-src/run-tests.php
  tests/valgrind-rooms.sh /home/edmond/php-src/sapi/cli/php`.
- Fuzz: `cd fuzz && make fuzz_ws_topic && USE_ZEND_ALLOC=0 ./fuzz_ws_topic -max_total_time=60
  corpus/ws_topic`.
- php-claw's own suite: `composer qa`.
