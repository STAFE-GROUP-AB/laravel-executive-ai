# tests/Security

Negative security tests: cross-account isolation, prompt injection, hostile MCP servers, SSRF and
egress, privacy-mode downgrades, secret redaction and the §16.7 foreign-email refusal gate. Everything here asserts that something **does not
happen**, which is the hardest kind of test to keep honest — hence the contract below.

The adversarial data is frozen in the repository and written before the defence (S0-19, issue #19), by
someone who is not implementing the defence. Tests consume it; they never invent attacks inline.

## Where the data lives

| Corpus | Path | Frozen by | Schema |
|---|---|---|---|
| Prompt-injection red-team corpus (§14) | `packaging/fixtures/security/prompt-injection/` | S0-19 | `injection.schema.json` |
| Hostile MCP-server fixtures (§14) | `packaging/fixtures/security/hostile-mcp/` | S0-19 | `hostile-mcp.schema.json` |
| Synthetic secrets (redaction) | `tests/Security/fixtures/secrets-corpus.json` | ADR-003 D7, S1-01 — every value carries a FAKE/TEST marker; `.gitleaks.toml` allowlists only marked values in this one file | — |
| The world the payloads ride on | `packaging/fixtures/crm/`, `packaging/fixtures/briefs/` | S0-18 | `crm.schema.json`, `brief.schema.json` |

The two adversarial corpora live under `packaging/fixtures/` rather than here because they are not
PHP-only: the Swift menu bar, the PWA renderer and the Node validator read the same bytes, and CI's
`fixtures` job (`node packaging/fixtures/validate-schema.mjs`) is what keeps them well-formed. The
secrets corpus stays under `tests/` because it is only ever injected by PHP and Swift tests and must
never be shipped in a fixture pack that could be published as sample data.

Both corpora are **packs**: the validator assembles the files in a directory into one object keyed by
file basename, so cross-file rules (unique ids, language coverage, references into the S0-18 world)
are checkable. Read `packaging/fixtures/README.md` → "Security corpora" for the catalogue.

## The suites

| Suite | Reads | Status |
|---|---|---|
| `tests/Security/ForeignEmailCannotTriggerSendTest.php` | four `email_body` cases: `01M4JGMWH02GAABCPY27KT2DQK` (the §16.7 case verbatim), `01M4JGN0E09WM1ENPV27T4CB53` (From identity), `01M4JGN4B0GG8Y5HA5J0JRYDS0` (privilege escalation), `01M4JGMSK84X4MM4BWNBYD3PG0` (delimiter spoofing) | **Live, expected-failing** (S0-21, #21). Threat model T-01, T-05, T-06, T-08. xfail mechanism: `Support\ExpectedFailure::guard()` — the body runs, a failure is printed as `EXPECTED FAILURE #21` with the removal condition and CI stays green, an **unexpected pass fails the build**. Skipping, `todo()` and `markTestIncomplete()` are forbidden here and the helper re-throws a skip rather than absorbing it. Removal condition: *remove `expectedFailure` when E2-4 lands `PolicyGate` + approvals and all four cases pass* |
| `tests/Security/ExpectedFailureTest.php` | — | Proves the xfail mechanism in both directions (reported-and-green, and build-failed on XPASS), so a broken helper cannot silence the gate above |
| `tests/Security/PromptInjectionCorpusTest.php` | every case in `prompt-injection/*.json` | S1, once an agent run exists; the S0-21 file is its first rows |
| `tests/Security/HostileMcpServerTest.php` | every exchange in `hostile-mcp/*.json` | S1/S3, with the MCP client (ADR-005 D4). Threat model T-02, T-52 |
| `tests/Security/SsrfGuardTest.php` | nothing frozen — the attacks are **addresses and URLs**, written inline as a table | **Live** (S2-14). One row per row of threat-model §3.4.1, 104 cases, covering T-23 – T-29. The data is inline rather than a corpus on purpose: an address table has no language, carrier or context dimension to vary, and an attack whose whole content is `0177.0.0.1` is more reviewable beside its expected `EgressRule` than in a JSON file. Each obfuscation row asserts the refusal is the **loopback** rule, not a spelling rule — refusing them as "malformed" would be refusing by accident |
| `tests/Security/LocalOnlyEgressTest.php` | the AI fixture provider set (`ContractHarness`), run under `LOCAL_ONLY` with a cloud provider deliberately configured | **Live** (S2-14). One test per ADR-004 D4 layer, so removing any single layer leaves four red; zero recorded requests and `egress_blocked` raised for every cloud provider. The `policy_downgrade` corpus cases join `PrivacyModeDowngradeTest` once the admin pane (S2-19) exists. The packet-capture half is a **lab** step and is never claimed here — `docs/security/egress-guard.md` §7 |
| `tests/Security/PrivacyModeDowngradeTest.php` | — | **Live** (S2-14). Threat model T-68: a widening privacy change needs the S2-05 proof **and** a UI re-confirmation while tightening needs neither; the history is append-only with exactly one live row; `privacy.mode_changed` is written in the same transaction as the row; and `/health/ready` plus the PWA shell report the **stored** mode |
| `tests/Security/NoSocketTransportArchTest.php` | `app/**/*.php` | **Live** (S2-14). Closes T-27's GAP: no HTTP client may carry a Unix-socket or named-pipe option, and **no new outbound call site may bypass `EgressGuard`** — a file that makes one must be in `EGRESS_CALL_SITES` with a sentence saying how it is guarded. Our own broker socket is on an explicit local-IPC allowlist that a further test holds to being neither an HTTP client nor egress |
| `tests/Security/IdorTest.php` | the schema itself: every table carrying `organization_id`, with rows built by `Support\TenantRowFactory` | **Live** (S2-06, #88). Threat model T-55, T-73. A member *and* an owner of organization A are refused every row of B on read, write and delete; a brief of B is 403 over HTTP by version id and by document id; a spoofed `organization_id`/`user_id`/`role` in the query string changes nothing; a revoked membership stops being one. Driven from the schema, so a table added later is in the sweep without an edit |
| `tests/Security/PolicySurfaceArchTest.php` | the routes, the Livewire components, the queued jobs, the tool namespaces and the policy namespace | **Live** (S2-06). The `IpcPolicyArchTest` shape applied application-wide: a surface that reaches tenant data must name an ability, and there is no `before()` hook anywhere. Its detectors are proved against the fixtures in `Support/Surfaces/` |
| `tests/Security/AuditCompletenessTest.php` | `audit_events` | **Live** (S2-06). Threat model T-46: one row per refusal and per mutating action on each of the five T-73 surfaces, naming actor, ability, subject and surface; context is references only |
| `tests/Security/TenantRowFactorySelfTest.php` | the schema | **Live** (S2-06). The sweep's engine: a real row can be built for every tenant table, so none drops out of `IdorTest` quietly |
| the `cross_account_access` corpus cases | the S0-18 accounts | S3 with the live connectors (**E3-1**); the policy half is `IdorTest` above |
| `tests/Security/RedactorTest.php`, `LogRedactionTest.php`, `LoggingConfigTest.php`, `ExceptionRedactionTest.php`, `QueuePayloadTest.php`, `JobsCarryNoSecretValueTest.php`, `PackagedConfigTest.php` | `secrets-corpus.json` | **Live** (S1-01). ADR-003 D7 rows 1–3, threat model T-10 – T-12, T-75. The `secret_harvesting` cases join once an agent run exists |
| `tests/Security/EncryptedAttributeInventoryTest.php` | the schema and every model under `app/Models/` | **Live** (S2-04, #87). The arch gate on encryption at rest: the `encrypted:` casts and `App\Security\Secrets\EncryptedAttributeInventory` are the same list in both directions — so ADR-003 D8's re-encryption job cannot fall behind a cast, and an `encrypted:` cast added by any item other than S2-04 fails the suite — no inventoried column is a credential name (ADR-003 D1, Forbidden 1), none is in a full-text index (ADR-002 D4), none is read by an index at all, and each is wide enough for its ciphertext |
| `tests/Security/EncryptedAtRestTest.php` | `Tests\Support\SensitiveAttributeFixtures` (one plaintext row per inventoried table) | **Live** (S2-04). Blueprint §3.1: the stored column is not the plaintext and does not contain it, the model hands the exact bytes back, the blind index stays in step with the address on every write — and a read under a key that cannot decrypt **fails closed** with `UnreadableEncryptedAttributeException` rather than returning ciphertext, an empty string or anything a caller could mistake for the value. That last one is the A25 rejected-option test |
| `tests/Security/AppKeyRotationTest.php` | the same fixtures | **Live** (S2-04). ADR-003 D8 + Evidence E35: a row written under a `previous[]` key still reads before the queued job runs, the job re-encrypts every inventoried attribute and the blind index under the new key (proven by emptying `previous[]` afterwards), the `audit_events` row carries counts and never a value, and a row no key can read is reported rather than overwritten |

Which threat each case answers is recorded in
[`docs/security/threat-model.md`](../../docs/security/threat-model.md) (S0-23), not here: name the
`T-nn` ids in the test docblock alongside the case id so the register and the tests stay connected.

Each suite is **data-driven**: one test per case id, named after the case, so a failure names the
attack. In Pest:

```php
dataset('injection-cases', fn () => InjectionCorpus::cases());   // yields [id => $case]

it('refuses the attack', function (array $case) {
    $run = ExecutiveAgent::fake()->process(Carrier::from($case));   // inject through the carrier

    expect($run)->toRefuse($case['expected']);                      // asserts must_not AND must
})->with('injection-cases');
```

`toRefuse()` is the single place the effect vocabulary is interpreted. It must fail loudly on an
effect name it does not know, so adding a case with a new effect cannot silently assert nothing.

### The harness (`tests/Security/Support/`, S0-21)

S0-21 built that interpreter and the fakes the suites share. The sketch above is the shape; the
implementation is typed, because an untyped `$case['expected']` is one renamed key away from
asserting nothing:

| File | Role |
|---|---|
| `InjectionCorpus` / `InjectionCase` | loads and validates a frozen case by id, resolves `context.ref` into the S0-18 world, and builds the Pest dataset keyed by case id. Every problem is a `RuntimeException`, never a skip |
| `ForeignEmailArrival` | the payload as it arrives: the owner's benign turn, the body as a `ToolResult` message, and the account-resolved From identity |
| `Refusal` | `toRefuse()` — the one place the effect vocabulary is read. Throws a `LogicException` naming the case and the effect for any effect it does not interpret |
| `AgentRunUnderTest` / `AgentRunOutcome` | the seam E2-4/E2-5 implement, and the recorded facts a run reports. There is deliberately no `refused: bool` |
| `FakeAiProviderAdapter` | a model scripted to obey the injection completely (§7 puts policy in code, so no test may depend on the model behaving); records every `AiRequest` |
| `FakeEmailConnector` | the egress boundary: records any `send()` on a read-only connection, so the assertion is "no send exists", not "the send was refused" |
| `FakeWorkspaceRepository` | the data-gathering boundary, seeded with the frozen customers so a leak is recognisable |
| `AuditLogSpy` / `InMemoryAuditLogSpy` | the `audit_event_recorded` seam; swapped for an adapter over `audit_events` once S1-01 lands it. The event type is the case's `user_message_key`, so the forensic record and the UI message cannot drift apart |
| `ExpectedFailure` | real xfail semantics for a gate written before its defence |
| `TenantRowFactory` (S2-06) | inserts a real row into **any** table carrying `organization_id`, built from `Schema::getColumns()`, `Schema::getForeignKeys()`, the model's own casts and the `->json()` declarations in the migrations. No per-table knowledge, so the IDOR sweep covers a table added next sprint; a shape it cannot satisfy fails loudly instead of skipping |
| `PolicySurface` (S2-06) | the reader `PolicySurfaceArchTest` uses: which tables are tenant data, which models and policies exist, which sources reach tenant data, and which Livewire methods are actions |
| `Surfaces/` (S2-06) | the fixtures the detectors are proved against: a guarded job, an **unguarded** job, a `GatedTool` and an **ungated** tool. The guarded two are also the job and tool `AuditCompletenessTest` runs |

## The contract

These rules are what make the corpora worth having. A PR that breaks one is rejected.

1. **Every case's `expected.must_not` is asserted, effect by effect.** Not as a group, not as a
   summary — each forbidden effect is a separate expectation with the case id and the effect name in
   the failure message.
2. **"No exception was thrown" is never a pass.** Neither is "the tool returned an error". The
   assertion is on observable effects: no mail queued, no HTTP request recorded, no row written, no
   approval created, no mode changed.
3. **`expected.must` is asserted too.** Silence is a failure mode of its own: a payload that is
   dropped on the floor leaves the user unaware that an attack arrived, and §4.2 requires the item to
   appear with its source. `flagged_in_brief_as_suspicious`, `audit_event_recorded` and
   `original_payload_preserved_verbatim` are as load-bearing as the refusals.
4. **`allowed_output_mentions` is the only way text from the payload may appear in output.** The
   assertion is: the output may paraphrase the attack descriptively (and these strings say what that
   looks like), but it must contain no instruction-shaped copy of it and none of the `must_not`
   effects may have occurred. An empty list means the payload must not be surfaced at all.
5. **The payload is injected through its carrier, never pasted into the user's turn.** `context`
   says which field of which S0-18 record it occupies (`communications[].excerpt`,
   `tools[].description`, `events[].description`, …). A test that puts the payload in the user
   message is testing a different, much easier thing.
6. **The fixture clock is injected.** `FIXTURE_NOW = 2026-10-09T12:00:00+02:00` (`Date::setTestNow`,
   a Swift `Clock` provider). Every `context.arrives_at` is at or before it. Never read the wall
   clock.
7. **`delayed_trigger` cases run at least two turns.** The payload arrives in turn one and the
   assertion is on turn two (and on the next brief). A single-turn test cannot see this class at all.
8. **A case is never deleted or skipped to make the suite green.** An attack we do not yet defend
   against is a failing or `todo()` test with the issue number, visible in CI output. Removing a case
   is a security-critical PR with two reviewers and a stated reason.
9. **Hostile-MCP exchanges are asserted on `registry_action` and `connection_state_after` as well.**
   Refusing one call while leaving the server connected and granted is a failure: the recordings
   encode the state machine (`connected` → `grants_suspended` → `quarantined` / `blocked`), and the
   replay must follow it.
10. **`response: null` means nothing left the client.** The test asserts no socket was opened (an
    `Http::fake()` spy with zero recorded requests, or no process spawned), not merely that the call
    returned an error.
11. **Elided bodies are synthesised, not committed.** Where `transport.body_elided` is true, the
    harness builds the body from `declared_bytes` / `nesting_depth` / `elapsed_ms`. The repository
    never carries megabytes of filler, and the test never waits out a real timeout.

### Reading the effect vocabulary

The effect names are a frozen vocabulary defined once, in
`packaging/fixtures/security/prompt-injection/injection.schema.json` (`$defs/forbiddenEffect`,
`$defs/requiredEffect`); `hostile-mcp.schema.json` `$ref`s it and adds the MCP-specific names. How
each one is observed is an implementation choice, but the mapping lives in one helper. Examples of
the shape that mapping takes:

| Effect | Observed as |
|---|---|
| `tool_call:email.send` | the tool was never invoked; `PolicyGate` recorded a denial; no queued mailable |
| `url_fetched:<host>` | `Http::fake()` / `preventStrayRequests()` spy has zero requests to that host |
| `local_only_disabled`, `provider_switch` | the run's privacy mode and resolved provider are unchanged after the run |
| `from_address_changed` | the frozen proposal's sending account equals the account in `context.account_id` |
| `approval_auto_granted` | no `action_approvals` row exists for the proposal |
| `secret_in_output` | no value from `secrets-corpus.json` appears in the output, logs, transcripts or arguments |
| `treated_as_data` | the stored record is byte-identical to `payload`, and the model saw it inside the untrusted-content block |
| `audit_event_recorded` | an `audit_events` row exists with the expected type and no raw payload content |
| `grants_suspended` | every `mcp_grants` row for that server is inactive after the exchange |
| `schema_hash_recorded` | the stored `mcp_tools.schema_hash` equals the exchange's `schema_hash` |

`expected.user_message_key` is the translation key the UI shows. Tests assert the key, never the
translated string, so wording changes do not break security tests. `null` means the expected
behaviour is silent (the payload is simply summarised as data).

## Adding an attack

Same freeze policy as the S0-18 pack (`packaging/fixtures/README.md` → "Freeze policy"), with one
difference: for these corpora the unit of addition is a **case**, not a file.

1. Append a case to the carrier file it belongs to (`CARRIERS_BY_FILE` in
   `packaging/fixtures/validate-schema.mjs` says which carriers live in which file), or a new
   exchange to the hostile server it belongs to. New ULID, never reusing one.
2. Fill `expected` before writing any defence. If you cannot describe the refusal as machine-checkable
   effects, the case is not ready: that gap is the actual finding.
3. Ride on an existing S0-18 record where the carrier has one — the validator resolves every
   `context.ref` into the pack and rejects an invented id.
4. Payloads carry no real secrets, people, companies, hostnames, home paths or tailnet names. Only
   `example.*` / `attacker.example` / `exfil.example.net` and the fictional S0-18 world; the
   validator enforces this, including inside base64. An obviously-fake credential shape
   (`sk-FAKE-0000…`) is allowlisted in `.gitleaks.toml` for this directory only.
5. Write invisible characters as JSON `\u` escapes so the fixture stays reviewable in a diff.
6. A new carrier, attack class, encoding or effect name is a **schema change**: bump the schema, say
   why in the PR, and expect the two-reviewer path. Every enum value must be exercised by at least
   one case — the validator fails on an unused one, so dead vocabulary cannot accumulate.
7. For a hostile-MCP `tools/list` exchange, rewrite the hash:

   ```bash
   node packaging/fixtures/tools/hash-mcp-tools.mjs --write packaging/fixtures/security/hostile-mcp/<file>.json
   node packaging/fixtures/tools/hash-mcp-tools.mjs --check packaging/fixtures/security/hostile-mcp/*.json
   ```

8. Run the validator, then the suites. Call the new case out in the PR body by id and attack class.

## Running

```bash
npm ci --prefix packaging/fixtures          # the corpora are validated by the fixtures job
node packaging/fixtures/validate-schema.mjs

php artisan test --testsuite=Security       # registered in phpunit.xml by S0-21
```

The validator is the gate on the data, the suites are the gate on the behaviour. Neither substitutes
for the other: a corpus that validates while no suite reads it is decoration, and a suite that passes
because its corpus was quietly narrowed is worse than no suite at all.
