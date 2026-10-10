# install-timer

The §16 step 6 runner: records every mandatory installer phase of one install as JSON, aggregates
runs into p50/p95 per phase, and gates on the §12.2 targets. Plain Node (24+), no dependencies.

The protocol — what a clean machine is, where the stopwatch starts and stops, how a human prompt is
timed, how to submit results — is in
[`docs/operations/install-measurement.md`](../../../docs/operations/install-measurement.md). This
file is the tool reference.

## Commands

```bash
TIMER=packaging/manifest/install-timer/install-timer.mjs

# 1. One record per install. Events come from --events <file> or stdin.
node $TIMER record --profile mac-a --runtime-cached yes --clean yes \
    --events ~/Desktop/install-events.json \
    --out packaging/manifest/install-timer/results/2026-sprint1/ \
    --product-version 0.1.0-alpha.3 --commit 0123abc

# 2. Table + summary.json for one or more directories/files.
node $TIMER aggregate packaging/manifest/install-timer/results/2026-sprint1/ \
    --summary packaging/manifest/install-timer/results/2026-sprint1/summary.json

# 3. The gate. Exit 1 on a breach or on fewer than --min-runs eligible runs per profile.
node $TIMER gate packaging/manifest/install-timer/results/2026-sprint1/ \
    --p50 180000 --p95 300000 --min-runs 30

# Sprint 1 interim reporting (S1-14): relaxes only the run count, never the thresholds.
node $TIMER gate packaging/manifest/install-timer/results/2026-sprint1/ --allow-insufficient

# Tests
node --test 'packaging/manifest/install-timer/**/*.test.mjs'
```

### `record`

| Flag | Meaning |
|---|---|
| `--profile mac-a\|mac-b\|other` | §12.2 machine profile. `other` for anything that is not one of the two gated profiles. |
| `--runtime-cached yes\|no` | Whether the signed runtime bundle was already on disk and had been launched once before this run (ADR-001 E3). The §0 target is stated for cached runtimes; uncached runs are aggregated separately. |
| `--clean yes\|no` (default `no`) | Operator assertion that the clean-machine protocol was followed. Only `yes` runs count toward the gate. |
| `--events <file>` | Events JSON (array, `{"events": [...]}` or NDJSON). Omit to read stdin. |
| `--out <dir>\|-` (default `results`) | Where to write `<date>-<profile>-<cached\|uncached>-<ULID>.json`; `-` prints to stdout. |
| `--chip`, `--memory-gb`, `--macos-version`, `--macos-build` | Override or supply the machine profile. On macOS the tool reads `sysctl machdep.cpu.brand_string`, `sysctl hw.memsize`, `sw_vers`. |
| `--scenario` (default `fresh_install`) | `reinstall`, `upgrade`, `data_preserving_reinstall` are timed for coverage but never gate. |
| `--product-version`, `--commit` | What was installed. |
| `--note` | Free text, no personal data. |
| `--synthetic` | Marks generated or illustrative data; excluded everywhere by default. |

### Event format

One event per state transition, RFC 3339 timestamps (millisecond precision, any zone):

```json
{ "events": [
  { "state": "DMG_OPEN", "at": "2026-10-10T09:00:00.000Z" },
  { "state": "WELCOME",  "at": "2026-10-10T09:00:04.200Z" },
  { "state": "human_interaction_start", "at": "2026-10-10T09:00:04.200Z" },
  { "state": "human_interaction_end",   "at": "2026-10-10T09:00:07.900Z" },
  { "state": "PREFLIGHT",      "at": "2026-10-10T09:00:07.900Z" },
  { "state": "PACKAGE_VERIFY", "at": "2026-10-10T09:00:10.400Z" },
  { "state": "INSTALL_CORE",   "at": "2026-10-10T09:00:16.100Z" },
  { "state": "CREATE_OWNER",   "at": "2026-10-10T09:01:02.700Z" },
  { "state": "SELECT_AI",      "at": "2026-10-10T09:01:32.000Z" },
  { "state": "BOOT_SERVICES",  "at": "2026-10-10T09:01:45.600Z" },
  { "state": "HEALTH_CHECK",   "at": "2026-10-10T09:01:52.300Z" },
  { "state": "READY",          "at": "2026-10-10T09:02:01.800Z" },
  { "state": "MODEL_DOWNLOAD", "marker": "start", "at": "2026-10-10T09:02:05.000Z" },
  { "state": "OAUTH_GOOGLE",   "marker": "start", "at": "2026-10-10T09:02:20.000Z" },
  { "state": "OAUTH_GOOGLE",   "marker": "end", "outcome": "completed", "at": "2026-10-10T09:03:05.250Z" },
  { "state": "MODEL_DOWNLOAD", "marker": "end", "outcome": "completed", "at": "2026-10-10T09:09:48.000Z" }
] }
```

| Event | Rule |
|---|---|
| `WELCOME` … `READY` | The nine §12.1 states, each exactly once, in order, timestamps non-decreasing. The event marks **entry** into the state; a state lasts until the next one is entered. `READY` is the terminal marker: the moment `/health/ready` returned `ready: true` and the Done screen is shown. |
| `human_interaction_start` / `_end` | A prompt is open and the installer is waiting for the person. Pairs, no nesting, must lie inside `WELCOME`→`READY`. The Welcome CTA, the owner form, the AI choice, an admin-password sheet are all prompts. |
| `DMG_OPEN` | Optional, at most once, before `WELCOME`: when the DMG was double-clicked. Produces `dmg_open_to_welcome_ms`, informational. |
| Async steps | Any `UPPER_SNAKE_CASE` name that is not a core state, with `"marker": "start"` / `"end"` and an optional `"outcome"` (`completed` default, `skipped`, `failed`, `cancelled`). Known names: `CONNECT_EMAIL`, `CONNECT_GITHUB`, `PEEKABOO_PERMISSIONS`, `REMOTE_ACCESS`, `SCHEDULE_BRIEF`, `MODEL_DOWNLOAD`, `OAUTH_GOOGLE`, `OAUTH_MICROSOFT`, `OAUTH_GITHUB`, `ICLOUD_MAIL`, `HEY`, `TAILSCALE_LOGIN`, `IOS_PUSH`, `EMAIL_VERIFICATION`. A start without an end is recorded as `incomplete`. A step that starts before `READY` is flagged `overlaps_core_window: true`. |

A cancelled or failed install produces no record — there is no `READY`. Count it as an attempt in
the campaign notes and file the installer log as an issue.

### The record

`record.schema.json` (JSON Schema 2020-12) is the contract; `results/example/synthetic-mac-a-cached.json`
is a validated, **synthetic** example. The essential structure:

```text
core_ready_ms                  ready_at − started_at           ← the gated number (§12.2)
├─ human_interaction_ms        sum of prompt intervals          ← own phase, already inside core_ready_ms
├─ automated_ms                core_ready_ms − human_interaction_ms
└─ phases.<STATE>              { started_at, duration_ms, human_ms }   durations sum to core_ready_ms
dmg_open_to_welcome_ms         informational, null when not timed
async_post_core
├─ counted_in_core_ready_ms    const false
└─ steps.<NAME>                { started_at, ended_at, duration_ms, outcome, overlaps_core_window }
```

"Never summed by construction": `core_ready_ms` is computed from two timestamps only, the record
has no field that adds core and async together, the schema rejects any extra field, and
`validateRecord` cross-checks `core_ready_ms == ready_at − started_at == Σ phases`. A record whose
`core_ready_ms` has been inflated with a download is invalid, not merely wrong.

### `aggregate`

Groups by `(profile, runtime_cached)`; prints p50/p95/min/max for `core_ready_ms`,
`human_interaction_ms`, `automated_ms` and every phase, then a **separate** table for async steps
(runs with the step, outcomes, completed-duration percentiles, runs where the step overlapped the
core window). Writes `summary.json` (`--summary <path>`). Synthetic records are excluded unless
`--include-synthetic`; `--pool-runtime-cached` merges cached and uncached runs into one group.

Percentiles are **nearest-rank** (value at `ceil(p/100 · n)` in the sorted sample): a reported p95
is always a time that actually occurred.

### `gate`

Eligible runs: not synthetic, `clean_machine: true`, `scenario: fresh_install`, and
`runtime_cached` matching `--runtime-cached` (`yes` default; `no` or `any`). For each profile in
`--profiles` (default `mac-a,mac-b`):

| Status | Condition | Exit |
|---|---|---|
| `PASS` | `n ≥ --min-runs`, `p50 < --p50`, `p95 < --p95` (strictly less, §12.2) | 0 |
| `FAIL` | `p50 ≥ --p50` or `p95 ≥ --p95`, regardless of run count | 1 |
| `INSUFFICIENT` | thresholds met, `n < --min-runs` | 1, or 0 with `--allow-insufficient` |
| `NO_DATA` | no eligible runs | 1, or 0 with `--allow-insufficient` |

Any record that fails schema validation fails the gate before anything is computed. `--out <file>`
writes the gate result as JSON (`gate.json`) for the release PR's evidence link.

## Files

| Path | What |
|---|---|
| `install-timer.mjs` | The tool. Exports `buildRecord`, `validateRecord`, `aggregateRecords`, `evaluateGate` for tests and future tooling. |
| `record.schema.json` | Record contract. Also validated by `packaging/fixtures/validate-schema.mjs` (ajv, CI). |
| `results/` | Committed records per campaign; `results/example/` is synthetic. See `results/README.md`. |
| `test/` | `node:test` suite: synthetic 35-run pass, p95 ≥ 300 s fail, insufficient runs, human time, async separation, validation. |
