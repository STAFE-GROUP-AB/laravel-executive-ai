# IPC server end (S1-08, part 1 of 2)

| | |
|---|---|
| **Status** | Implemented in `app/Security/Ipc/`, `app/Http/Controllers/Ipc/`, `routes/api.php` (S1-08 #36, server half). The Swift client half is delivered in `apps/macos/LaravelExecutiveAI/IPC/` — [ipc-client.md](ipc-client.md) — and replays every fixture here byte for byte against a stub transport |
| **Contract** | [ipc-contract.md](ipc-contract.md) **1.1.0** — frozen; this document records what was built against it, not changes to it. The 1.1.0 minor (§15) added the §6.9 owner-creation operation, #121 |
| **Fixtures** | `packaging/fixtures/ipc/*.json` — every exchange is replayed byte for byte by `tests/Feature/Ipc/IpcFixtureConformanceTest.php`; `packaging/fixtures/ipc-signing.mjs` is ported to `App\Security\Ipc\IpcSigner` |
| **Threat model** | T-17, T-19, T-20, T-30 – T-35 (the nine named IPC tests, [threat-model.md](../security/threat-model.md)) |
| **ADRs** | ADR-003 D3 (how the core obtains `K`), D7 (redaction); ADR-006 D3 (origin), D6.5 (bind is a constant) |

## 1. What was built

```
request ─► TrustLoopbackHosts (global, S1-04)
        ─► LoopbackOnly (health/* only)
        ─► IpcAuthenticate:<scope>        §4.4 steps 1–7, §4.1 header allowlist, §1.1 size, §5.2.5 media type
        ─► IpcThrottle:<operation>        §9, RateLimiter keyed by session_id, Retry-After
        ─► IpcRequireElevation:<op>       §7.3, remote-access-enable only
        ─► IpcRequireNoOwner              §2.1, POST /ipc/v1/owner only — the authorization of the one
                                          operation that runs before an owner exists; 409 owner_exists
                                          from the moment one does, audited as auth.owner_create_refused
        ─► controller                     IpcBody::validate (§4.4 step 10) → IpcPrincipal::authorize (§11) → application service
        ◄─ IpcResponder::finalise         §4.5 headers + signature on every response, framework responses rewritten to the §8 envelope
```

| Piece | Class | Contract |
|---|---|---|
| Token, signing key, request/response/elevation signing | `IpcSigner` (pure functions, port of `ipc-signing.mjs`) | §3.3, §4.2, §4.5, §7.3 |
| Token verification | `IpcTokenVerifier` → `IpcSession {client, session_id, expires_at, signing_key}` | §3.3 |
| Replay cache | `IpcNonceCache` — `ipc:nonce:<session_id>:<nonce>`, 120 s, atomic `Cache::add`; a nonce is remembered only after the signature verified | §4.3 |
| Steps 1–7 in one middleware | `Middleware\IpcAuthenticate` — scope is the route parameter (`:menubar` or `:menubar,supervisor`) | §4.4, §5.1, §5.2, §2 |
| Rate limits | `Middleware\IpcThrottle:<operation>` — per-operation and all-routes budgets from `config/ipc.php` | §9 |
| Elevation | `IpcElevationVerifier` + `Middleware\IpcRequireElevation:<operation>` | §7.3 |
| Principal and gates | `IpcPrincipal` (owner-bound, lazy) → `Gate::forUser($owner)->authorize($ability, [..., ['via' => 'ipc', 'client', 'session_id']])`; abilities defined once in `App\Security\Authorization\OwnerAbilities`. With no owner — a fresh install before `CREATE_OWNER` — `owner()` is null, `actor()` is `job:IpcServer` and `authorize()` fails closed with `policy_denied`, so every gated operation is refused on an ownerless core by construction | §2, §2.1, §11 |
| The pre-owner guard | `Middleware\IpcRequireNoOwner` on `POST /ipc/v1/owner` only: a live `menubar` session **plus** no owner is the authorization; once one exists the route is `409 owner_exists` for every caller, for good | §2.1, §6.9 |
| Envelopes and signing | `IpcResponder`, `IpcException` (`render()` → envelope, `report()` → audited not logged), `IpcErrorCode` (status, fixed message, hint) | §8, §4.5 |
| Body/query schema | `IpcBody` — `additionalProperties: false` everywhere, so `user_id` / `owner` / `approver` in a body is `validation_failed` | §4.4 step 10, §11.4 |
| Logging and audit | `IpcAuditor` — `ipc.request` log line and `audit_events{action: ipc.rejected}` with reason, `(ts, nonce)`, never the token | §10 |
| Operations | `App\Http\Controllers\Ipc\*` (one invokable controller per operation) + `ReservedController` (signed 404 for §6.8 and unknown paths) | §6 |

### Routes

| Route | Scope | Throttle | Gate | Mutates |
|---|---|---|---|---|
| `GET /health/ready` | `menubar,supervisor` | — (health contract) | — | no |
| `GET /ipc/v1/health` | `menubar,supervisor` | `health` | — (scope only) | no |
| `GET /ipc/v1/briefs/latest` | `menubar` | `briefs-latest` | `viewBrief` (→ `BriefPolicy::view`) | no |
| `GET /ipc/v1/approvals?state=pending` | `menubar` | `approvals` | `viewApprovals` | no |
| `POST /ipc/v1/ai/pause` | `menubar` | `ai-pause` | `pauseAi` | `AiPauseSwitch` |
| `POST /ipc/v1/ai/resume` | `menubar` | `ai-resume` | `resumeAi` | `AiPauseSwitch` |
| `POST /ipc/v1/heartbeat` | `menubar` | `heartbeat` | — (scope only) | `Heartbeats::MENUBAR` |
| `POST /ipc/v1/owner` | `menubar` | `owner` | — (**no gate**, §2.1) + `IpcRequireNoOwner` | `CreateOwner` (organization, user, owner membership, install marker) |
| `POST /ipc/v1/oauth/callback` | `menubar` | `oauth-callback` | `completeOAuth` | via `OAuthCallbackHandler` |
| `GET /ipc/v1/remote-access` | `menubar` | `remote-access` | `viewRemoteAccess` | no |
| `POST /ipc/v1/remote-access/enable` | `menubar` | `remote-access-enable` | `enableRemoteAccess` + **elevation** | `TunnelProvider::configure` |
| `POST /ipc/v1/remote-access/disable` | `menubar` | `remote-access-disable` | `disableRemoteAccess` | `TunnelProvider::disable` |
| `ANY /ipc/v1/{anything else}` | `menubar` | — | — | signed `404 / not_found` |

**Port.** `config('ipc.port')` is `LEA_HTTP_PORT` (default 8787), the one port variable of the
Supervisor's runtime env contract ([runtime-layout.md](runtime-layout.md) §3). It drives the §5.2 `Host`
check and the `X-Lxai-Origin` header, so a fallback port (8788–8799) works unchanged; `IpcOriginTest`
proves `Host: 127.0.0.1:8792` is accepted and `:8787` rejected once the config says 8792.
`runtime_version` in the heartbeat response is `basename(LEA_RUNTIME_DIR)` (the directory is
`runtime/<version>`), falling back to the PHP version in a developer checkout.

**Framework throttle.** The stock `api` middleware group contains only `SubstituteBindings` in this app
(`throttleApi()` is never called), so no per-IP framework limit sits in front of the per-session §9
budgets. The `/ipc/v1` group and `/health/ready` nevertheless declare
`withoutMiddleware(ThrottleRequests::class)`, `IpcPolicyArchTest` fails CI if a framework
`ThrottleRequests` ever resolves on an IPC route, and `IpcOperationsTest` sends 70 health requests
from `127.0.0.1` in one minute and expects 70 × 200.

`/health/live` keeps no token and no version header (health contract §6). `tests/Security/IpcPolicyArchTest.php`
pins this table: a new route under `/ipc/` without `IpcAuthenticate`, a throttle and a named ability fails CI,
as does any approval-mutating route or an IPC controller importing a model provider, the mailer, the CRM,
MCP or a process runner.

### Response bytes

Envelope bodies are emitted in RFC 8785 (JCS) canonical form — the same bytes the fixture signatures
cover — so a deterministic exchange reproduces the recorded `X-Lxai-Signature` exactly (the conformance
test asserts this for `briefs-latest`, `briefs-latest-not-modified`, `heartbeat`, `approvals-pending`,
`oauth-callback`, `remote-access-disable` and the three recorded error cases). Consequences the client must
not trip over: object keys arrive sorted, and `Cache-Control` is `no-store, private` (Symfony appends
`private`; check for `no-store`, do not compare the whole value).

`/health/ready` keeps its un-enveloped body for the Supervisor and the installer; it is still signed over
the bytes sent.

## 2. The key-source seam (what S1-07 must bind)

`App\Security\Ipc\IpcKeySource` is the only way the core obtains `K`:

```php
interface IpcKeySource
{
    /** @throws IpcKeyUnavailableException when the broker is down or locked */
    public function key(): IpcKey;
}
```

| Binding | When | Behaviour |
|---|---|---|
| `TestingKeySource` | `APP_ENV=testing` (or `LEA_IPC_KEY_SOURCE=testing`, which still refuses outside testing) | returns `K_test` = 32 zero bytes (§4.6) via `IpcKey::forTesting()` |
| `UnavailableKeySource` | every other environment today | throws; every `/ipc/*` and `/health/ready` request answers **503 / `secrets_unavailable`**, unsigned, with `Retry-After` |
| **S1-07's broker source** | after the Supervisor's secrets broker exists (ADR-003 D3) | rebind `IpcKeySource` in the container to a class that calls `SecretStore::get(SecretRef('se.stafe.executive-ai.ipc.menubar'))`, parses the `{"v":1,"kind":"hmac_key","key":"<base64 32 bytes>"}` envelope and returns `IpcKey::fromBytes(...)` on every call (no caching beyond the request, so `secrets.changed` after "Reset security pairing" takes effect within one request) |

`IpcKey` follows ADR-003 D3.4's SecretValue posture: cannot be serialised, JSON-encoded, cast, cloned or
dumped; bytes are zeroed on destruct; `fromBytes()` refuses the all-zero key unconditionally; `forTesting()`
refuses outside the testing environment. `K` is never read from `.env`, a plist or a file. The binding lives
in `App\Security\Ipc\IpcServiceProvider::register()`; S1-07 replaces that one `match` arm.

## 3. Sprint 1 stand-ins the operations run against

| Seam | Sprint 1 binding | Replaced by |
|---|---|---|
| `App\Support\Install\TunnelProvider` | `NotConfiguredTunnelProvider` (`NotInstalled`, refuses to configure, disable is a no-op) | `TailscaleServeTunnelProvider` (ADR-006, E4-1) |
| `App\Security\Ipc\OAuth\OAuthCallbackHandler` | `NoPendingOAuthFlows` (every state is `oauth_state_invalid`) | the connector that owns the authorisation flow (S0-17 EmailConnector, Sprint 2/3) |
| `App\Support\Health\PendingApprovals::page()` | `NoPendingApprovals` (empty list, `total_pending` 0) | the approvals module with `action_proposals` (E2-4) |
| `App\Support\Cancellation\AiPauseSwitch` | cache-backed global pause; `token()` is the `CancellationToken` runs observe | stays; `in_flight_runs_cancelled` becomes real once `ai_runs` exist (E2-5) |

Known gaps against the contract, all noted rather than faked:

- §6.4 `in_flight_runs_cancelled` is always `0` (no run ledger yet); `resumeAi` does not consult the
  automation-broker lock because no broker exists.
- §6.7 `tailscale.variant` is `null` (the S0-16 `TunnelStatus` DTO has no variant field); `remote.origin`
  is not persisted (no settings table yet); `verified_at` is the time `configure()` returned.
- §4.3's 10 000-entry LRU bound is not implemented as such: the rate limits (≤ 120 requests/min) bound the
  live population to ≤ 240 per session and the cache store evicts by TTL.
- §1.1's 1 MiB response ceiling for `briefs/latest` is not enforced server-side.

## 4. Test matrix (contract §12)

| Test | File | Proves |
|---|---|---|
| `IpcFixtureConformanceTest` | `tests/Feature/Ipc/` | every fixture request is accepted/rejected exactly as recorded; the recorded response signature verifies under the PHP port; the server's own response is signed over the bytes it sent; deterministic exchanges are byte-exact; every exchange validates against `ipc.schema.json`; every accepted fixture is `nonce_replayed` when replayed |
| `IpcTokenTest` | `tests/Feature/Ipc/` | missing, non-Bearer, six malformed shapes, wrong MAC, client swapped, expired, expiring-now → unsigned `401 session_invalid`; `supervisor` on five menu-bar routes → signed `403 forbidden_scope`; `/health/ready` unauthenticated → 401; rotation of `K` kills the token; no key → 503; rejection audit without the token; request-id echo/mint |
| `IpcReplayTest` | `tests/Feature/Ipc/` | same nonce twice (GET and mutating POST); −31/+31 → `timestamp_skew`, −29/+29/−30 accepted; malformed timestamp and nonce; per-session scoping; 120 s expiry; a failed signature does not consume the nonce; `(ts, nonce)` in the audit |
| `IpcOriginTest` | `tests/Feature/Ipc/` | `Host: localhost`, `[::1]`, wrong port, no port; `X-Forwarded-*`, `Forwarded`, `Tailscale-User-*`; `Origin`, `Referer`, `Cookie`, `Sec-Fetch-*`; peer `::1` and three off-host addresses; origin runs before the token; same rules on `/health/ready`; non-JSON body → 415 |
| `IpcBindTest` | `tests/Feature/Ipc/` | the S1-06 Caddyfile template binds `127.0.0.1` only (no `0.0.0.0`, `[::]`, `tls`); `IpcAuthenticate::LOOPBACK_PEER` is a constant; four non-loopback peers refused on four routes; `LoopbackOnly` stays on `/health/*` |
| `IpcVersionTest` | `tests/Feature/Ipc/` | missing, `0`, `2`, `3`, `1.0.0`, `01`, text → unsigned `426` with `supported_versions: [1]` and the repair hint; version checked before the token; `/health/live` exempt |
| `IpcPolicyArchTest` | `tests/Security/` | exactly the contract routes; `IpcAuthenticate:<scope>` on each; a throttle with a configured budget on each; every domain-touching controller calls `->authorize(OwnerAbilities::…)` and the set without an owner-bound gate is exactly `{health, heartbeat, reserved, owner}`; every ability is a defined gate; elevation on exactly `remote-access/enable`; `IpcRequireNoOwner` on exactly `POST /ipc/v1/owner`, which also names no ability; no mutating approval route; no forbidden imports; the mutating set is exactly seven POSTs |
| `OwnerCreateTest` | `tests/Feature/Ipc/` | §6.9 end to end: the owner, its organization and the membership from one signed request; `{created: true}` and nothing else in the body; the audit record with `via: ipc` and the `job:IpcCreateOwner` actor; the workspace named after the owner when `organization` is omitted; `409 owner_exists` on every repeat, with `auth.owner_create_refused` and nothing created; two asks → one owner; the request waits on `lea:create-owner`; `supervisor`, absent and wrong-MAC tokens; ten invalid or identity-asserting bodies; the 5/min budget |
| `IpcRedactionTest` | `tests/Security/` | corpus and live `lxai1.`/`lxai1e.` values never reach the log from an exception; the `oauth/callback` body reaches neither log, audit nor response even when the handler embeds it in an exception; the request log line carries no credential; `ipc.rejected` carries no credential |
| `HeartbeatCheckTest` | `tests/Feature/Ipc/` | `menubar_integration` fail → ok only on an authenticated `menubar` heartbeat; `supervisor` → `forbidden_scope`; five unauthenticated shapes never write the cache; identity-asserting bodies → 422; quitting heartbeat; 6/min budget with `Retry-After` |
| `IpcSignerTest` | `tests/Unit/Security/Ipc/` | the fixture token MACs, every recorded request and response signature, and the recorded elevation are reproduced from `K_test`; `IpcKey` refuses the zero key and serialisation |
| `IpcOperationsTest` | `tests/Feature/Ipc/` | the §6 operations end to end: health 200/503 envelope, brief 404/ETag/304/draft-excluded/cross-organisation denial, approvals query validation and the absence of any mutating approval route, idempotent pause/resume with audit and the cancellation token, OAuth seam, remote access against the stub and a scripted provider (elevation cases, Ready/Drifted/Funnel rules), 400/413/415/404 hygiene, the all-routes budget, framework-exception rewriting, `/health/ready` signing |

`HealthReadyTest` (S1-01) now sends signed `supervisor` requests through `Tests\Support\Ipc\IpcClient`.

## 5. What the Swift half must implement (S1-08 part 2)

The client is the mirror image of `IpcSigner` and `IpcAuthenticate`; `Tests\Support\Ipc\IpcClient` is a
PHP sketch of it.

**Every request** to `http://127.0.0.1:<port>` (literal address, no proxy, one keep-alive connection):

| Header | Value |
|---|---|
| `Host` | `127.0.0.1:<port>` |
| `Authorization` | `Bearer lxai1.<client>.<session_id>.<expires_at>.<mac>` from the pairing grant |
| `Accept` | `application/json` |
| `X-Lxai-Ipc-Version` | `1` |
| `X-Lxai-Timestamp` | Unix seconds, decimal |
| `X-Lxai-Nonce` | 16 random bytes, base64url, no padding (22 chars), fresh per request |
| `X-Lxai-Signature` | `v1=` + hex HMAC-SHA256(signing_key, `"LXAI-IPC-REQ/1\n" METHOD "\n" path-and-query "\n" timestamp "\n" nonce "\n" hex(SHA-256(body bytes))`) — SHA-256 of the empty string when there is no body |
| `X-Lxai-Request-Id` | ULID (optional; echoed) |
| `Content-Type` | `application/json` — only when a body is sent; send the JCS canonical bytes and sign exactly those |
| `If-None-Match` | only on `briefs/latest` |
| `X-Lxai-Elevation` | only on `remote-access/enable`: `lxai1e.remote-access-enable.<same nonce>.<expires_at>.<mac>` from the Supervisor |

Never send `Origin`, `Referer`, `Cookie`, `Sec-Fetch-*`, `X-Forwarded-*`, any other `X-Lxai-*`, or
`X-HTTP-Method-Override`; never use `localhost`.

**Every response** carries `X-Lxai-Ipc-Version`, `X-Lxai-Request-Id`, `X-Lxai-Origin: 127.0.0.1:<port>`
and `Cache-Control` containing `no-store`. Verify **before parsing the body**:

```
expected = "v1=" + hex(HMAC-SHA256(signing_key, "LXAI-IPC-RES/1\n" status "\n" request-nonce "\n" hex(SHA-256(body bytes))))
```

against `X-Lxai-Signature`, constant-time. A missing or wrong signature is *Core unreachable*, never data —
except `401 session_invalid` (re-pair), `403 origin_rejected` (diagnostics), `426 version_unsupported`
(*Update required*) and `503 secrets_unavailable` (*Core unreachable*, broker hint), which arrive unsigned
because the server had no key. `304` has an empty body and is signed over the empty-string hash.

Bodies are the §8 envelopes with keys in sorted order:
`{"data":{…},"ok":true,"request_id":"…","server_time":"…"}` and
`{"error":{"code","details","hint","message","retry_after_seconds","supported_versions"},"ok":false,"request_id":"…","server_time":"…"}`.
Back off `retry_after_seconds` / `Retry-After` on `429` and `503`.

**Erratum to ipc-contract §6.2 (S1-09):** the GUI brief cache lives at
`~/Library/Caches/<bundle id>/menubar/latest-brief.json` (`BriefCache`, [menubar.md](menubar.md)), not at
the `Application Support/…/brief-cache.json` path the contract names. Part 2 must call `BriefCache.clear()`
on "Reset security pairing" (and on uninstall) so a stale brief never survives a key rotation.

`IPCFixtureTests` (Swift) must sign every `packaging/fixtures/ipc/*.json` request with `K_test` and get the
recorded headers byte for byte, and verify every recorded response signature; `IpcResponseSigningTest`
(Swift) must show a tampered body, a tampered status and a missing signature each end in *Core unreachable*
with the body never parsed.
