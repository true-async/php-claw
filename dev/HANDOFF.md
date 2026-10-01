# Handoff — 2026-08-16

## Continue from

`dev/PLAN.md` outranks this file. Stage **S6 is finished and removed from the plan** — its
decision is in `dev/DECISIONS.md` (2026-08-16) and what it left behind is the last section of
`dev/TODO.md`. The active stage is **S2 — Runs leave the HTTP worker**, next step **S2.1** (the
pool exists and boots the autoloader). That is php-claw's own code, not upstream: the first step
of this stage is the first php-claw code in a while.

S1's remainder — **S1.11**, the half about what owns an obligation that must survive both a normal
resume and a death — waits on Sage by Edmond's decision, not on the executor.

## What S6 delivered, all merged into true-async/server main

Six PRs, **#162 through #168**. The pub/sub core lives in `src/room/` (`room_hub.c`,
`room_tree.c`, `php_room.c`, headers in `include/room/`) and no longer knows what a connection is:
a subscriber is a `room_receiver_t {ops, id, mark, filters}` embedded in whatever receives, and
delivery is one call through `ops->deliver`. A build configured with `--disable-websocket`
compiles it, registers `TrueAsync\Room`, and delivers a publish from one thread to a `recv()` in
another.

One deliberate BC break, approved by Edmond: `RoomDeliveryException` extends `HttpServerException`,
because a build without WebSocket has no `WebSocketException`. `CHANGELOG.md` carries it.

## Verified state, measured on merged main

- `tests/phpt`: 381–382 passed, **0 failed**. Two warns: an XFAIL section on a passing test, and
  `server/core/018-log-off-no-overhead` reporting "passed on retry" — a load-sensitive perf gate,
  not a regression. The h3 reload test that had been red for weeks passes since the full
  `phpize --clean` rebuild that S6.3 forced; the mechanism was never established.
- `tests/valgrind-rooms.sh`: 10 tests, 0 leaked.
- `--disable-websocket` build: loads, and all 11 tests in `tests/phpt/room` pass there. A CI step
  on the debug leg builds it and asserts at least ten tests passed.
- `fuzz_ws_topic` and `fuzz_ws_frame` both build and run in the embedded fuzz stage of CI, which
  they did not before this session.

## Open, and who owns each

1. **The names at the seam.** `getRuntimeStats()`'s `ws_*` keys and the `setWs*` knobs are public
   API and php-claw reads them; `http_server_get_topic_hub()` is internal and mechanical. Both in
   `dev/TODO.md`. **The public half is Edmond's call.**
2. **S1.11's remaining half** — assigned to Sage, later.
3. **The run-control primitive.** Sage ruled run control does not belong on a room; it becomes its
   own primitive of the room core (a channel addressed by receiver, on the same slots and
   mailboxes, outside the topic tree). Until it exists `run/{id}/control` rides a room.
4. **php-claw PR #120** (`feat/multi-worker-server`) is open and carries this folder.

## Traps this session paid for

- **A green build proves nothing about a PHP extension.** A shared object links with undefined
  symbols and fails at `dlopen`; `php -d extension=<abs .so>` is discarded when a scan-dir ini
  already loaded the module by name; `run-tests` exits 0 when everything skips. Memory:
  `php-extension-load-traps`.
- **Do not benchmark under a concurrent valgrind run.** `fuzz_ws_frame` measured 1 exec/s that way
  and ~19k exec/s alone, and the wrong figure reached a CI comment before it was corrected.
- **After `gh pr merge --delete-branch` the shell lands on `main`.** Two commits went there before
  the next branch existed.
- **`phpize --clean` deletes `config.nice`.** The invocation this tree needs is
  `./configure --with-php-config=/usr/local/bin/php-config`; a config.m4 change needs `phpize`
  before `./configure`, or the Makefile keeps building the old source list.

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
