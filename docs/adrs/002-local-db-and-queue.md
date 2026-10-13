# ADR-002: SQLite in WAL mode with the Laravel database queue is the only core-ready backend; MySQL/Redis is a contract-tested second profile

| | |
|---|---|
| **Status** | Proposed |
| **Date** | 2026-10-10 |
| **Deciders** | B (Laravel platform) author · F (Integrations) reviews the sync-worker rules · H (Security/privacy) reviews the backup and durability boundary. No owner sign-off required (`ENG` in `docs/OPEN-QUESTIONS.md`). |
| **Closes** | Blueprint §15 item 3 (first half: SQLite FTS5 vs optional MySQL) · OPEN-QUESTIONS §15.3a, **A4** (resolved), **A9** (measured) · **A3** proposed here 2026-10-10, **final 2026-10-13 in D9 (S2-01, #84)** |

## Context

Blueprint §2 has already chosen the backend for the five-minute install: *"använd SQLite i WAL mode
+ Laravel database queue för core-ready, med en tjänst och lokal native PHP runtime. MySQL och
Redis/Horizon kan erbjudas som avancerad migrations-/driftsprofil senare."* It also says what must
not be left open: *"Säkerställ migrationsväg och kontraktstester mellan backend-profiler; om teamet
efter benchmark kan bevisa en enklare underhållen MySQL-bundle inom fem minuter får ADR motivera
byte."* This ADR records the decision rather than re-arguing it, and closes the parts §2 leaves to
engineering.

Three further sections constrain the record:

- §3.2: *"Fulltext först med SQLite FTS5 / adapter; vektorsökning byggs som opt-in först efter
  benchmark. Ingen extern vector DB som krav."*
- §9: *"SQLite snapshot med konsekvent checkpoint/backup API, inte blind live-file-copy."*
- §5.6 acceptance criterion 3: *"Sync av minst 100 000 syntetiska mejl över åtta konton kan
  återupptas efter avbrott utan dubbletter eller dataförlust."* Eight concurrent sync workers
  against a single-writer WAL file is ambiguity **A9**, flagged as *"the most likely place the §2
  decision gets reopened."*

What breaks if this stays open: S0-26 cannot configure the empty Laravel app, S1-01 cannot pick the
queue topology, E2-2 cannot write the schema, and the Sprint 3 email adapters would be built on an
unmeasured assumption about write contention.

§3.1's note that SQLite is not protected by FileVault while the user is logged in is acknowledged
here and **owned by ADR-003** (Keychain/secrets, SQLCipher evaluation). This ADR only ensures that
nothing it decides makes that evaluation harder.

## Decision

### D1. Backend profiles: `local` is core-ready, `server` is the later profile

Two named backend profiles, selected by one environment variable the installer writes and the user
never sees:

| | `local` (default, the only profile the installer produces) | `server` (advanced, Sprint 4+ at the earliest) |
|---|---|---|
| `LEA_BACKEND_PROFILE` | `local` | `server` |
| `DB_CONNECTION` | `sqlite` | `mysql` (MySQL 8.4 LTS; MariaDB 11 LTS acceptable if the contract suite passes) |
| `QUEUE_CONNECTION` | `database` | `redis` (Horizon optional, never required) |
| `CACHE_STORE` / `SESSION_DRIVER` | `database` / `database` | `redis` / `redis` |
| Processes | one PHP-FPM/HTTP process, one `queue:work`, one `schedule:work` — all `launchd`-supervised per §2 | adds `mysqld`, `redis-server`, run by the operator, **never installed by the Mac installer** |

The `server` profile exists so the §2 migration path is real and tested, and so a future multi-user
or hosted deployment is possible. It is not a performance escape hatch for the Mac install; the
evidence below shows none is needed.

### D2. SQLite connection settings (binding for `config/database.php`)

Laravel's `sqlite` connection ships the keys `busy_timeout`, `journal_mode`, `synchronous` and
`transaction_mode` (vendor doc: `laravel/laravel` `config/database.php`, fetched 2026-10-10). They are
set, not left `null`:

```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => env('DB_DATABASE'),           // <Application Support>/Laravel Executive AI/data/app.sqlite
    'foreign_key_constraints' => true,
    'busy_timeout' => 5000,                     // ms; measured sufficient with 0 SQLITE_BUSY at 12 writers
    'journal_mode' => 'WAL',
    'synchronous' => 'NORMAL',                  // see D7 for the durability trade
    'transaction_mode' => 'IMMEDIATE',          // every DB::transaction() takes the write lock up front
],
```

`transaction_mode = IMMEDIATE` is required, not optional: Laravel's `database` queue driver pops a
job with a read-then-write transaction (`getNextAvailableJob` then `markJobAsReserved`). In SQLite a
`DEFERRED` transaction that upgrades from read to write after another writer committed fails with
`SQLITE_BUSY_SNAPSHOT` immediately, bypassing `busy_timeout`. `BEGIN IMMEDIATE` makes the busy
handler apply. Every transaction in the benchmark used `BEGIN IMMEDIATE` for this reason.

One database file holds everything: application tables, `jobs`, `job_batches`, `failed_jobs`,
`cache`, `sessions`. Splitting the queue into its own file was considered (see Alternatives) and
rejected because it doubles the backup surface for no measured gain.

### D3. Write-path rules that make eight sync workers safe (A9)

The benchmark (Evidence E1) shows WAL serialises writers without failing them: 0 `SQLITE_BUSY` at 1
through 12 concurrent writers, throughput flat at ~100 000 inserts/s, contention visible only as
tail latency (p95 6 ms → 102 ms at 8 writers). The reopen condition is **not** met. Instead of one
serialised writer, the rule set below is adopted; with it, up to eight sync workers are allowed and
two are the default.

1. **No network I/O inside a database transaction.** Fetch a page from the provider, then write it.
   A transaction that spans an HTTP call holds the single write lock for the duration of that call
   and starves every other worker. This is a code-review blocker.
2. **Batch writes: 100–500 rows per transaction**, `DB::transaction()` (which is `BEGIN IMMEDIATE`
   under D2). Measured cost per 500-row batch: 4.4 ms plain, 11.5 ms with FTS5.
3. **Idempotent upserts** keyed on the §5.6 unique index `(connection_id, provider_message_id)` and
   the thread equivalent, so a resumed batch after a crash is a no-op rather than a duplicate
   (criterion 3).
4. **Cursor commit in the same transaction as the batch** (`email_sync_cursors` row updated with
   the rows it covers), never before — this is what makes "resume without loss or duplicates" hold
   with `synchronous = NORMAL`.
5. **Queue topology:** queues `default`, `email-sync`, `briefs`, `ai`. One `queue:work` process
   serves all four with `--queue=briefs,email-sync,default,ai`; `email-sync` jobs are one
   per `(connection, folder, page)` and carry `WithoutOverlapping` on the connection id so a
   connection never syncs itself twice. Default worker concurrency is **2**; the installer never
   starts more than 2; a user-visible setting may raise it to **8**, the measured ceiling at which
   p95 batch latency stays near 100 ms.
6. **`busy_timeout` exhaustion is a bug, not a retry.** A job that still gets `database is locked`
   after 5 s goes to `failed_jobs` with the lock-wait recorded in `health_events`, and the reopen
   condition below counts it.

### D4. Full-text search: FTS5 now, vector search only after a benchmark, no external engines

- Full-text is **SQLite FTS5** behind an internal `FullTextSearch` contract (`app/CRM/Search/`):
  one external-content FTS5 table per searchable entity (`email_messages_fts`, `contacts_fts`,
  `communications_fts`, `briefs_fts`) maintained by `AFTER INSERT/UPDATE/DELETE` triggers created in
  the migration, exactly as the benchmark's `--fts5` schema does. Tokenizer `unicode61`.
- **`remove_diacritics 2` — decided 2026-10-13 by S2-01 (#84) against the Swedish fixture corpus**,
  where this decision was deferred to E2-1. Folding is on: `goteborg` must find "Göteborg",
  `sjostrom` "Sjöström", `akersberga` "Åkersberga", `molndal` "Mölndal" — what the owner types on a
  keyboard that is not Swedish, and what a pasted address or a provider's mangled subject gives us.
  The recall argument is reinforced by the profile argument: MySQL's `utf8mb4_unicode_ci` FULLTEXT
  folds diacritics whether we like it or not, so *not* folding on SQLite would make the two profiles
  return different hit sets and the `search` contract group unsatisfiable. The precision cost is
  accepted and small on this corpus (`har` also matches "här"); it is a CRM lookup over names,
  subjects and notes, not a linguistic index. One residual difference is recorded rather than
  papered over: `utf8mb4_unicode_ci` also folds ß → ss, `unicode61` does not, so `mussig` finds
  "Müßig" on the `server` profile only — the contract queries avoid ß, and S2-09's UI must not
  promise it. Evidence E6.
- The `server` profile implements the same contract with InnoDB `FULLTEXT` indexes and
  `MATCH … AGAINST (… IN BOOLEAN MODE)`. The contract test asserts the same hit **set** for the
  fixture queries on both; ranking order is allowed to differ.
- Verified on this machine: the bundled SQLite 3.45.2 reports `ENABLE_FTS5` and a live
  `CREATE VIRTUAL TABLE … USING fts5` round-trip works (Evidence E2). **ADR-001 must guarantee the
  same for the shipped runtime**: `ENABLE_FTS5` is a build-time option, and a PHP build without it
  breaks every search migration. This is recorded as a hard requirement on the runtime manifest.
- **Vector search is not adopted.** The named candidate is `sqlite-vec` (asg017/sqlite-vec,
  Apache-2.0 **or** MIT, a loadable extension). It is explicitly *"pre-v1, so expect breaking
  changes"* (Evidence E2), it requires `load_extension` which PDO does not expose and the bundled
  PHP may not enable, and it would be a second signed binary in the ADR-001 manifest. A Sprint 2+
  issue will benchmark it against the FTS5 baseline (recall@10 and p95 latency on 100 000 messages)
  before any adoption ADR. **No external vector database may ever become a requirement** (§3.2);
  anything beyond `sqlite-vec` is out of scope.

### D5. Backup: `VACUUM INTO` snapshots, verified, encrypted downstream; never a live file copy

- The scheduled backup (`backup_runs` table, §3.1) runs `VACUUM INTO '<backup-dir>/app-<ts>.sqlite.tmp'`
  on a read-only connection, then `PRAGMA integrity_check` on the copy, then an atomic rename, then
  hands the file to the encryption step **owned by ADR-003** (key in Keychain), then applies
  retention. Measured: 100 000 rows / 43.8 MB in 101 ms with `integrity_check = ok` (Evidence E3).
- Why `VACUUM INTO` over the `sqlite3_backup` API: in WAL mode `VACUUM INTO` reads one consistent
  snapshot and is **unaffected by concurrent writers**, whereas the online backup API *"restarts"*
  when *"a file-based source database [is written] by an external process or thread using a database
  connection other than pDb"* (SQLite backup.html) — which with two sync workers would mean a
  backup that never finishes unless workers are paused. `VACUUM INTO` also compacts (42.6 MB vs
  43.8 MB) and, because the source runs with `synchronous = NORMAL`, SQLite *"invokes fsync() … to
  sync the output database to disk after it has been written"* (SQLite lang_vacuum.html).
  `SQLite3::backup()` is kept as the documented fallback for a runtime whose SQLite predates 3.27.
- **Forbidden in code review:** `copy()`, `rsync`, `cp`, Finder duplicates or any file-level copy of
  `app.sqlite` while any process may have it open. The WAL *"is part of the persistent state of the
  database … If a database file is separated from its WAL file, then transactions that were
  previously committed to the database might be lost, or the database file might become corrupted"*
  (SQLite wal.html). The data directory is marked for Time Machine exclusion by the installer
  (`tmutil addexclusion`, workstream A) so the OS does not take that live copy either; the verified
  snapshot directory is what Time Machine or the user's external destination (§9) backs up.
- Restore: stop the `launchd` services, move the live `app.sqlite`, `-wal`, `-shm` aside, copy the
  decrypted snapshot in, run `PRAGMA integrity_check`, start services. Restore drills (§9) run this
  path in CI against the fixture database.

### D6. Checkpointing and WAL hygiene

- Default `wal_autocheckpoint = 1000` pages (4 MB) stays. The scheduler runs
  `PRAGMA wal_checkpoint(TRUNCATE)` once per night in the quiet window and records WAL size and
  checkpoint result in `health_events`. A WAL above 256 MB, or a `TRUNCATE` that reports pages it
  could not checkpoint on two consecutive nights, is a health warning: it means a long-running read
  transaction is starving the checkpointer (*"a long-running read transaction can prevent a
  checkpointer from making progress"*, wal.html).
- Readers therefore must not hold transactions open: no streaming a large export inside one
  transaction; chunk with `lazyById()` outside a transaction.

### D7. Durability setting

`synchronous = NORMAL`, `fullfsync = OFF`. SQLite: *"WAL mode is safe from corruption with
synchronous=NORMAL"*, *"Transactions are durable across application crashes regardless of the
synchronous setting"*, but *"a transaction committed in WAL mode with synchronous=NORMAL might roll
back following a power loss or system crash"* (pragma.html). On macOS even `FULL` only calls
`fsync()`, which does not flush the drive cache; true power-loss durability needs
`PRAGMA fullfsync = ON`, measured at roughly half the throughput (55–79 k inserts/s). The product is
a laptop/desktop with a battery or UPS-class risk profile and every email write is re-derivable from
the provider by cursor (D3.4), so the lost-last-transactions case costs a re-sync, not data. The one
place this is not true — recording that an outgoing brief was **sent** — is handled in D3's rule that
the send receipt is committed before any further side effect and dedup-checked against the
provider's returned message id before a retry; ADR-009 owns the full send-consent design and may
raise that single write to `fullfsync`. [ADR-009](009-brief-scheduling-and-send-consent.md) D5 takes
that option: the `sent` receipt commits with `synchronous = FULL` and `fullfsync = ON` on that one
transaction, and D5.3 defines the provider-side reconciliation before any retry.

### D8. Table naming — A4 resolved

The table is **`email_connections`** (§5.6 data model, `EmailConnection` DTO, `email_*` family);
§5.6's security paragraph's `connected_email_accounts` refers to the same table. It stores
provider, account identity, scopes, status, folder selection and a **Keychain item reference** —
never a token, refresh token or password (§5.6, ADR-003).

### D9. A3 — how `communications`/`messages`/`threads` relate to `email_messages`/`email_threads` (final, S2-01)

**Final as of 2026-10-13 (S2-01, #84), closing ambiguity A3.** The direction below was proposed on
2026-10-10 for workstreams B and E to confirm when the schema was written; S2-01 wrote it
(`database/migrations/2026_10_11_*`, `app/Models/CRM/`) and the acceptance evidence is in
`tests/Integration/BackendContract/CrmSchemaContractTest.php` (`communications` carries
`source_type`/`source_id` and has **no** foreign key to `email_messages`, and neither `messages` nor
`threads` exists) and `tests/Feature/CRM/CrmFixtureRoundTripTest.php` (the S0-18 pack round-trips,
the user-corrected domain match included). Nothing in the text changed when it became final:

- **`email_messages` and `email_threads` are the system of record for provider data**: one row per
  message per connection, provider-faithful after normalization (MIME, HTML sanitization, threading
  headers), protected by the `(connection_id, provider_message_id)` unique index. They are never
  edited by CRM matching.
- **`communications` is the CRM timeline projection**, source-agnostic: one row per (source item,
  customer or contact) link with `source_type`/`source_id` (a morph to `email_messages` today,
  GitHub items or call notes tomorrow), `customer_id`, `contact_id`, `match_reason`, `matched_by`
  (`rule`, `ai`, `user`), `confidence`, timestamps and soft-delete. Because it is a separate row per
  link, the §5.6 requirement that users can **merge/split and correct** matches, and that one domain
  may map to several customers, is a row edit rather than a rewrite of provider data.
- **`messages` and `threads` from §3.1 are not created as separate tables.** `email_messages` *is*
  the messages table and `email_threads` the threads table; adding a third generic layer would store
  the same object twice. If a non-email channel ever needs its own raw store, it gets its own
  `<channel>_messages` table and plugs into `communications` through the same morph.
- Reasoning: a projection keeps provider sync idempotent and fast (D3), keeps the cross-account
  policy boundary at the `email_connections` level where criterion 4 is tested, and makes criterion 6
  (explainable, correctable matching with a link back to the original) a property of one table.
- **What S2-01 added while landing it.** `source_records` is the non-email counterpart of
  `email_messages`: the provider-agnostic snapshot a sync job upserts, unique on
  `(organization_id, source_type, external_account_id, external_id)` — the §3.1
  "provider/account/external_id" key that makes a replayed batch a no-op (D3.3). A contact's
  addresses live in `contact_emails`, unique per organization, because "the sender address is this
  contact's address" (§5.6) must be an indexed lookup rather than a scan of a JSON column. The
  ordered lists the fixture pack shows nested (`emails`, `github_links`) keep their order through a
  `position` column; pure id sets (`customer_ids`, `contact_ids`, `assignee_user_ids`) are relations
  and come back ordered by id. `communications.account_id` and `source_records.external_account_id`
  are plain ULID columns until S2-03 lands `email_connections`, which is when they get their
  foreign key.
- **Additions to the §3.1 minimum that E2-2 must pick up from other ADRs:** `push_subscriptions`
  (ADR-006, opt-in PWA Web Push: `endpoint`, `p256dh`, `auth`, `user_id`, `created_at`,
  `last_seen`, `revoked_at`; unique on `endpoint`). It follows the portable schema rules in D10 and
  lives in the same database file.

### D10. Migration path and contract-test matrix between profiles

- **Portable schema rules** (enforced by review and by the contract suite): ULIDs as `char(26)`;
  JSON via `$table->json()`; booleans via `boolean()`; all timestamps UTC `datetime`; no `enum`
  columns (use `string` + PHP enum); no raw SQL outside `app/Support/Database/<Driver>Grammar`
  classes; id-like columns on MySQL use `utf8mb4_bin` collation so uniqueness is case-sensitive as
  in SQLite; no reliance on SQLite's dynamic typing.
- **Migration command:** `php artisan lea:backend:migrate --from=local --to=server` streams every
  table in foreign-key order with ULIDs preserved, in 1 000-row chunks, verifies row counts and a
  per-table checksum (`sha256` over ordered ULIDs) on both sides, writes a `backup_runs` row, and
  refuses to run unless a fresh `VACUUM INTO` snapshot exists. It is **not** offered in the installer.
- **Migrations are `up()` only.** A shipped install's rollback path is a restore from a verified
  snapshot (D5), not a `down()` that drops the user's tables, so no migration defines one and the
  `migrations` group asserts that instead of a rollback. *(Amended 2026-10-13 by S2-01, #84: the
  group's original wording required `migrate:rollback` of the last batch, which contradicted the
  `up()`-only rule the repository has followed since S1-01 and which `MigrationsContractTest` had
  already dropped. `lea:backend:migrate` above, not `migrate:rollback`, is how a profile is left.)*
- **Contract-test matrix** — CI runs the Pest suite twice, `profile: [local, server]`; `server` uses
  MySQL 8.4 and Redis service containers. The schema is built once per job with `migrate`; tests
  run inside `DatabaseTransactions`, never `RefreshDatabase` per file. The groups that must pass on
  both (`tests/Integration/BackendContract/`):

  | Group | Asserts on both profiles |
  |---|---|
  | `migrations` | `migrate` from zero succeeds with zero pending; every migration this repository authors is `up()` only (Laravel's bundled `cache`/`jobs` migrations keep their stock `down()`); schema matches the portable rules |
  | `queue` | push, pop, release, retry with backoff, fail → `failed_jobs`, `Bus::batch`, `ShouldBeUnique`, `WithoutOverlapping` on the connection id |
  | `repository` | every `WorkspaceRepository` query (§3) over the frozen CRM fixture returns identical ids |
  | `search` | `FullTextSearch` fixture queries return the same hit set; a trigger-maintained index stays consistent after insert/update/delete. Its corpus is the one thing in the suite that must be **committed**: InnoDB publishes rows to a FULLTEXT index at commit, so a corpus inside the test transaction is invisible on `server` (E6). It is therefore imported under mirrored ULIDs and deleted again per test, never left behind for another test to see |
  | `sync-idempotency` | replaying the same 100-message sync batch twice yields one row each; a batch interrupted after N rows resumes without loss or duplicates |
  | `cross-account` | the §5.6 criterion 4 negative tests (S0-21) pass on both |
  | `backup-restore` | snapshot → integrity check → restore round-trip (SQLite: `VACUUM INTO`; MySQL: `mysqldump --single-transaction`) reproduces the fixture checksums |
  | `profile-migration` | `lea:backend:migrate local→server` and `server→local` reproduce row counts and checksums |

## Evidence

### E1. Measured prototype — WAL write contention (A9)

Artefact: `packaging/fixtures/prototypes/adr-002/wal-contention-bench.php`, raw results in
`packaging/fixtures/prototypes/adr-002/results/` (`2026-10-10-m4-max*.json`, summarised in
`results/README.md`). Plain PHP 8.4.19 + PDO; `proc_open` spawns N writer processes and one reader;
`email_messages`-shaped table (ULID pk, `connection_id`, unique `(connection_id, provider_message_id)`,
`thread_id` index, `(connection_id, received_at)` index, JSON `meta`); 100 000 rows total in
500-row `BEGIN IMMEDIATE` transactions; `busy_timeout = 5000`.

Machine profile: Apple M4 Max, 14 cores, 36 GB, APFS SSD, macOS 26.6.2 — the **upper bound**, not
the minimum machine; the run must be repeated on the ADR-001 minimum profile before Accepted.

| synchronous | writers | inserts/s | SQLITE_BUSY | tx p50 | tx p95 | tx p99 | lock-wait p95 | reader p95 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| NORMAL | 1 | 105 247 | 0 | 4.4 ms | 6.0 ms | 7.1 ms | 0 ms | 4.4 ms |
| NORMAL | 2 | 104 946 | 0 | 4.5 ms | 17.5 ms | 124.5 ms | 12.2 ms | 4.3 ms |
| NORMAL | 4 | 97 784 | 0 | 4.6 ms | 71.7 ms | 157.2 ms | 99.7 ms | 6.8 ms |
| NORMAL | 8 | 104 834 | 0 | 4.5 ms | 102.1 ms | 478.7 ms | 159.1 ms | 3.6 ms |
| NORMAL | 12 | 101 074 | 0 | 4.5 ms | 172.1 ms | 590.5 ms | 695.0 ms | 4.0 ms |
| FULL | 1 | 108 846 | 0 | 4.3 ms | 5.2 ms | 6.2 ms | 0 ms | 3.9 ms |
| FULL | 8 | 92 173 | 0 | 4.4 ms | 77.3 ms | 492.4 ms | 128.2 ms | 3.6 ms |
| FULL | 12 | 88 251 | 0 | 4.3 ms | 168.2 ms | 698.0 ms | 1 027.4 ms | 3.4 ms |
| NORMAL + FTS5 triggers | 1 | 39 046 | 0 | 11.5 ms | 18.4 ms | 24.1 ms | 0 ms | — |
| NORMAL + FTS5 triggers | 8 | 35 266 | 0 | 12.3 ms | 139.6 ms | 1 558.7 ms | 1 002.5 ms | — |
| NORMAL + `fullfsync=ON` | 1 | 55 578 | 0 | 7.0 ms | 10.4 ms | 46.8 ms | 0 ms | — |
| NORMAL + `fullfsync=ON` | 8 | 78 827 | 0 | 8.6 ms | 135.2 ms | 612.2 ms | 216.1 ms | — |
| FULL + `fullfsync=ON` | 8 | 54 861 | 0 | 7.2 ms | 123.8 ms | 1 218.5 ms | 401.6 ms | — |

Every scenario verified 100 000 rows and 100 000 distinct `(connection_id, provider_message_id)`.
The concurrent reader never blocked (0 `SQLITE_BUSY`, p95 ≤ 6.8 ms, ~630 queries/s throughout).
Resulting file: ~74 MB for 100 000 messages (~740 B/row with indexes, no bodies).

**Conclusion on A9 and §5.6 criterion 3.** A single writer absorbs 100 000 messages in 0.95 s;
eight concurrent writers finish in the same wall time with zero failures and a 102 ms p95 batch
latency. Real sync is bounded by provider rate limits, not by this write path — eight accounts
would have to deliver well over 10 000 messages per second before the database became the
bottleneck. The reopen condition is **not met**; the write-path rules in D3 are sufficient and a
strictly single-writer design is unnecessary. What contention does cost is tail latency, which is
why the default is two sync workers and the ceiling eight.

### E2. Measured — bundled SQLite build and FTS5

On this machine, `php -r 'print_r((new PDO("sqlite::memory:"))->query("PRAGMA compile_options")…'`
with PHP 8.4.19 (Laravel Herd build) reports SQLite **3.45.2** with `ENABLE_FTS5`, `ENABLE_FTS3/4`,
`ENABLE_RTREE`, `ENABLE_MATH_FUNCTIONS`, `THREADSAFE=1`, `DEFAULT_WAL_AUTOCHECKPOINT=1000`,
`DEFAULT_SYNCHRONOUS=2`. A `CREATE VIRTUAL TABLE t USING fts5(a)` + `MATCH` round-trip succeeded,
and `SQLite3::backup()` exists. `ext/sqlite3` and `pdo_sqlite` both report 3.45.2.
sqlite-vec: GitHub README (fetched 2026-10-10) states *"sqlite-vec is a pre-v1, so expect breaking
changes!"*, licensed Apache-2.0 or MIT, loaded as an extension.

### E3. Measured — backup mechanics

Same artefact, `runBackupProbe`: 100 000-row, 43.8 MB database. `VACUUM INTO` 100.6 ms → 42.6 MB,
`PRAGMA integrity_check = ok`. `SQLite3::backup()` 83.5 ms → 43.8 MB, `integrity_check = ok`.
`PRAGMA wal_checkpoint(TRUNCATE)` after each scenario returned `[0,0,0]` in < 1 ms because the last
closing connection had already checkpointed — the documented *"when the last database connection on
a database file closes"* behaviour.

### E4. Vendor documentation (all fetched 2026-10-10)

- SQLite, *Write-Ahead Logging* (https://sqlite.org/wal.html): *"writers and readers can run at the
  same time. However, since there is only one WAL file, there can only be one writer at a time."*;
  the WAL-file separation warning quoted in D5; checkpoint starvation sentence quoted in D6; default
  autocheckpoint *"1000 pages or more in size, or when the last database connection on a database
  file closes."*
- SQLite, *PRAGMA synchronous / busy_timeout* (https://sqlite.org/pragma.html): the three
  durability sentences quoted in D7.
- SQLite, *VACUUM INTO* (https://sqlite.org/lang_vacuum.html): *"transactional in the sense that the
  generated output database is a consistent snapshot of the original database"*; *"if the PRAGMA
  synchronous setting of the original database is NORMAL or FULL, then SQLite invokes fsync()"*;
  interruption *"by an unplanned shutdown or power loss"* may leave the output *"incomplete and
  corrupt"* — hence the `.tmp` + `integrity_check` + rename sequence in D5.
- SQLite, *Online Backup API* (https://sqlite.org/backup.html): the restart-on-external-write
  sentence quoted in D5.
- Laravel, `config/database.php` on `laravel/laravel` `master`
  (https://github.com/laravel/laravel/blob/master/config/database.php): `sqlite` connection keys
  `foreign_key_constraints`, `busy_timeout`, `journal_mode`, `synchronous`, `transaction_mode`.

### E5. Legal / licensing

SQLite is public domain; FTS5 is part of the amalgamation. `sqlite-vec` (Apache-2.0 or MIT) is
*not* adopted, so no review is required now; if it is ever proposed, the bundled binary goes through
the ADR-007 process. MySQL's GPLv2-with-FOSS-exception redistribution question is exactly why the
`server` profile is operator-installed and never bundled.

### E6. Measured — diacritic folding and FULLTEXT visibility on both profiles (S2-01, 2026-10-13)

Probed on the development machine against the S0-18 Swedish/Italian/German corpus: PHP 8.4.19 with
SQLite **3.45.2** (FTS5, `unicode61 remove_diacritics 2`) and **MySQL 9.3.0** (InnoDB FULLTEXT,
`utf8mb4_unicode_ci`, `innodb_ft_min_token_size = 3`).

| Query | SQLite FTS5 | MySQL FULLTEXT |
|---|---|---|
| `goteborg` → "Göteborg" | hit | hit |
| `sjostrom` → "Sjöström", `akersberga` → "Åkersberga", `molndal` → "Mölndal" | hit | hit |
| `naive`/`facade` → "naïve façade", `celik`/`bronte` → "Zoë Brontë-Çelik" | hit | hit |
| `mussig` → "Müßig" | **miss** (ß is not a diacritic to `unicode61`) | **hit** (`utf8mb4_unicode_ci` folds ß → ss) |
| `har` → "här" and "har" | both (accepted recall/precision trade) | both |
| rows written inside the open transaction | visible (FTS5 triggers are transactional) | **invisible** until commit |

The last two rows are why D4 folds and why the `search` group's corpus is committed and then
deleted. Two further `server`-only behaviours are left out of the contract queries rather than
worked around: InnoDB ignores tokens shorter than three characters and its own stopword list
(`the`, `for`, `not`, …), so a query of operator-looking words can match on `server` and miss on
`local`. `SearchTerms` keeps the surface narrow — word characters only, optional trailing `*` — so
nothing a person types reaches either engine as an operator.

Also measured (because external-content FTS5 keys the index by the content table's rowid, and D5's
backup rewrites the file): rowids of a `char(26)`-keyed table survive both `VACUUM` and
`VACUUM INTO`, and the FTS5 `integrity-check` on the snapshot passes, so a restored snapshot keeps a
valid index. `SqliteFullTextGrammar::rebuild()` exists for the case where it does not.

## Alternatives considered

1. **Bundle MySQL (or PostgreSQL) in the Mac installer from day one.** Adds a signed, notarised
   server binary of 100+ MB to the ADR-001 manifest, a second `launchd` service with its own data
   directory and upgrade path, and a GPLv2 redistribution review. §2 allows this only if a benchmark
   proves core-ready in under five minutes with it; the measured SQLite path leaves no performance
   reason to spend that budget. Kept as the `server` profile instead.
2. **Strict single-writer architecture: one sync worker, all writes funnelled through it.** Would
   trivially avoid contention, but the benchmark shows contention costs only tail latency and no
   failures, while a single worker would serialise the *network* fetches too and make eight
   accounts sync eight times slower. Rejected in favour of D3's rules with a two-worker default.
3. **One SQLite file per email connection (sharding).** Removes writer contention entirely and would
   let a single bad account be dropped by deleting a file, but kills the Unified Inbox's
   cross-account queries (`ATTACH` is limited to `MAX_ATTACHED=10`, below the "no hard account
   limit" requirement), multiplies backups and migrations by N and makes criterion 4's policy
   boundary a file-system question. Unnecessary given E1.
4. **Separate SQLite file for the queue.** Isolates `jobs` churn from application data; rejected
   because `jobs` writes are tiny relative to sync batches, the queue would then need its own
   backup/restore story, and `Bus::batch` + application writes could no longer share one
   transaction.
5. **Redis queue for core.** A second process to supervise and a second thing the five-minute
   installer can get wrong; the database queue on WAL handled the benchmark's write load with margin.
   Available in the `server` profile.
6. **`synchronous = FULL` + `fullfsync = ON` everywhere.** True power-loss durability at about half
   the write throughput; rejected as default because every email row is re-derivable by cursor and
   the one non-re-derivable write (the send receipt) is handled explicitly (D7, ADR-009).
7. **`sqlite3_backup` API as the primary backup.** Measured marginally faster (84 vs 101 ms) but
   restarts whenever another connection writes, which with live sync workers means pausing the queue
   for every backup. `VACUUM INTO` needs no pause. Kept as fallback.
8. **Meilisearch/Typesense for full-text, or any external vector database.** Each is another
   process, another binary in the manifest and another backup target; §3.2 forbids an external vector
   DB as a requirement and FTS5 is already in the bundled build.

## Consequences

Easier: one file to back up, snapshot and restore; the installer configures nothing but a path;
tests run against the same engine as production; the queue, cache and sessions need no extra
service; the contract suite makes the MySQL path real without anyone having to maintain it by hand.

Harder: every schema and query must be written portably from the start (D10); FTS5 triggers triple
per-row write cost; the team must respect the write-path rules rather than relying on the database
to be forgiving; a future multi-user deployment still needs the `server` profile to be exercised
beyond CI.

**Forbidden in code review from this ADR on:**

- Network I/O, model inference or any blocking call inside `DB::transaction()`.
- Row-at-a-time inserts in sync jobs (batch 100–500).
- Any file-level copy of the live database (`copy`, `rsync`, `cp`, `Storage::copy`), or a backup
  without `PRAGMA integrity_check` on the output.
- Credentials, tokens or passwords in `email_connections` or any table (Keychain reference only).
- `DEFERRED` transactions on the SQLite connection, raw driver-specific SQL outside the grammar
  classes, `enum` columns, non-UTC timestamps.
- `RefreshDatabase` in a test file (schema per CI job, `DatabaseTransactions` per test).
- Adding a vector or search engine dependency without a benchmark ADR; any external vector DB.
- Reopening "SQLite vs MySQL" without the measurements in *Reopen conditions*.

## Residual risks

| Risk | Plain language | Accepted by |
|---|---|---|
| Power loss with `synchronous = NORMAL` | The last few committed transactions may be lost. Email data is re-synced by cursor; a lost send receipt could cause one duplicate brief unless ADR-009's dedupe lands. | B, with ADR-009 (D + F + H) owning the send case |
| Benchmark machine is the upper bound | 100 000 inserts/s on an M4 Max says nothing about the minimum supported Mac. The run is repeated on the ADR-001 minimum profile before Accepted; a 10× slower machine still gives ~10 000 inserts/s, far above provider rates. | B, I |
| Benchmark is inserts only | No concurrent updates/deletes, no `jobs`-table churn, no brief generation in the same run. D3's rules bound these, and the contract suite's `sync-idempotency` group adds mixed workloads. | B |
| Database file readable while logged in (§3.1) | Not changed by this ADR. Encryption of the live file (SQLCipher) and of snapshots is ADR-003's decision; D5 hands snapshots to it. | H via ADR-003 |
| Checkpoint starvation by a long reader | A runaway export or report could grow the WAL unbounded. D6's nightly `TRUNCATE` and 256 MB health alert make it visible; D6 forbids long read transactions. | B |
| FTS5 missing from the shipped runtime | A different PHP build could drop `ENABLE_FTS5`; every search migration would fail at install. Recorded as a hard requirement on the ADR-001 runtime manifest with a CI check. | A, B |
| `server` profile drifts because nobody runs it | Mitigated by the required CI matrix; still a maintenance cost paid for a path no customer uses yet. | B |

## Blueprint references

§0, §2, §2.1, §3.1, §3.2, §5.6, §9, §11, §13.1, §13.2, §14, §15 (item 3), §17.

## Reopen conditions

Any one of the following reopens this ADR; the measurement artefact is the S0-07 benchmark re-run
with the same parameters:

1. On the ADR-001 **minimum supported machine**, 8 writers yield **< 5 000 inserts/s**, or any
   `SQLITE_BUSY` survives a 5 000 ms busy timeout, or p95 write-lock wait exceeds **2 s**.
2. In the field, `health_events` records `database is locked` on **> 0.1 % of queue jobs** over any
   7-day window, or the WAL health alert (D6) fires on three consecutive nights after the long-reader
   fix.
3. A measured, signed MySQL (or MariaDB) bundle reaches `core-ready` **inside the five-minute gate**
   on the minimum machine (§2's explicit condition) **and** a product requirement — not performance —
   needs a server database (e.g. multi-user hosted deployment).
4. FTS5 search p95 exceeds **500 ms** on a 1 000 000-message fixture, or a vector-search benchmark
   shows `sqlite-vec` cannot deliver the agreed recall — which reopens only the search part (D4),
   never the external-vector-DB prohibition.
5. The shipped runtime's SQLite loses `ENABLE_FTS5` or `VACUUM INTO` (3.27+), or Laravel removes
   the `transaction_mode` / `busy_timeout` connection options.
