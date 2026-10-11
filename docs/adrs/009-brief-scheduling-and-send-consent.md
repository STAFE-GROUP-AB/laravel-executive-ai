# ADR-009: The daily brief is opt-in, at most one per organization per local day by unique constraint, caught up after login, and emailed only under a test-message-backed consent for one recipient and one content type

| | |
|---|---|
| **Status** | Proposed |
| **Date** | 2026-10-13 |
| **Deciders** | D (AI/Agents) owns scheduling and generation; F (Integrations) owns the sending path; H (Security/privacy) owns the consent mechanics and reviews the whole; B (Laravel platform) reviews the schema and the durability change; A (macOS/Swift) and C (TALL/PWA) review the surfaces. The §15.9 product question was **decided 2026-10-11** under the owner's delegation (`docs/OPEN-QUESTIONS.md` §15.9, "DECIDED 2026-10-11"); the owner accepts the ADR itself |
| **Closes** | Blueprint §15 item 9; `docs/OPEN-QUESTIONS.md` §15.9 and the ADR-009 half of A2; the send-receipt durability case ADR-002 D7 deferred here |

## Context

Blueprint §1.3 defines the Executive Brief as a document with `id`, `version`, `period_start/end` and
`delivery_state` that is "assembled and frozen transactionally once", delivered idempotently
("mail may not be sent twice on retry"), with a "local preview mode without outgoing mail". §5.5 fixes
the sending precondition in one sentence: *"Ingen e-post skickas innan mottagare och sändkonto är
bekräftade"* — configure SMTP "with a verified recipient and a test message", and approval of daily
automatic delivery "is given separately". §5.6 narrows the consent: brief distribution runs "from an
explicitly chosen and tested sender account", and *"ett samtycke till schemalagd automatisk
briefdistribution gäller enbart dess valda mottagare och innehållstyp; inga övriga mail får skickas utan
särskilt godkännande"*. §5.6 acceptance criterion 7 is the test: *"En testad brief skickas exakt en gång
genom explicit valt konto efter samtycke; menyrad och mejl återger samma version."* §15 item 9 listed
"brief default schedule/timezone, SMTP sender verification and send-consent" as unresolved;
`docs/OPEN-QUESTIONS.md` §15.9 carried the defensible default — **no default schedule at all**, opt-in
time and IANA zone with the Mac's own zone pre-filled, a test message as the precondition — and
**closed it on 2026-10-11** ("DECIDED 2026-10-11", under the owner's delegation of the same day): the
defensible default stands, and the product shows a *suggested* time of **07:00 in the Mac's own zone**
as a pre-filled field only, never active until the person saves it **and** a test message to the
confirmed recipient has succeeded. D3 below is that ruling, not a proposal; the ADR's own Status is
Proposed because the owner accepts ADRs.

Three Sprint 0/1 decisions constrain the shape:

- [`services.md`](../architecture/services.md) §5: the Supervisor is a **user LaunchAgent** (ADR-001
  D3), so nothing runs before login; *"a 05:00 brief scheduled while nobody is logged in is not
  generated at 05:00"*, and generation therefore "must be idempotent per day and catch up, so that the
  first `schedule:work` tick after login produces the day's brief if none exists yet". services.md
  records that rule as owed to this item.
- [ADR-002](002-local-db-and-queue.md) D7: `synchronous = NORMAL`, `fullfsync = OFF`, justified because
  every email write is re-derivable from the provider — except *"recording that an outgoing brief was
  sent"*, which D7 explicitly hands to ADR-009 together with the option to raise "that single write to
  `fullfsync`". ADR-002's residual-risk table names "ADR-009's dedupe" as the control.
- [`brief-schema.md`](../architecture/brief-schema.md) §2 and §5 and
  [`data-model.md`](../architecture/data-model.md) §2 already make double delivery impossible to
  *record*: `idempotency_key` per frozen version, a deterministic `Message-ID`, the append-only
  `brief_deliveries` log with `brief_deliveries_idempotency_attempt_unique` and
  `brief_deliveries_single_sent_unique`, and the freeze rule that a correction is `version + 1` under
  the same `id`. This ADR builds on those; it changes none of them and keeps the brief schema at 1.0.0.

What breaks if this stays open: S2-17 (#99) can generate a brief but has no rule for *when*, so a
scheduler entry would either fire at a fixed wall-clock time in an unknown zone or not at all after a
reboot; Sprint 3's email platform (E3-1) would have to invent the consent gate its `send()` path must
honour; and §5.6 criterion 7 has no definition to test against. Ambiguity A2 asked for this ADR to be
written in Sprint 2 so it gates Sprint 3 rather than Sprint 0.

Scope: this ADR decides scheduling, catch-up, the opt-in model, the send consent, the durability of the
send receipt, the versioning interplay and the observability of the new jobs. It does **not** decide
what the brief contains (S2-17, `brief-generation.md`), which providers can send (ADR-008, E3-1), or the
approval model for any other outgoing mail (§5.6 "read-only som grundläge", E2-4). The code half of
S2-18 implements D1–D7 after S2-17 lands; names below are the proposed ones from issue #100.

## Decision

### D1. Scheduling model: one brief per organization per local day, by unique constraint

1. **The unit of scheduling is the organization's local calendar day.** The zone is
   `organizations.timezone` (IANA, already in the data model), set by the user when scheduling is
   enabled (D3). "Today" is the calendar date in that zone at the moment of the tick; the day boundary
   is local midnight in that zone, never UTC midnight and never the Mac's current zone if the user
   chose another.
2. **A new append-only claim table `brief_days` enforces "at most one brief per organization per local
   day"**: `organization_id`, `local_date` (DATE), `timezone` (the IANA zone at claim time),
   `slot_at` (UTC instant the brief was due, D1.3), `document_id` (the brief schema `id` of that day's
   brief), `catch_up` (bool, D2), `generated_at`, and the unique index
   **`brief_days_organization_local_date_unique (organization_id, local_date)`**. The row is inserted
   **inside the freeze transaction** of the day's `version 1`
   (`App\Briefs\BriefDocumentMapper::import()`, data-model §3), so a claim exists if and only if a
   frozen brief exists. A unique-constraint violation means another tick already froze today's brief;
   the loser rolls back its draft and discards it. **No `exists()`/`firstOrCreate()`/cache-lock may
   stand in for the index**: locks and `ShouldBeUnique` are permitted only as optimisations that avoid
   wasted generation work, never as the correctness guarantee, because the cache can be lost
   ([`ai-state.md`](../architecture/ai-state.md) §1 makes the same point) and a scheduler the
   Supervisor restarts because it stopped answering (services.md §3) may still be mid-tick when its
   replacement starts.
3. **The slot.** For a schedule `{time_of_day, timezone}` the day's slot is the first instant **at or
   after** `local_date time_of_day` in that zone. On a spring-forward day where the wall-clock time
   does not exist, the slot is the first existing instant after the gap; on a fall-back day where it
   exists twice, the slot is the first occurrence. The slot is computed from the schedule and the
   date every tick; it is not stored ahead of time, so a changed time or zone takes effect on the next
   tick without migration.
4. **`period_start` / `period_end` describe the intended period, never the generation time.**
   `period_end` is the day's slot instant. `period_start` is the `period_end` of the organization's
   most recent frozen brief, so consecutive briefs tile the timeline with no gap and no overlap
   however late a brief was generated; for the first brief after enabling, and whenever the tiling
   rule would reach back more than **14 local days**, `period_start` is the slot instant 14 local days
   earlier (the slot of the previous local day for the first brief), and the audit event records the
   truncated range (D7). The generator queries sources by `[period_start, period_end)`; anything that
   arrives after `period_end` belongs to the next brief. `generated_at` records when the brief was
   actually frozen and may be hours later than `period_end` (D2); the two are different fields in the
   frozen schema precisely so this is representable.
5. **Only today's local date is ever generated.** A past date whose slot was missed is never
   back-filled (Alternatives 5); its content is covered by the tiling rule in D1.4. The brief for a
   local date is generated at the earliest tick at which the slot has passed and no claim exists —
   which is the scheduled time on a normal day and the catch-up time after login, sleep or pause (D2).

### D2. Catch-up after login, and its cut-off

1. **Mechanism.** `php artisan briefs:tick`, registered in `routes/console.php` as
   `->everyMinute()->withoutOverlapping(5)` beside `health:selftest`, runs
   `App\Briefs\Generation\DailyBriefSchedule::tick(now)`: for every organization with an enabled
   schedule, compute today's local date and slot (D1.3); if `now >= slot` and no `brief_days` row
   exists for `(organization_id, local_date)`, dispatch `GenerateDailyBrief` (D7.1). The condition
   contains nothing about login, sleep or reboot: catch-up is the same code path as on-time generation,
   which is why the first tick after login produces the day's brief (services.md §5) without a
   separate "missed" state to maintain.
2. **Cut-off: the end of the local day.** A day is caught up at any tick until local midnight; after
   that "today" is a new date and D1.5 applies. Rationale: the day is the unit the user consented to
   ("daily"), so a brief frozen at 23:50 for a 05:00 slot is still the day's brief — the menu bar, the
   PWA and the mail promise are kept for that date, and D1.4 keeps the content window exact regardless
   of when it ran. A shorter window (e.g. "no catch-up once half a day has passed") would add a
   configurable knob and a "skipped day" state to explain in three surfaces, and gain nothing: the
   next day's brief covers the same content either way.
3. **"Pause AI actions" defers, it does not skip.** While the ADR-004 D7 cancellation token is set
   ([`ipc-contract.md`](../architecture/ipc-contract.md) §6.4: "the scheduler skips brief
   generation"), `tick()` dispatches nothing and inserts no claim; on resume, the next tick catches up
   if it is still the same local day. A pause that spans local midnight leaves that date without a
   brief by D1.5; health shows `brief_state: stale` once `stale_after` passes, exactly as §6.4 already
   specifies. The same deferral applies while `ai_ready == false` (ai-state.md §1): a scheduled brief
   needs a model, so the tick waits for AI to become ready rather than freezing a brief the generator
   cannot ground; the schedule itself can be enabled before AI is ready (D3.1). Pause also defers
   **delivery** of an already frozen brief (D4.6): the user who presses Pause expects the app to do
   nothing on its own, and sending is the one irreversible thing it does.
4. **Sleep and wake, logout and login, reboot.** No special handling: `schedule:work` resumes with the
   session (services.md §5 table) and D2.1 does the rest. The `brief_days.catch_up` flag is `true`
   when `generated_at - slot_at` exceeds the tick interval, so the operations guide and the audit log
   can tell on-time briefs from caught-up ones.

### D3. No default schedule; a suggested 07:00 in the Mac's zone, pre-filled only (§15.9, decided 2026-10-11)

1. **Nothing is scheduled until the user schedules it.** A new table `brief_schedules`
   (`organization_id` unique, `enabled`, `time_of_day` `HH:MM`, `timezone` IANA, `enabled_at`,
   `enabled_by`, plus the §3.1 common columns) has **no seeded row**; `briefs:tick` iterates only rows
   with `enabled = true`. There is no environment variable that enables scheduling, and no code path
   writes an `enabled` row without a person pressing Save in `ScheduleSettings`.
2. **The suggested time is `07:00`, in the Mac's own zone, and it is a pre-filled form value and
   nothing else** (the §15.9 ruling). `config('lea.briefs.suggested_time')` is the string `'07:00'`;
   `App\Livewire\Briefs\ScheduleSettings` renders it in the time field and the detected Mac zone in the
   zone picker, both editable, with the sentence "No brief is scheduled yet" above them. The
   suggestion has **no** effect on `briefs:tick`, which reads `brief_schedules` only: an install where
   nobody opened the page has an empty table and generates nothing, however long it runs. Changing the
   config value changes the pre-fill and nothing else; that is the property `NoDefaultScheduleTest`
   pins, so the value can never silently become a default schedule.
3. **Two conditions make a brief go out, and the ruling binds them together.** Saving a time enables
   *generation* (the brief appears in the menu bar and the PWA). Email delivery additionally requires
   the successful test message and confirmed recipient of D4 — "never active until the person saves it
   and a test message to the confirmed recipient has succeeded" is exactly D3.1 plus D4.2, and no
   other combination enables outgoing mail.
4. **The zone.** The zone picker is pre-filled from `App\Support\Time\MacTimeZone` (reads the
   `/etc/localtime` symlink target under `zoneinfo/`, validated against
   `DateTimeZone::listIdentifiers()`; falls back to `organizations.timezone`, then `UTC`). Save writes
   `brief_schedules` and `organizations.timezone` in one transaction and audits
   `brief.schedule.enabled` (D7.3); the Save button stays disabled until a time is present. When the
   PWA is opened remotely (ADR-006), the page may *show* the browser's zone as a hint when it differs;
   it never replaces the pre-fill, because the brief runs on the Mac.
5. **Scheduling and sending are two separate switches.** Enabling the schedule makes the brief appear
   in the menu bar and the PWA (`menubar` and `pwa` channels). Email delivery is a second, later opt-in
   governed by D4; the settings page says so in one sentence above the delivery section.
6. **What the surfaces say before scheduling is enabled.** The installer's Done screen
   ([`installer.md`](../architecture/installer.md) §7 "optional-connections checklist") gains the
   unchecked line "Daily brief: not scheduled — choose a time in Settings"; it is informational and
   never a blocker for `READY`. For the menu bar and `/home`, S2-18's code half bumps the health
   contract by a **minor** (1.0.0 → 1.1.0, [`health-contract.md`](../architecture/health-contract.md)
   §4) with the optional field `brief_schedule: {enabled, next_at, timezone}`; while
   `brief_schedule.enabled == false` the menu bar shows "No daily brief scheduled — set a time" in the
   neutral band instead of "Stale brief" once the install placeholder ages out, and `/home` shows the
   same banner. Until a menu bar build decodes the field it keeps the existing state table
   ([`menubar.md`](../architecture/menubar.md) §2), which is conservative (`stale`), never wrong.

### D4. Send consent: test message first, one recipient, one content type, revocable

1. **The consent record.** A new table `brief_send_consents`: `organization_id`, `recipient`
   (normalised address), `content_type` (`executive_brief` — the only value in v1),
   `sender_connection_id` (FK → `email_connections`, the explicitly chosen sender account, ADR-002 D8),
   `test_message_id` (the deterministic `Message-ID` of the test message), `test_sent_at`,
   `confirmed_at`, `granted_at`, `granted_by`, `revoked_at`, `revoked_reason`, a generated column
   `active_marker` (`1` while `revoked_at IS NULL`, else `NULL`) and the unique index
   **`brief_send_consents_active_unique (organization_id, recipient, content_type, active_marker)`** —
   the same portable partial-unique idiom as `brief_deliveries_single_sent_unique`, so revoked rows
   keep the history and exactly one consent can be active per target. Rows are never deleted;
   `revoked_at`/`revoked_reason` is the only update.
2. **Precondition: a successful, confirmed test message (§5.5).** `App\Briefs\Delivery\SendConsent`
   grants a consent only after, in this order: (a) the chosen `email_connections` row is `connected`
   and holds a send capability for that account (E3-1; nothing can satisfy this before Sprint 3);
   (b) a **test message** from that sender to that recipient was accepted by the provider and the
   receipt recorded (`test_message_id`, `test_sent_at`); (c) the user entered the six-character
   confirmation code carried in the test message into `ScheduleSettings` (`confirmed_at`). The test
   message is a fixed template with no customer data — it is sent *before* any consent exists, to an
   address that may be mistyped — containing only the sender, the recipient and the code. (c) is what
   "verified recipient" means here: it proves a person who controls that mailbox is the one enabling
   daily delivery, and it is the cheapest control against a daily leak to a typo.
3. **Scope: exactly that recipient and that content type; nothing else rides on it.** The delivery
   job (D5) asks `SendConsent::authorize(organization, recipient, BriefMailable)`; the method accepts
   only an `App\Briefs\Render\BriefMailable` built from a frozen version and returns the active consent
   for `(organization_id, recipient, executive_brief)` whose `sender_connection_id` is still
   `connected`, or throws. Any other mailable, any other recipient, any other content type and any
   caller outside `App\Briefs\Delivery` is refused by construction and by the architecture test in
   `SendConsentTest` (Evidence). The consent never authorises `reply`, `forward` or any mutating mail
   operation of §5.6 — those keep their own per-message approval (E2-4, threat-model T-01). The `From`
   identity is the consent's sender account and nothing else (T-05).
4. **Revocation.** One action in `ScheduleSettings` sets `revoked_at` with `revoked_reason = user`.
   Changing the recipient revokes the old consent (`recipient_changed`) and starts D4.2 again from the
   test message; there is no "edit recipient". Changing the sender account likewise
   (`sender_changed`). Revocation never deletes briefs or deliveries; the schedule stays enabled and the
   brief keeps appearing in the menu bar and the PWA.
5. **Disconnected or unavailable sender (ADR-003 D8 order).** On **Disconnect**, the consent is revoked
   (`sender_disconnected`) in the same transaction as `email_connections.status = disconnected`, i.e.
   after the provider revoke and `SecretStore::delete` and before the audit row and the retention
   purge of D8's sequence; the settings page then reads "Daily brief is generated but not emailed —
   choose a sender and send a test message". On **`needs_reauthorization`** the consent stays: the
   identity is unchanged, the delivery job records `skipped` with `error = sender_unavailable`, the
   UI shows `Reconnect` (§5.6 criterion 5), and delivery resumes after reauthorisation without a new
   test message.
6. **Preview-only until E3-1.** Email delivery requires an active consent; a consent requires a
   `connected` sender with a send capability; no connector can provide one before E3-1. The send path
   can therefore be built and tested now against a fake connector while production remains
   preview-only **by construction**, and `PreviewCannotSendTest` (S1-03) keeps asserting that the
   renderer and the preview surfaces reference no mailer. While paused (D2.3) the delivery job releases
   itself back to the queue with a delay rather than skipping.

### D5. Send-receipt durability — the case ADR-002 D7 deferred

1. **What is recorded, where.** Every email delivery attempt is two rows in `brief_deliveries`,
   never one: first `status = queued` (attempt *n*, the deterministic `message_id` from
   `App\Briefs\Render\BriefMessageId`), committed **before** the connector is called; then exactly one
   outcome row for that attempt — `sent` (with `message_id`, `sent_at` and the new nullable column
   **`brief_deliveries.provider_message_id`** holding the provider's own id from `SendReceipt`),
   `failed` (sanitised `error`) or `skipped` (`error` names the reason: `already_sent`,
   `no_active_consent`, `sender_unavailable`, `paused`). The document's `deliveries[]` and
   `delivery_state` are the export of these rows (brief-schema §3); `audit_events` gets one row per
   outcome (D7.3). A `queued` row with no outcome row is the signal that an attempt was in flight when
   the process died.
2. **The `sent` row is the one write that cannot be re-derived, so it is committed durably.** The
   transaction that inserts the `sent` row runs with `PRAGMA synchronous = FULL` and
   `PRAGMA fullfsync = ON` on its connection and resets both afterwards; every other write keeps
   ADR-002 D7's `NORMAL`/`OFF`. Cost: one `F_FULLFSYNC` per sent brief per day, measured by ADR-002 at
   roughly half the *bulk* throughput — irrelevant at one write a day. ADR-002 D7 offered exactly this
   option; this ADR takes it.
3. **A retry never double-sends.** Before any send the job: (a) returns `skipped already_sent` if a
   `sent` row exists for `(idempotency_key, channel, recipient)` (data-model §2 rule); (b) if a
   `queued` row without an outcome exists for an earlier attempt, **reconciles against the provider**
   by looking up the deterministic `Message-ID` (Gmail API `q=rfc822msgid:<id>`; Microsoft Graph
   `$filter=internetMessageId eq '<id>'`; IMAP `SEARCH HEADER Message-ID` on the sent folder where the
   account exposes one) — if found, it inserts the `sent` row with the found `provider_message_id`
   and `error = reconciled` and stops; (c) only then sends. Two workers racing on (c) are stopped by
   `brief_deliveries_single_sent_unique`: the second insert of a `sent` row fails and is recorded as
   `skipped already_sent`, which is the only legal reaction to that constraint error. The deterministic
   `Message-ID` remains the last line of defence for a sender whose provider offers no lookup
   (Residual risk R1).
4. **Retries and failure.** `SendBriefDelivery` runs with `tries = 3` and exponential backoff; each
   try is a new `attempt` and therefore a new `queued`/outcome pair. Exhausting the tries writes the
   `failed` row, moves `delivery_state` to `failed`, and audits `brief.delivery.failed`; the menu bar
   and the PWA still show the frozen brief (the content was frozen before any delivery, brief-schema
   §5). Nothing retries automatically after the third failure; the user re-sends from `/briefs` within
   the existing consent.

### D6. Idempotency and versioning

1. **A correction is `version + 1` under the same `id`, never an edit** (brief-schema §5, S2-17
   acceptance). `brief_days.document_id` is how a correction finds its day: `GenerateDailyBrief` in
   correction mode reads the claim for `(organization_id, local_date)` and freezes `version + 1` with
   the same `id`, the same `period_start/end`, a new `idempotency_key` and a new `content_hash`.
   Because the claim already exists, a correction never creates a second `brief_days` row and D1 holds
   trivially.
2. **The delivery record references id + version.** `brief_deliveries.brief_id` points at the version
   row and carries that version's `idempotency_key` (data-model §2), so the receipt names exactly
   which `(id, version)` was sent; `/briefs` shows which version was emailed next to each version
   (brief-schema §5 "remains the version that was emailed").
3. **Corrections are not emailed automatically.** `version >= 2` of a day's brief reaches the
   `menubar` and `pwa` channels on freeze; the `email` channel for a correction requires one explicit
   "Send corrected brief" action in `/briefs`, which runs D5 under the existing consent (same
   recipient, same content type — no new test message). Corollary and the invariant §5.6 criterion 7
   asks for: **at most one automatic email per organization per local day**, and a tested brief is
   sent exactly once per version.
4. **IPC and parity are unchanged.** `GET /ipc/v1/briefs/latest` keeps returning the latest frozen
   version; the ETag `"<id>:<version>"` (ipc-contract §6.2) already changes on a correction;
   `BriefParityPayload` stays the one projection
   ([`brief-parity.md`](../architecture/brief-parity.md) §1), so a corrected brief is one more input
   to the S1-10 gate, not a new surface.

### D7. Observability: health, heartbeats, audit

1. **Jobs and queues.** `briefs:tick` runs inside the scheduler process and is cheap (one indexed
   query over `brief_schedules`). Generation and delivery are queued jobs on the **`briefs`** queue the
   worker already serves (data-model §4): `App\Briefs\Generation\Jobs\GenerateDailyBrief` —
   `ShouldQueue`, `ShouldBeUnique` on `organization_id:local_date` for the job's lifetime (an
   optimisation per D1.2), `timeout` 600 s — and `App\Briefs\Delivery\Jobs\SendBriefDelivery`, one per
   `(brief_id, channel, recipient)`. Neither job may run inline in the scheduler process.
2. **Health.** No new subcheck: `scheduler_selftest` proves ticks fire (`briefs:tick` runs beside
   `health:selftest` under the same `schedule:work`), `queue_worker` proves the `briefs` queue is
   drained, and `brief_state: error` already means "the last scheduled brief generation failed"
   (health-contract §4) — `GenerateDailyBrief`'s `failed()` hook is what sets it, and `latest_brief`
   keeps pointing at the last good version. The 1.1.0 field `brief_schedule` (D3.6) adds
   `next_at` so the menu bar and `/admin` can show when the next brief is due, computed from
   `brief_schedules` and `brief_days` on each `/health/ready` call, never cached.
3. **Audit events** (`audit_events`, grammar as in ipc-contract §10: `ai.paused`), context redacted by
   `Redactor` and never containing brief content: `brief.schedule.enabled` / `.changed` / `.disabled`
   (`time_of_day`, `timezone`); `brief.generated` (`document_id`, `version`, `local_date`, `slot_at`,
   `catch_up`, `period_start`, `period_end`, `period_truncated`); `brief.generation.failed`
   (sanitised reason); `brief.consent.test_sent`, `brief.consent.granted`, `brief.consent.revoked`
   (`recipient`, `sender_connection_id`, `reason`); `brief.delivery.sent` / `.skipped` / `.failed` /
   `.reconciled` (`brief_id`, `version`, `attempt`, `recipient`, `provider_message_id` where present,
   the `skipped`/`failed` reason). A `brief_days` unique-constraint loss is a `health_events` row
   (ADR-002 D6) and a log line, not an audit event: it is expected behaviour under concurrency, counted so Reopen
   condition 3 can be observed.

## Evidence

**An ADR without this section filled cannot move to Accepted.** Status is Proposed; it moves to
Accepted when the four S2-18 tests below are green on both backend profiles and the health 1.1.0
fixtures are committed. The product question is **not** outstanding: `docs/OPEN-QUESTIONS.md` §15.9
records the 2026-10-11 ruling (no default schedule, a suggested 07:00 in the Mac's zone as a pre-filled
field only, never active until Save plus a successful test message), which D3 implements verbatim and
`NoDefaultScheduleTest` pins.

- **Design inputs already in the repo** — services.md §5 (user LaunchAgent, the catch-up rule owed
  here); ADR-002 D7 and its residual-risk row (the deferred send receipt); brief-schema §2 and §5
  (deterministic `Message-ID`, append-only `deliveries[]`, `version + 1`); data-model §2
  (`brief_deliveries_idempotency_attempt_unique`, `brief_deliveries_single_sent_unique`, the
  generated-column idiom) and §4 (the `briefs` queue, `scheduler_selftest`, `queue_worker`);
  ipc-contract §6.4 (pause skips generation and reports `stale`); ADR-003 D8 (disconnect order);
  ai-state §1 (cache loss fails safe, the argument for a database constraint over a cache lock);
  `App\Briefs\Render\BriefMessageId` and `tests/Feature/Briefs/Mail/PreviewCannotSendTest.php` (S1-03,
  preview-only by construction).
- **Tests the code half must add** (`tests/Feature/Briefs/`, `DatabaseTransactions`, both backend
  profiles, ADR-002 D10):
  - `DailyBriefIdempotencyTest` — fires `briefs:tick` repeatedly and concurrently for one organization
    and one local day (including with the cache cleared between ticks and with two workers racing the
    freeze) and asserts exactly one `brief_days` row and one `version 1`; asserts the second insert
    fails on `brief_days_organization_local_date_unique`, not on an application check; asserts a
    correction produces `version 2` under the same `id` and no second claim (D1, D6).
  - `BriefCatchUpAfterLoginTest` — with a 05:00 schedule and no tick between 04:00 and 09:30
    (simulated logout/sleep), asserts the first tick at 09:30 freezes the day's brief with
    `period_end = 05:00` local, `period_start` = the previous brief's `period_end`, `generated_at =
    09:30`, `catch_up = true`; asserts that a tick after local midnight does **not** back-fill the
    missed date; asserts the spring-forward and fall-back slots of D1.3; asserts a paused core defers
    and resumes within the same day and skips across midnight (D2).
  - `NoDefaultScheduleTest` — on a fresh install asserts no `brief_schedules` row and that 48
    simulated hours of ticks dispatch nothing; asserts `ScheduleSettings` pre-fills `07:00` and the
    detected Mac zone, both editable, and that Save is disabled until a time is present; asserts the
    suggestion is **inert** — setting `config(['lea.briefs.suggested_time' => '05:00'])` still
    dispatches nothing over the same 48 hours, and only a saved row does — which is the §15.9 ruling
    expressed as a test (D3).
  - `SendConsentTest` — asserts no email delivery without an active consent; no consent without a
    `connected` sender, a recorded test message **and** the confirmation code; that
    `SendConsent::authorize` refuses any mailable other than `BriefMailable`, any other recipient and
    any other `content_type`; that revocation, recipient change and sender disconnect stop delivery
    with `skipped` and the right reason while generation continues; that `needs_reauthorization`
    skips with `sender_unavailable` and resumes after reconnect without a new test; that a retry after
    a `queued`-without-outcome row reconciles against a fake connector and inserts no second `sent`;
    and the architecture assertion that nothing outside `App\Briefs\Delivery` references
    `SendConsent` or a mailer (D4, D5). `PreviewCannotSendTest` stays green unchanged.
  - `tests/Unit/Briefs/BriefDaySlotTest.php` (additional, small) — pins D1.3 and D1.4 arithmetic on
    fixed dates and zones, including the 14-day cap.
- **Vendor documentation** — SQLite `PRAGMA synchronous` and `PRAGMA fullfsync`
  (<https://www.sqlite.org/pragma.html>): *"a transaction committed in WAL mode with synchronous=NORMAL
  might roll back following a power loss or system crash"* (the sentence ADR-002 D7 quotes), FULL
  syncs the WAL on every commit, and `fullfsync` *"determines whether or not the F_FULLFSYNC syncing
  method is used on systems that support it"* — the basis of D5.2. Laravel 13 task scheduling
  (<https://laravel.com/docs/13.x/scheduling>): `schedule:work` runs the scheduler in the foreground
  and invokes it every minute, `withoutOverlapping` and the `ShouldBeUnique` job contract
  (<https://laravel.com/docs/13.x/queues#unique-jobs>) — both used only as optimisations per D1.2.
  Gmail API `users.messages.list` accepts Gmail search syntax in `q`, including `rfc822msgid:`
  (<https://developers.google.com/gmail/api/reference/rest/v1/users.messages/list>); Microsoft Graph
  `message.internetMessageId` is filterable
  (<https://learn.microsoft.com/graph/api/resources/message>) — the basis of D5.3's reconciliation.
  E3-1 re-verifies each sentence against the page version current when the connector lands and
  records the date in ADR-008.
- **Measured prototype** — none needed for the decision itself; the one performance question (one
  `F_FULLFSYNC` per day) is bounded by ADR-002's measurement (55–79 k inserts/s with `fullfsync = ON`).
- **Legal / licensing input** — none: no new dependency, binary or model.

## Alternatives considered

1. **A fixed default schedule (05:00 in the Mac's zone), on by default.** The blueprint's "05:00" is an
   illustration, not a requirement. A default time enables generation in a zone the product has not
   been told, pretends an unattended 05:00 run that services.md §5 shows is not guaranteed, and — if
   coupled to delivery — contradicts §5.5 outright. Rejected; `docs/OPEN-QUESTIONS.md` §15.9 reaches
   the same conclusion and the 2026-10-11 ruling confirms it. The *suggested* time was the separate
   question in that entry, and the ruling answered it: `07:00` in the Mac's zone, pre-filled in the
   form and inert until saved (D3.2) — a suggestion, not a default.
2. **A LaunchDaemon so the brief runs before login.** Rejected in ADR-001 Alternatives 6: admin
   approval in System Settings, no access to the user's Keychain or `~/Library` without privilege
   juggling, contrary to §12.2 and §6 — and FileVault makes unattended boot impossible anyway (§9). The
   catch-up rule in D2 is the honest answer; a daemon would still need it.
3. **Sending without a test message, or treating "recipient entered" as consent.** Rejected by §5.5's
   sentence and §5.6 criterion 7; it also turns a typo into a daily leak of customer data to a stranger.
   D4.2's confirmation code costs one copy-paste once.
4. **Check-then-insert with a `Cache::lock` as the idempotency guarantee.** Rejected: the lock is
   advisory, the cache can be cleared or lost (ai-state.md §1 relies on exactly that failure mode to
   fail safe), two `schedule:work` processes can overlap briefly after a Supervisor restart, and the
   blueprint's §3.1 asks for unique indexes rather than application checks. Locks remain as an
   optimisation only (D1.2).
5. **Back-filling every missed local day after a long absence.** Rejected: a week away would produce
   seven briefs and, with consent, seven emails on the morning of return, each summarising stale
   content. One catch-up brief whose period tiles back to the last frozen brief (D1.4) loses nothing
   and keeps "daily" meaning daily; the 14-day cap keeps the brief a brief.
6. **Registering the brief with `Schedule::...->dailyAt('05:00')->timezone(...)`.** Laravel's schedule is
   static per process: one zone and one time for all organizations, re-read only on restart, and
   `dailyAt` fires in the minute it names — a tick missed during logout is simply gone, so catch-up
   would have to be bolted on anyway. A per-minute `briefs:tick` that derives the slot from the
   database gives on-time generation, catch-up and per-organization zones in one rule.
7. **Emailing every corrected version automatically.** Rejected: the recipient consented to a daily
   brief; two or three mails for one day because the generator found late data erodes trust and breaks
   criterion 7's "exactly once". Corrections reach the local surfaces at once and the mail on request
   (D6.3).
8. **`fullfsync = ON` for the whole database.** Rejected by ADR-002's measurement (roughly half the
   write throughput); only the one non-re-derivable write needs it (D5.2).

## Consequences

Easier:

- Catch-up, sleep, logout and pause are one code path with one observable condition ("slot passed,
  no claim today"); `BriefCatchUpAfterLoginTest` can simulate all of them with the clock alone.
- The timeline of briefs tiles exactly (D1.4), so "what did I miss" never depends on when the Mac
  was on; the fixture pack's "empty period" case is simply a day with no new sources.
- The consent is a row with a unique index, so "is email allowed for this target" is one query and
  one policy, reusable unchanged by E3-1's connectors.
- The send receipt has a defined crash story (D5.1 two rows, D5.3 reconciliation), closing ADR-002's
  open residual-risk row.

Harder:

- Three new tables (`brief_schedules`, `brief_days`, `brief_send_consents`) and one column
  (`brief_deliveries.provider_message_id`), each with a migration, factory and both-profile CI run;
  data-model.md must be extended by the code half.
- A health 1.1.0 minor and the matching fixtures, and menu bar / `/home` copy for the unscheduled
  state; until the Swift side decodes `brief_schedule` the panel stays at the conservative `stale`.
- `MacTimeZone` reads a symlink outside the app's directory; it must fail soft (D3.4) and be covered on
  the lab Mac (S1-14 style), not only in CI.
- Reconciliation (D5.3) needs a per-provider lookup; E3-1 must implement it for every sender it
  ships or accept R1 explicitly for that provider.

**Forbidden in code review** because of this decision:

- `Schedule::...->dailyAt(...)`, `->daily()` or any fixed-time entry for brief generation; a seeded
  `brief_schedules` row; reading `lea.briefs.suggested_time` anywhere but the form's initial state
  (`briefs:tick` reading it is the bug `NoDefaultScheduleTest` exists to catch); any env var or config
  flag that enables scheduling or delivery.
- `exists()` / `firstOrCreate()` / `updateOrCreate()` / a cache lock as the guard for "one brief per
  day" or "one sent per target" — the unique indexes are the guard; a constraint violation is handled
  as "already done", never retried.
- Calling `Mail::send`, `Mail::queue`, a connector's `send()` or `BriefMailable` outside
  `App\Briefs\Delivery`; passing anything but a `BriefMailable` built from a frozen version through
  `SendConsent::authorize`; a consent row with a `content_type` other than `executive_brief` without a
  new ADR section.
- A `sent` row without a preceding `queued` row for the same attempt; sending without the
  reconciliation step when a `queued` row has no outcome; a `From` address other than the consent's
  sender account.
- Automatic email of `version >= 2`; back-filling a past `local_date`; computing `period_start/end`
  from `now()`.
- Brief content, subjects, bodies or recipients' message text in `audit_events.context`.

## Residual risks

| # | Risk, in plain language | Mitigation in v1 | Accepted by |
|---|---|---|---|
| R1 | A sender whose provider offers no `Message-ID` lookup (plain SMTP without IMAP access to a sent folder) can still produce **one duplicate email** if the process dies between the provider accepting the message and the durable `sent` commit. | D5.2 shrinks the window to the fsync itself; the deterministic `Message-ID` lets receiving systems that de-duplicate by it collapse the pair; the `queued`-without-outcome row makes the event visible and audited (`brief.delivery.reconciled` is absent, `sent` follows a gap) | F proposes · H accepts; E3-1 lists which shipped senders fall under R1 |
| R2 | After an absence longer than 14 local days the catch-up brief's period is truncated (D1.4); items older than the cap are not in any brief. | They remain in the inbox and CRM "Needs Attention" views; the audit event records `period_truncated`; the generator's summary may say the period was shortened (S2-17 wording) | D |
| R3 | Mac zone detection fails (no `/etc/localtime`, unknown identifier) and the form pre-fills `UTC`. | The zone is a visible, editable field; Save requires an explicit time anyway, so the user sees the zone before anything runs | C |
| R4 | The test message goes to a mistyped address. | It contains no customer data, only the sender, recipient and code; nothing further is sent until the code is entered (D4.2) | H |
| R5 | A consent survives a provider re-authorisation of the same account (D4.5); if the account's identity changed server-side (alias rename), the `From` shown to the recipient changes without a new test. | `From` is always the connection's current primary address; ADR-003 D8 reauthorisation keeps the same `email_connections` row only when the provider account id matches | F |
| R6 | Two ticks may both generate before one loses on the `brief_days` index (D1.2), wasting a model run. | `withoutOverlapping` and `ShouldBeUnique` make it rare; the loss is counted in `health_events` (Reopen conditions 3) | B, D |
| R7 | A long pause silently leaves days without briefs (D2.3). | Health shows `stale` and the menu bar "AI actions paused"; the Resume path is one click; no email is promised while paused | D, C |
| R8 | Until the Swift menu bar decodes health 1.1.0's `brief_schedule`, an unscheduled install shows "Stale brief" after the placeholder ages out. | Conservative, not false; `/home` carries the correct banner from the code half onwards; A tracks the decode in the same sprint | A |

## Blueprint references

§0, §1.1, §1.2, §1.3, §3.1, §4.2, §5.5, §5.6 (criteria 5, 7, 8), §9, §10, §11, §12.1, §12.2, §13.2,
§14, §15 (item 9), §16 (step 2), §17.

## Reopen conditions

1. A later product ruling asks for scheduling or delivery to be **on** by default, or for the
   suggested time to become an active schedule without a Save → reopens D3 **and** D4 against §5.5,
   because that is a change of principle, not a config value. Moving the suggested time itself
   (07:00 → another hour) is not a reopen: it is `lea.briefs.suggested_time` and one line in D3.2.
2. E3-1 ships a sender whose provider cannot look up a message by `Message-ID` and the Owner is not
   willing to accept R1 for it → D5.3 needs a provider-side idempotency token or that sender is not
   offered for brief delivery.
3. `health_events` records a `brief_days` unique-constraint loss on more than **1 % of local days**
   over any 30-day window → the Supervisor is running two schedulers (services.md §3) or the tick
   overlaps; fix the process model before touching D1.
4. A product requirement for **more than one recipient** per organization, per-member briefs, or a
   second content type (e.g. a weekly digest) → extend D4.1's `content_type` and the per-recipient
   consent flow in a new ADR section; the unique index already allows one active consent per
   recipient.
5. ADR-002 raises `fullfsync` globally or changes the journal mode → D5.2 collapses into it.
6. services.md §5 changes the process model (a daemon, or a scheduler that survives logout) → re-read
   D2; the unique constraint and the tiling rule stay.
7. The health contract moves to 2.0 or the menu bar state table is redesigned → re-anchor D3.6 and
   D7.2.
8. A field incident shows a duplicate brief email that D5.3 should have prevented → incident; D5 is
   amended before the fix merges, and the threat model register
   ([`threat-model.md`](../security/threat-model.md)) gains the row.
