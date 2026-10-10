/health/live and /health/ready including scheduler/worker self-test and a separate ai_ready (§12.2).

Implements docs/architecture/health-contract.md (S0-14). `HealthService` runs the seven checks in
`Checks/` in contract order with the skip, timeout and budget rules, caches the result for
`config('health.cache_ttl_seconds')` and never calls a model, provider or process.

Heartbeats the checks read (`Heartbeats`, cache keys `health:heartbeat:<name>`):

| Name | Written by | Check |
|---|---|---|
| `menubar` | `POST /ipc/v1/heartbeat` (S1-08) — `Heartbeats::record(Heartbeats::MENUBAR, ['client_version' => …])`, only for an authenticated `menubar`-scoped request | `menubar_integration` (120 s) |
| `queue_worker` | `QueueWorkerHeartbeat` on the worker's `Looping` event, throttled to 30 s | `queue_worker` (90 s) |
| `scheduler` | `health:selftest`, scheduled every minute in routes/console.php | `scheduler_selftest` (180 s) |
| `login_selftest` | `LoginSelfTest`, run by `health:selftest` | `auth_login` |
| `queue_selftest` | `Jobs/QueueSelftestJob`, scheduled every minute, recorded when processed | diagnostics only |
| `brief_generation_error` | the brief generator on a failed scheduled run | `brief_state: error` |
| `connectivity` | the sync layer (Sprint 3) via `Connectivity::record()` | `connectivity` |

`AiReadiness` and `PendingApprovals` are interfaces bound in `AppServiceProvider`; the AI and
approvals modules rebind them when `ai_runs` and `action_proposals` exist. Until then
`pending_approvals` is 0 and `ai_ready` is true only when `RecordAiCompletionSuccess` has recorded a
succeeded completion on the currently selected provider (`AiCompletionSuccessMarker`, cache key
`health:ai:last_success`, no expiry) — nothing in `app/` calls that writer before Sprint 2's run
loop, so it is false in every Sprint 1 install. See `docs/architecture/ai-state.md`.

Supervision (S1-07): `schedule:work` and `queue:work` must both run under launchd; `ready` drops
within minutes when either stops. See docs/architecture/data-model.md §4.
