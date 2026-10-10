# ADR-002 benchmark results

Raw output of `../wal-contention-bench.php`. Each JSON file is one run; this page is the human
summary. Re-run on the minimum support-matrix machine from ADR-001 before ADR-002 moves to Accepted.

## Run 2026-10-10 — development Mac (upper bound, not the minimum machine)

| | |
|---|---|
| Machine | Apple M4 Max, 14 cores, 36 GB, APFS on internal SSD |
| OS | macOS 26.6.2 (Darwin 25.6.0 arm64) |
| PHP | 8.4.19 NTS (Laravel Herd build), `pdo_sqlite` + `sqlite3` extensions |
| SQLite | 3.45.2, compile options include `ENABLE_FTS5`, `THREADSAFE=1`, `DEFAULT_WAL_AUTOCHECKPOINT=1000` |
| Parameters | 100 000 rows total, 500-row `BEGIN IMMEDIATE` transactions, `busy_timeout=5000`, one concurrent reader |
| Files | `2026-10-10-m4-max.json`, `2026-10-10-m4-max-fts5.json`, `2026-10-10-m4-max-fullfsync.json` |

### Baseline — plain table with three indexes, `fullfsync=OFF`

| synchronous | writers | wall | inserts/s | SQLITE_BUSY (writers / reader) | tx p50 | tx p95 | tx p99 | lock-wait p95 | reader q/s | verify |
|---|---:|---:|---:|---|---:|---:|---:|---:|---:|---|
| NORMAL | 1 | 0.95 s | 105 247 | 0 / 0 | 4.4 ms | 6.0 ms | 7.1 ms | 0.0 ms | 632 | ok |
| NORMAL | 2 | 0.95 s | 104 946 | 0 / 0 | 4.5 ms | 17.5 ms | 124.5 ms | 12.2 ms | 623 | ok |
| NORMAL | 4 | 1.02 s | 97 784 | 0 / 0 | 4.6 ms | 71.7 ms | 157.2 ms | 99.7 ms | 585 | ok |
| NORMAL | 8 | 0.95 s | 104 834 | 0 / 0 | 4.5 ms | 102.1 ms | 478.7 ms | 159.1 ms | 638 | ok |
| NORMAL | 12 | 0.99 s | 101 074 | 0 / 0 | 4.5 ms | 172.1 ms | 590.5 ms | 695.0 ms | 624 | ok |
| FULL | 1 | 0.92 s | 108 846 | 0 / 0 | 4.3 ms | 5.2 ms | 6.2 ms | 0.0 ms | 654 | ok |
| FULL | 2 | 1.06 s | 94 481 | 0 / 0 | 4.3 ms | 5.5 ms | 13.3 ms | 0.0 ms | 641 | ok |
| FULL | 4 | 0.98 s | 101 708 | 0 / 0 | 4.3 ms | 18.6 ms | 220.0 ms | 95.8 ms | 641 | ok |
| FULL | 8 | 1.09 s | 92 173 | 0 / 0 | 4.4 ms | 77.3 ms | 492.4 ms | 128.2 ms | 646 | ok |
| FULL | 12 | 1.13 s | 88 251 | 0 / 0 | 4.3 ms | 168.2 ms | 698.0 ms | 1 027.4 ms | 648 | ok |

Reader loop p95 stayed between 3.4 and 6.8 ms in every scenario; the reader never saw a lock.
Resulting database: 73.8–74.0 MB for 100 000 rows (≈ 740 bytes/row incl. indexes). The WAL was
already checkpointed when the verifier connected (last connection closing triggers a checkpoint);
`PRAGMA wal_checkpoint(TRUNCATE)` then returned `[0,0,0]` in < 1 ms.

### Variant — external-content FTS5 index on `subject, body_preview` maintained by triggers

| synchronous | writers | wall | inserts/s | SQLITE_BUSY | tx p50 | tx p95 | tx p99 | lock-wait p95 | FTS probe |
|---|---:|---:|---:|---|---:|---:|---:|---:|---|
| NORMAL | 1 | 2.56 s | 39 046 | 0 | 11.5 ms | 18.4 ms | 24.1 ms | 0.0 ms | `proposal AND timeline` → 100 000 hits |
| NORMAL | 8 | 2.84 s | 35 266 | 0 | 12.3 ms | 139.6 ms | 1 558.7 ms | 1 002.5 ms | same |

FTS5 roughly triples the per-row write cost (4.4 → 11.5 ms per 500-row batch) and still indexes
100 000 messages in under three seconds.

### Variant — `PRAGMA fullfsync=ON` (macOS `F_FULLFSYNC`, real power-loss durability)

| synchronous | writers | wall | inserts/s | SQLITE_BUSY | tx p50 | tx p95 | tx p99 | lock-wait p95 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| NORMAL | 1 | 1.80 s | 55 578 | 0 | 7.0 ms | 10.4 ms | 46.8 ms | 0.0 ms |
| NORMAL | 8 | 1.27 s | 78 827 | 0 | 8.6 ms | 135.2 ms | 612.2 ms | 216.1 ms |
| FULL | 1 | 1.61 s | 62 173 | 0 | 7.0 ms | 9.9 ms | 21.0 ms | 0.0 ms |
| FULL | 8 | 1.82 s | 54 861 | 0 | 7.2 ms | 123.8 ms | 1 218.5 ms | 401.6 ms |

Plain `fsync()` on APFS does not flush the drive cache, which is why `FULL` cost nothing in the
baseline. Forcing `F_FULLFSYNC` roughly halves throughput and is still > 50 000 inserts/s.

### Backup probe — 100 000 rows, 43.8 MB source

| Method | Time | Output | `PRAGMA integrity_check` |
|---|---:|---:|---|
| `VACUUM INTO 'file'` | 101 ms | 42.6 MB (compacted) | ok |
| `SQLite3::backup()` (`sqlite3_backup_*`) | 84 ms | 43.8 MB (page copy) | ok |

## Reading the numbers

- Throughput is flat at ~100 000 inserts/s from 1 to 12 writers: WAL serialises writers, so adding
  writers only redistributes the same work and adds lock-wait tail latency. It never reduced
  correctness (row counts and uniqueness verified) and never produced `SQLITE_BUSY` with a 5 s
  busy timeout.
- Contention shows up as **tail latency**, not failure: p95 transaction latency rises from 6 ms
  (1 writer) to 102 ms (8) and 172 ms (12); worst single wait was ~1 s at 12 writers.
- The run is write-bound only because it has no network I/O. A real sync worker spends almost all
  its time waiting on the provider API; §5.6's 100 000 messages correspond to ~1 s of write time
  here and ~3 s with FTS5.
