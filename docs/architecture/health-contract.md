# Health check contract (S0-14)

**Status:** Frozen at `schema_version` **1.0.0** · **Owner:** Workstream B (Laravel platform)
**Schema file:** [`packaging/fixtures/health.schema.json`](../../packaging/fixtures/health.schema.json)
**Examples:** [`packaging/fixtures/health/`](../../packaging/fixtures/health/)
**Validation:** `node packaging/fixtures/validate-schema.mjs` (CI step, see S0-05)

Blueprint §12.2 defines **`ready`** as HTTP health, migrations, login, menu integration, local brief
placeholder, background worker *and* scheduler self-test, and **AI-ready** as a separate state that
requires a successful model completion. §0 is emphatic that an install without a model shows
**AI setup required**, never a false **AI ready**. The installer's `HEALTH_CHECK → READY` transition
(§12.1), the menu bar's status area (§1.1) and `launchd` health probes (§9) all gate on this
contract, so it is specified before either side is written.

## 1. Endpoints

| Endpoint | Purpose | Status codes | Touches |
|---|---|---|---|
| `GET /health/live` | Is the PHP process up and able to serve a request? | `200` always when reachable | Nothing. No database, no cache, no filesystem. |
| `GET /health/ready` | Is the core **ready** per §12.2, and what is the AI / brief / connectivity state? | `200` when `ready: true`, `503` otherwise. Body shape identical in both cases. | Cached heartbeats and a few indexed reads. Never generates a brief, never calls a model, never calls a provider. |

Both return `Content-Type: application/json; charset=utf-8` and `Cache-Control: no-store`.

### `/health/live`

```json
{ "status": "ok" }
```

That is the whole body. A supervisor (`launchd`/`SMAppService`, §9) uses it to decide "restart the
process or not"; it must stay true while the database is locked, migrating or corrupt, because
those are `ready` problems, not liveness problems. Restart loops caused by a slow migration are the
failure this separation prevents.

### `/health/ready`

Top-level fields (all required, see schema):

| Field | Type | Meaning |
|---|---|---|
| `schema_version` | `"1.0.0"` | Contract version. Same compatibility rule as the brief schema: minor adds optional fields, major removes/renames/retypes or changes an enum. |
| `ready` | bool | **True if and only if every entry in `checks` is `ok`.** Independent of `ai_ready`. This is §12.2 core-ready. |
| `checks[]` | exactly 7 `Check` | One entry per §12.2 subcheck (section 2). The schema requires all seven names to be present once. |
| `ai_ready` | bool | **True only after a recorded successful model completion with the currently selected provider** (section 3). Never derived from configuration being present. |
| `ai_state` | `not_configured` · `configured_untested` · `ready` · `error` | Finer-grained AI state for the UI (section 3). |
| `ai_provider` | adapter id or `null` | The selected adapter (e.g. `ollama`, `openai`). `null` iff `ai_state` is `not_configured`. Never a key, endpoint or model path. |
| `ai_last_success_at` | RFC 3339 or `null` | The completion that `ai_ready` rests on. Required non-null when `ai_ready` is true. |
| `brief_state` | `none` · `fresh` · `stale` · `error` | Section 4. |
| `latest_brief` | `{id, version, generated_at, stale_after}` or `null` | `null` iff `brief_state` is `none`. Enough for the menu bar to show date/time and decide staleness without fetching the brief. |
| `connectivity` | `online` · `offline` · `degraded` | Internet reachability **as last measured by the sync layer**, cached. `online`: all enabled external providers reachable; `degraded`: some; `offline`: none, or no route. With no external providers enabled (pure Local Only) it reports the OS route state. |
| `privacy_mode` | `LOCAL_ONLY` · `EU_RESTRICTED` · `CUSTOM` | §4, §10. Shown in the menu bar and the PWA so the user always sees which mode is active. |
| `pending_approvals` | int ≥ 0 | Number of action proposals awaiting the owner (§11). **The only field that may drive a notification badge** (§1.1). |
| `checked_at` | RFC 3339 | When the response was produced. |
| `duration_ms` | int ≤ 5000 | Total time spent producing the response. |

### `Check`

```json
{
  "name": "queue_worker",
  "status": "ok",
  "detail": "worker heartbeat 12s ago",
  "checked_at": "2026-10-10T09:12:03+02:00",
  "duration_ms": 0,
  "age_seconds": 12,
  "threshold_seconds": 90
}
```

| Field | Meaning |
|---|---|
| `name` | One of the seven names in section 2. |
| `status` | `ok` · `fail` · `skip`. A timeout is `fail` with `detail: "timeout"`. `skip` means the check was **not evaluated because a check it depends on failed** (for example `auth_login` is skipped when `database_migrations` failed); `skip` never counts as `ok` for `ready`. |
| `detail` | Human-readable, sanitised, ≤ 500 chars. No tokens, credentials, mail content, file paths that reveal user names, or stack traces (§11 "aldrig tokens i app logs"). |
| `checked_at`, `duration_ms` | Per-check timing; `duration_ms` ≤ 2000 by contract. |
| `age_seconds`, `threshold_seconds` | Optional, for heartbeat-based checks: how old the last successful heartbeat is and the maximum age that still counts as `ok`. Lets the UI show "last seen 7 s ago" without parsing `detail`. |

## 2. The seven subchecks

Each §12.2 clause is one individually reportable item. Thresholds below are the contract defaults;
they live in `config/health.php` and the schema does not fix them.

| `name` | §12.2 clause | What `ok` means | How it is evaluated cheaply |
|---|---|---|---|
| `http` | "HTTP health" | The request reached the application through the bundled HTTP server and the Laravel kernel booted. | Trivially true when the handler runs; included so the array is complete and so the installer can distinguish "server up, kernel broken" (`/health/live` ok, `/health/ready` unreachable) from "kernel up, check failed". |
| `database_migrations` | "migrations" | The SQLite database opens and every migration in the bundle has run; **zero pending**. | One query on the `migrations` table compared with the bundled migration list. `detail` names the latest batch and the pending count. |
| `auth_login` | "login" | An owner account exists and the local login self-test passed within its interval. | Owner-exists is one indexed query. The login self-test (owner resolution, a session round-trip over an isolated store with a synthetic request, and a one-time-code issue/verify round-trip through the real services inside a rolled-back transaction — [auth.md](auth.md) §6) is run by the scheduler, not on demand; the endpoint reads its last result and `detail` names the part that failed. |
| `menubar_integration` | "menu integration" | The SwiftUI app has sent an IPC heartbeat within `threshold_seconds` (default 120). | The IPC server records the time of the last authenticated heartbeat (IPC contract S0-15, #15); the endpoint reads the cached timestamp. `age_seconds` is set. |
| `brief_placeholder` | "local brief placeholder" | At least one brief document exists — a real brief **or** the install-time placeholder (a `brief.schema.json`-valid document with `model_provenance.provider: "none"`). | `SELECT … ORDER BY generated_at DESC LIMIT 1` on `briefs`. The menu bar can therefore always open to *something* (§14 "native menu opens instantly from cached brief"). |
| `queue_worker` | "background worker" | The database-queue worker process wrote a heartbeat within `threshold_seconds` (default 90). | Worker heartbeat is a cached timestamp written by the worker loop every 30 s. `age_seconds` is set. |
| `scheduler_selftest` | "scheduler self-test" | The scheduled `health:selftest` job (every minute) ran within `threshold_seconds` (default 180). | The job writes a timestamp and runs the login self-test above. If the scheduler is not firing, this ages out and `ready` drops. `age_seconds` is set. |

**Dependencies for `skip`:** `database_migrations` depends on nothing; `auth_login`,
`brief_placeholder`, `queue_worker` and `scheduler_selftest` depend on `database_migrations`;
`menubar_integration` and `http` depend on nothing. When a dependency fails the dependants report
`skip` with `detail` naming the failed dependency.

## 3. `ai_ready` and `ai_state`

```text
not_configured ──select provider──▶ configured_untested ──recorded successful completion──▶ ready
                                            │                                                 │
                                            └──completion failed / provider error ──▶ error ◀──┘
```

- `ai_ready` is **true iff `ai_state == "ready"`**, and `ready` is entered only when an `ai_runs`
  record exists with status succeeded for the **currently selected** provider and model (§3.1
  `ai_runs`). Selecting a provider, saving a key, or even a successful `GET /models` against the
  provider does **not** make it true. §0: "Utan tillgänglig modell/API-nyckel ska appen vara
  installerad men tydligt visa AI setup required, inte felaktigt AI ready."
- `configured_untested` is the state between "Choose AI" (§12.3 step 5) and the first real
  completion. The installer may run a tiny completion as part of `SELECT_AI` to move through it;
  if the user skips, the state stays and the UI shows **AI setup required**.
- `error` means the last attempted completion failed (auth, quota, network, unsupported capability).
  The UI shows the error state; `ai_ready` is false. Changing provider resets to
  `configured_untested`.
- Changing provider or model **always** resets `ai_ready` to false until a new completion succeeds.
- `ai_ready` is **not** part of `ready`. Core-ready without AI is a legitimate, fully supported
  state (§12.2 "do not count as AI-ready"; §4.2 "AI unavailable får inte hindra CRM, läsa tidigare
  brief eller admin").

## 4. `brief_state` and `latest_brief`

| `brief_state` | Condition | Menu bar |
|---|---|---|
| `none` | No brief and no placeholder. Only possible before `INSTALL_CORE` completed; `brief_placeholder` is then `fail`. | "No brief yet" |
| `fresh` | `latest_brief` exists and `now < latest_brief.stale_after`. | Normal: headline + `generated_at` |
| `stale` | `now >= latest_brief.stale_after`. | **Stale brief** indicator (§1.1), still shows the cached brief |
| `error` | The last scheduled brief generation failed (job failed or produced an invalid document). `latest_brief` still points at the last good version. | **Error** indicator with "Open diagnostics"; still shows the cached brief |

`latest_brief` carries only `id`, `version`, `generated_at` and `stale_after` (from the brief
schema). The menu bar fetches the full brief through the IPC read-latest-brief operation (S0-15)
and caches it; the health endpoint is never the transport for brief content.

## 5. Timeouts and cost

- **Each subcheck ≤ 2 000 ms**, enforced with a per-check deadline. Exceeding it yields
  `status: fail`, `detail: "timeout"`.
- **Whole endpoint ≤ 5 000 ms.** When the total budget is exhausted, remaining checks are reported
  as `skip` with `detail: "budget exhausted"` and `ready` is false.
- These are ceilings, not expectations. Every check is designed as an O(1) read of a cached
  heartbeat or an indexed row; the fixtures show realistic totals under 10 ms. §14: "no brief
  generation on every menu open", "low idle CPU/memory". The endpoint **never**: generates a brief,
  calls a model, calls an external provider, runs migrations, or spawns a process.
- The response may be served from a 5-second cache keyed on nothing (one global entry), so a menu
  bar polling every few seconds costs one evaluation per 5 s at most.

## 6. Authentication and exposure

- Both endpoints are served **only on the loopback bind** of the bundled HTTP server (§2 "localhost
  authenticated IPC / loopback HTTPS"; §6). They are excluded from any Tailscale Serve route
  allowlist (§8) and from the PWA's public routes.
- `GET /health/live` requires **no token**: it reveals nothing, and the supervisor that probes it
  has no session. It still refuses non-loopback source addresses.
- `GET /health/ready` requires the **IPC token** defined by the IPC contract (S0-15, #15): the
  session-bound bearer token issued at install and stored in the Keychain, with the IPC version
  header and origin check that contract specifies. The body contains state (brief id, pending
  approvals, provider) that must not be readable by any other local process.
- The PWA `/admin` diagnostics page shows the same data by calling the health service **in-process**
  behind the owner's authenticated session and the `viewDiagnostics` policy; it does not proxy the
  HTTP endpoint.

## 7. Menu bar mapping (§1.1)

§1.1 requires the panel to "visa tydligt vid stale brief, offline eller fel" and to badge "endast
för verkliga handlingspunkter". The mapping is fixed here so S1-09 and S1-11 implement it the same
way:

| Panel state | Driven by | Notes |
|---|---|---|
| **Core unreachable (error)** | `/health/ready` cannot be fetched over IPC, or returns a non-JSON body | Derived by the Swift side from the IPC failure, not from a field. Show "Open diagnostics", "Restart services". |
| **Not ready (error)** | `ready == false` | List the failing `checks[]` by `name` with `detail`. During install this is the normal `HEALTH_CHECK` screen. |
| **AI setup required** | `ai_state ∈ {not_configured, configured_untested}` | Banner, not an error. CRM, previous brief and admin remain usable (§4.2). Never show "AI ready" unless `ai_ready == true`. |
| **AI error** | `ai_state == error` | Error indicator on the AI row with "Reconfigure AI". |
| **Stale brief** | `brief_state == stale` | Show the cached brief with a visible stale marker and its `generated_at`. Also treat `brief_state == none` as stale-equivalent ("No brief yet"). |
| **Brief error** | `brief_state == error` | Error indicator; cached brief still shown. |
| **Offline** | `connectivity == offline` | Informational: local features work, sync is queued (§9). `degraded` shows a softer "some sources unreachable". |
| **Privacy mode** | `privacy_mode` | Always visible as text, never a badge. |
| **Notification badge (count)** | `pending_approvals > 0` only | The count is `pending_approvals`. Stale, offline, error and AI states change the icon/indicator **inside the panel**, never the badge count. |

Precedence when several apply: core unreachable > not ready > brief error > stale > AI error > AI
setup required > offline. The badge is independent of this ordering.

## 8. Examples

All four live in `packaging/fixtures/health/` and validate against `health.schema.json`.

### Fresh install, core-ready, no AI configured (`200`)

`ready-core-ready-no-ai.json`: all seven checks `ok`, `ready: true`, `ai_ready: false`,
`ai_state: "not_configured"`, `ai_provider: null`, `brief_state: "fresh"` pointing at the placeholder
brief `v1`, `connectivity: "online"`, `pending_approvals: 0`. The menu bar shows **AI setup
required** and the placeholder brief. This is the state the installer's `READY` screen accepts.

### AI ready, two approvals pending (`200`)

`ready-ai-ready.json`: as above but `ai_ready: true`, `ai_state: "ready"`, `ai_provider: "ollama"`,
`ai_last_success_at` set, `latest_brief.version: 3`, `pending_approvals: 2`. The only fixture in
which the badge shows a number.

### Stale brief, offline (`200`)

`ready-stale-brief-offline.json`: core is still `ready: true` (nothing in §12.2 depends on the
internet), `brief_state: "stale"` because `now` is past `stale_after`, `connectivity: "offline"`. The
panel shows the cached `v3` with a stale marker and an offline note; no badge.

### Failing scheduler (`503`)

`not-ready-scheduler-failing.json`: six checks `ok`, `scheduler_selftest` is `fail` with
`age_seconds: 1412`, so `ready: false`. `ai_state: "configured_untested"` (a provider was chosen, no
completion yet), `brief_state: "error"` because the scheduled generation never ran, `connectivity:
"degraded"`, `privacy_mode: "EU_RESTRICTED"`. The panel shows "Not ready" with the scheduler detail
and "Restart services".

A `503` body is the same shape as a `200` body. Clients branch on `ready`, not on the status code
alone; the status code exists for supervisors and the installer's simple gate.

## 9. What this document does not decide

- The **IPC transport, token format and heartbeat message** — S0-15 (#15). This contract only
  requires that the IPC server records the time of the last authenticated heartbeat.
- **Threshold values** beyond the defaults above — `config/health.php` in S1-01, tuned by S1-14
  measurements.
- The **install-state marker** (§16 step 4) — part of the installer state machine (S1-05); it is not
  a health check, and `ready` does not read it.
- What the PWA shows on `/admin` beyond "the same fields".
