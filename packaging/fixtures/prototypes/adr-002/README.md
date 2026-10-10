# ADR-002 prototype: SQLite WAL write-contention benchmark

Evidence for [`docs/adrs/002-local-db-and-queue.md`](../../../../docs/adrs/002-local-db-and-queue.md)
(OPEN-QUESTIONS **A9**: eight concurrent email sync workers against one WAL-mode SQLite file).

Plain PHP + PDO, no framework, no app code. It is a measurement fixture, not a pattern to copy into
`app/`.

## What it does

1. Creates a fresh WAL-mode database with an `email_messages`-shaped table (ULID primary key,
   `connection_id`, unique `(connection_id, provider_message_id)`, `thread_id` index,
   `(connection_id, received_at)` index, JSON `meta`).
2. Spawns one **reader** process (query loop: latest-20 per connection, count per connection,
   thread count) and **N writer** processes via `proc_open` (N = 1, 2, 4, 8, 12 by default).
3. Each writer inserts its share of 100 000 rows in 500-row `BEGIN IMMEDIATE … COMMIT`
   transactions with `busy_timeout = 5000` ms, recording per-transaction latency, write-lock wait
   and any `SQLITE_BUSY` that survives the busy handler.
4. Repeats for `synchronous = NORMAL` and `FULL`; verifies the row count and uniqueness; measures
   `PRAGMA wal_checkpoint(TRUNCATE)`.
5. Optional variants: `--fts5=1` (external-content FTS5 index with triggers, §3.2 shape) and
   `--fullfsync=1` (macOS `F_FULLFSYNC`, true power-loss durability).
6. Backup probe: `VACUUM INTO` versus the `sqlite3_backup` API on the 100 000-row file, with
   `PRAGMA integrity_check` on both outputs.

## Run

```sh
php packaging/fixtures/prototypes/adr-002/wal-contention-bench.php
php packaging/fixtures/prototypes/adr-002/wal-contention-bench.php --writers=1,8 --fts5=1 --skip-backup-probe=1
php packaging/fixtures/prototypes/adr-002/wal-contention-bench.php --writers=1,8 --fullfsync=1 --skip-backup-probe=1
```

Databases are written to `./tmp/` (git-ignored). Results land in `./results/<date>-<host>.json`;
commit those together with a line in `results/README.md`.

## Results

See [`results/README.md`](results/README.md) for the recorded runs and the machine profile of each.
