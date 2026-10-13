# Data retention — the named rules and how a row expires

**Status:** Living document · **Owner:** Workstream B (Laravel platform) with H (security/privacy)
**Issues:** #86 (S2-03, this page) · #96 (S2-14, the privacy-mode rule) · #87 (S2-04, encryption at rest)
**Source of truth:** `app/Security/Retention/` and `database/migrations/2026_10_13_000006_create_data_retention_rules_table.php`
**Spec:** blueprint §10.1 item 3 ("gallring"), §10.3 · ADR-002 D5/D6/D8/D9.5/D10 · ADR-003 D1 ·
ADR-005 D4.4/D4.6 · OPEN-QUESTIONS **A18** · threat model **T-68**

## 1. Why the rules land with the schema

Every business object has carried `retention_policy` and `retain_until` since S1-01
([data-model §1.1](data-model.md#11-31-common-columns)). `retention_policy` is a **name**, and a name
that resolves to nothing is worse than a null: the purge job looks the row up, finds no rule, and
skips it — forever. Data kept by accident is the one failure mode §10.3's *gallring* requirement
cannot tolerate, and it is invisible until somebody asks why a three-year-old mailbox is still on
disk.

So resolution is defined before the first writer exists. `App\Security\Retention\RetentionRules` is
the only way a `retention_policy` value becomes a rule, and it **throws**
`UnknownRetentionRuleException` rather than returning null.
`tests/Feature/Retention/RetentionRuleResolutionTest.php` is where an unknown name fails, and
`tests/Integration/BackendContract/GovernanceSchemaContractTest.php` additionally asserts the
schema-wide property: *every table that carries a `retention_policy` column is governed by a rule
that resolves*, on both ADR-002 D1 profiles. A new business table added without a rule fails that
test on the branch that adds it.

## 2. What a rule is

A rule is not a number of days. `App\Security\Retention\RetentionRule` carries:

| Field | Meaning |
|---|---|
| `name` | the token a `retention_policy` column holds (`audit_log`, `crm_records`, …) |
| `description` | one sentence, shown to the owner and quotable in a GDPR answer |
| `basis` | the GDPR Article 6 basis the period rests on (`RetentionBasis`: consent · contract · legal_obligation · legitimate_interests). Only the four this product can honestly claim exist |
| `clock` | which column starts the countdown (`RetentionClock`: `created_at`, `updated_at`, `deleted_at`, `occurred_at`, `last_seen`) |
| `action` | `purge`, `anonymize`, or `keep_until_erased` (`PurgeAction`) |
| `retainDays` | the period, or **null** |
| `graceDays` | extra days after `retain_until` — the undo window on an accidental delete |
| `minimumRetainDays` | the floor the retention UI may not let a user go below |
| `userAdjustable` | whether the owner may change the period at all |
| `appliesTo` | the tables the rule governs, named explicitly rather than by pattern |

Three invariants are enforced in the constructor, which is what keeps the set honest:

1. **`retainDays === null` if and only if the action is `keep_until_erased`.** A clockless rule is
   always a declared decision; there is no way to write a rule that forgets to say when it expires.
   `RetentionRuleResolutionTest` asserts both directions and that the constructor refuses the
   contradictions.
2. **A floor only exists on an adjustable rule**, and never exceeds its own period. This is how the
   seven-year audit obligation survives contact with a settings pane: `audit_log`,
   `delivery_receipts`, `approval_record` and `email_send_log` keep at least a year whatever the
   owner sets.
3. **A rule governs at least one table**, and the contract test checks both directions — every
   governed table has a rule, and every rule's clock column exists on the tables it names.

`retainUntil($clockValue)` is what a writer puts in `retain_until`; `purgeableAt($clockValue)` adds
the grace and is the earliest instant `PurgeExpiredData` (§4.1) may act. Both are null for a
clockless rule, which is also what `retain_until` stays on those rows.

## 3. Two layers: the table and the shipped defaults

`RetentionRules::resolve($name, $organizationId)` reads, in order:

1. **`data_retention_rules`** — the per-workspace rows. This is where an owner's adjustment lives,
   and where a workspace-specific rule the defaults never named may be added (a customer contract
   that requires ninety days on their communications, say). A row is used only while `is_active` is
   true and it is not soft-deleted; `changed_by_user_id` and `audit_event_id` make an adjustment
   attributable the same way a privacy-mode change is, and `is_default` marks the rows
   `RetentionRules::install()` wrote so "reset to the shipped rule" is decidable.
2. **`RetentionRules::defaults()`** — the shipped rules, which every install has whether or not the
   table has been populated.

A stored rule is scoped to its workspace: a name only one workspace defines stays unknown
everywhere else, so a dangling name is never quietly satisfied by somebody else's rule.

`RetentionRules::install($organizationId)` copies the defaults into the table idempotently — a rule
the owner already adjusted keeps its numbers, a newly shipped rule is added, and nothing is
overwritten. The retention pane (S2-14 onwards) is the UI over those rows; it is not where the rules
come from.

## 4. The shipped rules

Periods are defaults, not promises: an adjustable rule may be changed down to its floor. "Clockless"
means `keep_until_erased` — the row leaves through a user deletion, a disconnect or a GDPR erasure,
never through a timer.

| Rule | Tables | Basis | Clock | Period | Action |
|---|---|---|---|---|---|
| `workspace_registry` | `organizations`, `memberships` | contract | `deleted_at` | clockless | keep until erased |
| `audit_log` | `audit_events` | legal obligation | `occurred_at` | 2555 d (floor 365) | purge |
| `governance_record` | `privacy_policies`, `data_retention_rules` | legal obligation | `created_at` | clockless | keep until erased |
| `health_telemetry` | `health_events` | legitimate interests | `occurred_at` | 90 d (floor 7) | purge |
| `backup_log` | `backup_runs` | legitimate interests | `created_at` | 365 d (floor 30) | purge |
| `brief_history` | `briefs`, `brief_items` | legitimate interests | `created_at` | 730 d + 30 d grace | purge |
| `delivery_receipts` | `brief_deliveries` | legal obligation | `created_at` | 2555 d (floor 365) | purge |
| `crm_records` | `customers`, `customer_contact`, `projects`, `project_links`, `project_contact`, `tasks`, `task_assignees` | contract | `deleted_at` | clockless + 90 d grace | keep until erased |
| `contact_personal_data` | `contacts`, `contact_emails` | legitimate interests | `updated_at` | 1095 d + 30 d grace (floor 90) | **anonymize** |
| `communication_timeline` | `communications` | legitimate interests | `occurred_at` | 1095 d + 30 d grace (floor 30) | purge |
| `commitment_log` | `commitments` | legitimate interests | `created_at` | 1095 d + 30 d grace (floor 30) | purge |
| `provider_snapshots` | `source_records` | legitimate interests | `created_at` | 365 d (floor 30) | purge |
| `connection_metadata` | `email_connections`, `external_accounts` | contract | `deleted_at` | clockless + 30 d grace | keep until erased |
| `sync_state` | `email_sync_cursors`, `sync_cursors` | legitimate interests | `updated_at` | clockless | keep until erased |
| `push_devices` | `push_subscriptions` | **consent** | `last_seen` | 180 d (floor 7) | purge |
| `mcp_registry` | `mcp_servers`, `mcp_tools` | legitimate interests | `updated_at` | clockless | keep until erased |
| `mcp_grant_record` | `mcp_grants` | legal obligation | `created_at` | clockless | keep until erased |
| `ai_run_log` | `ai_runs`, `tool_runs`, `browser_sessions` | legitimate interests | `created_at` | 365 d (floor 7) | purge |
| `ai_provider_registry` | `ai_providers`, `ai_models` | contract | `updated_at` | clockless | keep until erased |
| `approval_record` | `action_proposals`, `action_approvals` | legal obligation | `created_at` | 2555 d (floor 365) | purge |
| `memory_store` | `memories`, `memory_sources` | legitimate interests | `updated_at` | clockless + 30 d grace | keep until erased |
| `email_store` | `email_messages`, `email_threads`, `email_attachments` | legitimate interests | `occurred_at` | 1095 d + 30 d grace (floor 30) | purge |
| `email_account_state` | `email_folders`, `email_account_permissions`, `email_identity_mappings` | contract | `updated_at` | clockless | keep until erased |
| `email_send_log` | `email_delivery_attempts` | legal obligation | `created_at` | 2555 d (floor 365) | purge |

The last three rules name tables that **do not exist yet** — E3-2's mail store. They are declared
now, through `RetentionRules::plannedTableRules()`, so the inventory is complete the moment those
tables appear rather than when somebody remembers them: the contract test exempts a planned table
from the "table exists" check and still requires its rule to resolve. The four AI, approval and
memory rules were declared the same way while S2-02 (#85) and this item were open in parallel; S2-02
merged first and its nine tables moved up into `RetentionRules::tableRules()`, which is the stricter
list (the table must exist *and* carry `retention_policy`).

### Why these numbers

- **Seven years on the obligations.** `audit_log`, `delivery_receipts`, `approval_record` and
  `email_send_log` are the record that an action was *authorised* — the approver identity, the
  frozen input hash, the recipient, the nonce (§11). They are the first thing an incident or a
  dispute asks for, and the floor keeps them for a year even if the owner shortens the period.
- **Three years on content, measured from when it happened.** The CRM timeline, the mail store and
  contact data live on a legitimate-interests balance, which gets harder the older the data is.
  `occurred_at` rather than `created_at` means a back-filled bootstrap sync does not reset the clock
  on a five-year-old thread.
- **Anonymize, not purge, for contact data.** A contact with no activity for three years is reduced
  to a non-identifying stub: the timeline keeps its shape and the aggregate survives, while the
  person stops being identifiable (§10.3 data minimisation). This is the one rule whose action is
  not a delete, and it is deliberate.
- **Clockless where a timer would cause harm.** A sync cursor expired on a timer would silently
  trigger a full resync of every folder; a pinned `mcp_tools.schema_hash` expired on a timer would
  silently disable rug-pull detection (ADR-005 D4.6); the `governance_record` is the evidence that
  answers "when was Local Only turned off" (T-68). None of them may have a clock, so each declares
  `keep_until_erased` and says why.
- **One year on things that are re-derivable.** `provider_snapshots` and `ai_run_log` cost a re-sync
  or a re-run, not data (ADR-002 D3.4, D7).
- **Consent for push devices.** Web Push is opt-in (ADR-006), so the basis is consent and the clock
  is `last_seen`: a subscription nobody uses is a secret kept for nothing.
- **The consent trail is never shorter than the audit trail.** `audit_events` records *that* an
  outbound action happened; `action_proposals`/`action_approvals` record *that a human approved it*,
  with the approver identity, the frozen input hash and the nonce (§11). If `approval_record`
  expired first, an incident could prove the send and not the consent, so both the period and the
  floor match `audit_log` — asserted, not assumed.

### `expires_at` is never a retention clock

Several tables carry an `expires_at`, and in every case it is a **business** deadline, not a purge
deadline: the §11 approval window on `action_proposals`/`action_approvals` (minutes), ADR-005 D1.8's
60-minute `browser_sessions` grant, ADR-004 D5's evidence lapse on `ai_providers`, when a `memories`
row stops being *active*, when a push endpoint stops being valid. An approval that lapsed an hour ago
is exactly the row that proves who consented to what.

`retain_until`, derived from one of `RetentionClock`'s five columns, is the only column that means
"the purge job may delete this row". `expires_at` is deliberately not a `RetentionClock` case and
must never become one; `RetentionRuleResolutionTest` asserts both that and that no rule's clock
column is named like it.

## 5. What this page does **not** decide

- **Encryption at rest** is S2-04 (#87). No `encrypted:` cast is added by S2-03 — the governance
  tables classify their rows with `sensitivity_class` (`personal` on `email_connections` and
  `external_accounts`, `confidential` on `push_subscriptions`) and leave the values plaintext, so
  the two items do not half-apply each other's work.
- **The privacy-mode rule** was S2-14 (#96) and has **landed**: `App\Security\Privacy\PrivacyModeStore`
  is the one writer of `privacy_policies`, a widening transition needs the S2-05 re-authentication
  plus a UI re-confirmation, tightening needs neither, and every change writes
  `privacy.mode_changed` in the same transaction as the row (ADR-004 **D9**). S2-03 landed only the
  storage — see [data-model](data-model.md) `privacy_policies`,
  [privacy-modes](privacy-modes.md) and OPEN-QUESTIONS **A18**.
- **The purge job itself** (`PurgeExpiredData`, §4.1) and the retention pane. This page defines what
  the job reads; it does not schedule it. A clockless rule means the job has nothing to do for those
  tables, which is why it is safe for the job to land later than the rules.

  One thing about it **is** settled, by #127: the purge has **no user principal**, so it cannot be a
  `ShouldQueue` job running as its dispatcher the way S2-06 requires of everything that reaches
  tenant data. It deletes rows across every tenant on a schedule with nobody asking, and one
  dispatcher's memberships do not describe that. It is therefore a member of the tenant-wide
  maintenance category — `App\Security\Authorization\Maintenance\TenantWideMaintenance`,
  [authorization.md §10](authorization.md) — which also explains why §4's "erasure is the retention
  job's, not a policy's" and authorization §3's "`forceDelete` is refused to everybody" are
  consistent: the purge does not delete as a user with a very strong role, it deletes as an
  operation no user is behind. §10.5 is the checklist for whoever writes it.
- **GDPR export and erasure** (§3.2, §10.3 item 3). Erasure is the path that removes a
  `keep_until_erased` row; it is its own item and will reference the `basis` column when it has to
  justify refusing to erase something held under a legal obligation.

## 6. Running the checks

```bash
vendor/bin/pest tests/Feature/Retention tests/Integration/BackendContract/GovernanceSchemaContractTest.php
```

Both files are in the **`backend-contract`** group, including the pure-logic cases in
`RetentionRuleResolutionTest`. That is deliberate and it is what decides whether these assertions
run on MySQL at all: the `server-profile` CI lane runs `vendor/bin/pest --group=backend-contract`,
not the whole suite, so a profile-sensitive test outside the group would stay unexercised on the
second profile until somebody adopted it in anger. Resolution reads rows back through
`RetentionRule::fromRow()`, and how an engine returns a JSON column, a boolean and a nullable
integer is exactly the kind of difference the matrix exists to catch.

To run the `server` leg by hand, point the connection at a database that exists only for this
purpose and migrate it first — never at a database with data in it, and never with `migrate:fresh`,
`migrate:refresh` or `db:wipe` (see [data-model §5](data-model.md#5-backend-profiles)).
