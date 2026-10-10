# IPC client end (S1-08, part 2 of 2)

| | |
|---|---|
| **Status** | Implemented in `apps/macos/LaravelExecutiveAI/IPC/` (S1-08 #36, client half; the issue text says `apps/macos/Sources/IPC/`, the real layout is the app target's `IPC/` folder next to `MenuBar/` and `Services/`). Wired into the panel (`PanelModel.live`), the installer's `HEALTH_CHECK` (`InstallDependencies.live`) and "Reset security pairing" (`SupervisorServiceControl`). The pairing XPC call and the GUI's Keychain item run for real only on the signed S1-14 lab build; every test runs against a stub `URLProtocol`, an in-memory store and a scripted Supervisor |
| **Contract** | [ipc-contract.md](ipc-contract.md) 1.0.0 — frozen; this document records what was built against it |
| **Server** | [ipc-server.md](ipc-server.md) — the client is the mirror image of `IpcAuthenticate` + `IpcResponder`; §5 there lists what the client must send and verify, this document is the "as built" |
| **Fixtures** | `packaging/fixtures/ipc/*.json` — every exchange is reproduced byte for byte by `IPCFixtureTests` (request headers, canonical body, signature) and its recorded answer is handled exactly as the §8.2 table says |
| **Threat model** | T-17, T-19, T-20, T-30 – T-35 ([threat-model.md](../security/threat-model.md)); the client-side tests are listed in §11 |
| **ADRs** | ADR-003 D5 (the GUI's default private Keychain group), D7 (redaction); ADR-006 D3 (origin) |

## 1. What was built

| Piece | File | Contract |
|---|---|---|
| Session: the `menubar` pairing grant, its Keychain item, renewal and replacement | `IpcSession.swift` — `IpcSession` (actor), `SessionStore`, `KeychainSessionStore`, `InMemorySessionStore` | §3.1 row 2, §3.2 step 4, §3.4 |
| The client: `CoreClient` over `URLSession` to `http://127.0.0.1:<port>` | `LoopbackIpcClient.swift` — `LoopbackIpcClient`, `IpcOperation`, `IpcResponse`, `IpcClientError`, `ErrorEnvelope`, the log sink | §1.1, §4.1, §4.2, §4.5, §5.1, §6.1–§6.5, §8, §9, §10 |
| The installer's health probe | `IpcHealthProbe.swift` — `IpcHealthProbe: HealthProbe` | §2 (`/health/ready` scope), health contract §1/§6 |
| Composition | `IpcClientFactory.swift` — one `IpcSession` per process, the panel's `CoreClient`, the probe, the heartbeat identity | §2 "exactly one live session per client" |
| Token, signing key, request/response signature, elevation arithmetic | `IpcSigning.swift` (S1-07; reused, not duplicated) | §3.3, §4.2, §4.5, §7.3 |

Nothing in `IPC/` is compiled into the Supervisor tool except `IpcSigning.swift`; the client is GUI-only.

## 2. Session lifecycle

```
first use ──► store.load()  ──(none / expiring ≤ 24 h)──► control.pair(client: .menubar) ──► store.save()
                 │                                                                              │
                 └──(valid)──► sign requests with grant ◄───────────────────────────────────────┘
                                      │
          401 session_invalid / signature_invalid ──► store.load() has a *different* session? use it
                                      │                                   else pair() once, retry once
          "Reset security pairing" ──► control.resetPairing() ──► session.replace(with:) + BriefCache.clear()
          uninstall ───────────────► Uninstaller step 5 clears the item with the brief cache
```

- **The grant** is `PairingGrant` exactly as `pair(client: .menubar)` returns it (§3.2 step 3): token,
  signing key, `session_id`, `expires_at`, `port`. It is held in the `IpcSession` actor's memory and
  in the store; it is never in a plist, `UserDefaults`, a file or a log line.
- **The Keychain item** (`KeychainSessionStore`): generic password, service
  `<bundle prefix>.ipc.menubar.session`, account `default`, `kSecUseDataProtectionKeychain`,
  `kSecAttrSynchronizable = false`, `kSecAttrAccessibleAfterFirstUnlock`, label
  "Laravel Executive AI — IPC session", **no `kSecAttrAccessGroup`** — the GUI holds none (ADR-003 D5),
  so only the signed GUI can read it. The payload is the grant's wire JSON (`PairingGrant.encoder()`).
  The query dictionaries are built by pure functions (`baseQuery`, `readQuery`, `addAttributes`,
  `updateAttributes`) that `IpcSessionStoreTest` pins; the `SecItem*` calls themselves run only in the
  app, never in a test.
- **Renewal** (§3.2 step 4): a grant whose `expires_at` is within 24 h is replaced before the next
  request. Silent; no dialog.
- **Rotation** (§3.4, §8.2): `401 session_invalid` or `401 signature_invalid` means `K` changed. The
  session first reloads the store — "Reset security pairing" writes the fresh grant there, and a second
  GUI process would see it — and only when the store holds the same or no session asks the Supervisor
  once. The request is retried once with the replacement; a second rejection surfaces
  (`IpcClientError.sessionInvalid` / `.signatureInvalid` → *Core unreachable*). Each later refresh starts
  that cycle again (one pairing, two requests), so a core whose `K` disagrees with the Supervisor's is
  visible every 30 s and never loops.
- **Reset** (§7.1): `SupervisorServiceControl.resetPairing()` → `control.resetPairing()` →
  `session.replace(with: grant)` (memory + store) → `BriefCache.clear()` (erratum in ipc-server.md: the
  cache lives under `~/Library/Caches/<bundle id>/menubar/`, and no brief fetched under the old key
  survives the rotation). The Settings control that calls this is still open (menubar.md §9).
- **Uninstall** (§3.4 "the GUI deletes its private item"): `UninstallDependencies.sessionStore` is cleared
  in step 5 (`briefCacheCleared`) of the S1-13 order; a store that cannot be reached is not a reason to
  stop, the item is useless without `K`.
- **Unsigned builds**: the data-protection keychain refuses a process without an application identifier
  (`errSecMissingEntitlement`). The session then keeps the grant in memory for the process lifetime
  (`storeUnavailable == true`) and pairs once per launch instead of once per 30 days. Nothing else
  changes; the lab run (S1-14) is where the item is seen in Keychain Access.
- **One session per process**: `IpcClientFactory.liveSession` is shared by the panel's client, the
  installer's probe and the reset path, so the Supervisor's "one live session per client" (§2) holds and
  `elevate` (which checks the live session id) will work for whichever surface asks first.

## 3. Request pipeline (`LoopbackIpcClient.perform`)

Every operation is an `IpcOperation` — method, request-target, optional body as a `JSONValue`, optional
`If-None-Match`, optional elevation, the §9 timeout — and goes out as:

| Header | Value |
|---|---|
| (`Host`) | derived by `URLSession` from the literal URL `http://127.0.0.1:<port><path>`: always `127.0.0.1:<port>` |
| `Accept` | `application/json` |
| `Authorization` | `Bearer <grant.token>` |
| `X-Lxai-Ipc-Version` | `1` (`LoopbackIpcClient.wireMajor`; the configuration knob exists for the `error-version-unsupported` conformance replay only) |
| `X-Lxai-Timestamp` | `floor(now + clockOffset)` in Unix seconds; `clockOffset` is 0 until a `timestamp_skew` answer resyncs it from `server_time` |
| `X-Lxai-Nonce` | 16 random bytes, base64url, 22 characters, fresh per request (`IpcSigning.nonce`) |
| `X-Lxai-Signature` | `IpcSigning.signRequest` over method, request-target, timestamp, nonce and `SHA-256(body bytes)` |
| `X-Lxai-Request-Id` | a ULID per request |
| `Content-Type` | `application/json`, only when a body is sent |
| `If-None-Match` | only `briefs/latest` |
| `X-Lxai-Elevation` | only when the caller attached one (§7.3); such a request is never retried, because the elevation is bound to its nonce |

The body bytes are the RFC 8785 canonical form of the `JSONValue` (`JCS.canonicalize`, the same port the
parity lane uses), and the signature covers exactly those bytes. No other header is set; the forbidden
set (`Origin`, `Referer`, `Cookie`, `Sec-Fetch-*`, `X-Forwarded-*`, `Forwarded`, `Tailscale-User-*`,
`X-HTTP-Method-Override`, any other `X-Lxai-*`) is asserted absent on every recorded request by
`IpcOriginClientTest` and `IPCFixtureTests`.

Transport (§9): `URLSessionConfiguration.ephemeral`, `connectionProxyDictionary = [:]`,
`waitsForConnectivity = false`, cookies off and never accepted, no `URLCache`,
`.reloadIgnoringLocalCacheData`, one connection per host, cellular/expensive/constrained paths off, 35 s
ceiling with the per-operation timeouts of the §9 table (`health` 6 s, `briefs/latest` / `approvals` /
`ai/*` 5 s, `heartbeat` 3 s, `/health/ready` 6 s). A redirect is refused by the task delegate and the 3xx
itself is the answer, which the client then rejects (`IpcClientError.redirected`). A grant whose `port`
differs from the client's is refused before anything is sent.

The six `CoreClient` operations are built on this: `health` → `GET /ipc/v1/health`; `latestBrief` →
`GET /ipc/v1/briefs/latest` with `If-None-Match`; `pendingApprovals` →
`GET /ipc/v1/approvals?state=pending&limit=20`; `pauseAI` → `POST /ipc/v1/ai/pause` with
`{"reason":"user_menubar"}`; `resumeAI` → `POST /ipc/v1/ai/resume` with `{}`; `heartbeat(panelOpen:)` →
`POST /ipc/v1/heartbeat` with `{client, client_version, contract_version, panel_open, since}` where
`client_version` is `"<CFBundleShortVersionString> (<CFBundleVersion>)"` and `since` is the process's
start time in RFC 3339 (UTC). `heartbeat(panelOpen:quitting:)` adds `"quitting": true` for §6.8; the
panel's "Quit UI" does not call it yet (menubar.md §5 pins "Quit UI sends nothing to the core"; the
contract's final heartbeat is an open S1-09/S1-08 reconciliation, noted in §12).

## 4. Response pipeline

1. **Transport failure or a non-HTTP answer** → `IpcClientError.unreachable`.
2. **Signature first, before the body is looked at** (§4.5): with `X-Lxai-Signature` present,
   `IpcSigning.responseSignatureIsValid` over `(status, request nonce, body bytes)` in constant time; a
   mismatch is `unreachable("the response signature did not verify")` and the body is never parsed.
   With the header absent, the answer is accepted **only** when its status is 401/403/426/503 and the
   body is an §8.2 envelope whose code is one of `session_invalid`, `origin_rejected`,
   `version_unsupported`, `secrets_unavailable` — the four the server sends before it has derived a
   key. Anything else unsigned is `unreachable("the response carried no signature")`, including a
   perfectly shaped success envelope.
3. **Version** (§5.1): a response `X-Lxai-Ipc-Version` above `1` is `versionUnsupported(serverMajor:)`
   even on a signed `200` — the GUI is older than the core and renders nothing partial.
4. **Redirects**: any 3xx but 304 → `redirected(status)`.
5. **Envelope**: `304` is a success with an empty body; otherwise the body is parsed and an `ok: false`
   envelope is mapped by code (§5 below); an `ok: true` envelope — including the `503` health answer,
   whose body is still the success envelope — is a success. `/health/ready` is un-enveloped, so the probe
   decodes the body as a health document directly.
6. **Single retries** (§8.2): `session_invalid` / `signature_invalid` → re-pair (§2) and retry once;
   `timestamp_skew` → set `clockOffset = server_time − now` and retry once; `nonce_replayed` → retry once
   with a fresh nonce. A second failure surfaces as the typed error. Nothing else is retried; `429` and
   `503` carry `retryAfterSeconds` for the caller.

Only the §8.1 `data` leaves the client: re-encoded to bytes and decoded into `HealthDocument`,
`PendingApprovals`, `AiActionsState`, `HeartbeatAck`, or handed to `PanelModel` as the brief document
with its `ETag`. `PanelModel` never sees a header.

## 5. Error mapping

| HTTP / code | `IpcClientError` | `CoreClientError` (what the panel sees) | Panel |
|---|---|---|---|
| no answer, non-HTTP, missing/invalid signature, unsigned where a signature is due, non-envelope body | `unreachable(reason)` | `unreachable` | *Core unreachable*, cached brief kept |
| Supervisor will not pair | `supervisorUnreachable(reason)` | `unreachable` | *Core unreachable* |
| 401 `session_invalid` ×2 | `sessionInvalid` | `unreachable` | *Core unreachable* |
| 401 `signature_invalid` ×2 | `signatureInvalid` | `unreachable` | *Core unreachable* |
| 401 `timestamp_skew` ×2 | `timestampSkew` | `unreachable` | *Core unreachable* |
| 401 `nonce_replayed` ×2 | `nonceReplayed` | `unreachable` | *Core unreachable* |
| 426 `version_unsupported`, or response major > 1 | `versionUnsupported(serverMajor, supportedVersions, hint)` — `serverMajor` is the highest `supported_versions` entry (the major the GUI would have to speak), the hint is the server's repair sentence | `versionUnsupported(serverMajor:)` | **Update required** (`PanelModel.ipcVersionRefused`); `badge 0`; no brief, approvals or heartbeat until a proper answer clears it |
| 403 `origin_rejected` | `originRejected` | `forbidden` | never retried; diagnostics |
| 403 `forbidden_scope` | `forbiddenScope` | `forbidden` | bug; logged |
| 403 `elevation_required` / `elevation_invalid` | `elevationRequired` | `forbidden` | the elevation flow (§12) |
| 403 `policy_denied` | `policyDenied(message)` | `forbidden` | pause toggle shows "failed" |
| 403 `reauth_required` | `reauthRequired(openUrl)` | `forbidden` | open the PWA |
| 404 `not_found` | `notFound` | `notFound` | no brief yet |
| 409 `state_conflict` | `stateConflict` | `invalidResponse` | remote access (E4-1) |
| 400 / 413 / 415 | `badRequest(code)` | `invalidResponse` | bug; logged |
| 422 `validation_failed` / `oauth_state_invalid` | `validationFailed(code, details)` | `invalidResponse` | — |
| 429 `rate_limited` | `rateLimited(retryAfterSeconds)` | `rateLimited` | next refresh |
| 503 `secrets_unavailable` (unsigned) | `secretsUnavailable(hint, retryAfterSeconds)` | `unreachable` | *Core unreachable* with the broker hint in diagnostics |
| 500 `internal` | `internalError(requestId)` | `invalidResponse` | — |
| 3xx (not 304) | `redirected(status)` | `unreachable` | *Core unreachable* |
| unknown code | `unexpected(status, code)` | `invalidResponse` | — |

`CoreClientError` is unchanged from S1-09 (menubar.md §4); the only panel change is the `426` branch in
`PanelModel.refresh()`, which makes the "Update required" row of the state table live for the IPC
version as it already was for the brief schema.

## 6. The installer's health probe

`IpcHealthProbe` is the live `HealthProbe` in `InstallDependencies.live` (replacing
`UnavailableHealthProbe`, which stays for builds without a client). `readiness()` reads the port from
the install checkpoint's `preflight.port` **when it runs** — preflight records it during the very install
the probe belongs to — builds the client on the shared session and sends `GET /health/ready`, signed with
the `menubar` grant (the route's scope is `menubar,supervisor`, ipc-server.md). The un-enveloped body of
`200` and `503` alike is decoded once by `HealthDocument` and reduced to `HealthReadiness{ready,
failingChecks (names only), aiState}`; a transport failure, a bad signature or a persistently invalid
session is `HealthProbeError.unreachable(<sanitised reason>)`, which the state machine counts as a
failed attempt; a signed body that is not a health document is `invalidResponse`. `unavailable` — the
"no probe is wired" failure — is never thrown by this probe. `live()` is the plain, unsigned
`GET /health/live` of preflight 6.8. `IpcHealthProbeTests.testTheInstallerReachesReadyThroughTheLiveProbe`
drives `InstallStateMachine` from Welcome to `READY` through this probe against the stubbed core, so
`HEALTH_CHECK → READY` is now reachable on a real install (installer.md §6, S1-08 row).

## 7. `healthy|failed` versus `running` (services.md §11)

ipc-contract §7.2 wrote `ServiceStatus.state ∈ running|starting|stopped|degraded`; the Supervisor reports
`healthy` for the running-and-ok case and adds `failed` for the post-bound state. Resolved on the client
side rather than by reopening the frozen contract: `ServiceState.ipcContractName` maps `healthy →
"running"` and carries `starting|stopped|degraded` through unchanged; `failed` has no contract name and is
carried as itself — a client-side extension, pinned by `IpcOriginClientTest.testServiceStateNamesMapToTheContractsVocabulary`.
The contract text is untouched; a future minor may adopt the two names.

## 8. Logging (§10, ADR-003 D7)

The client logs one `IpcLogEvent` per exchange through an `IpcEventSink`: operation name, HTTP status,
an outcome keyword (`signed`, `unsigned_error`, `signature_invalid`, `unsigned`, `transport_failure`,
`not_http`, `version_unparsable`, `version_above_client`), the request id and the duration. The event is
built from names and numbers only, so no header value can reach it by construction. The live sink is
`os.Logger` (subsystem `<bundle id>`, category `ipc`) with the request id interpolated as `.private`;
tests use a capturing sink. `IpcClientError.userFacingReason` and the `CustomStringConvertible` form of
`IpcResponse` (status, signed, byte count) carry no token, key, nonce or signature.
`IpcRedactionClientTests` runs a success, every error class, a tampered answer, a redirect, an elevated
request and an OAuth callback with a corpus value as `code`, and asserts that neither the log nor any
error description contains the token, the signing key, the elevation, `lxai1.`/`lxai1e.`, `Bearer `,
`v1=` or any entry of `tests/Security/fixtures/secrets-corpus.json`.

## 9. Wiring

| Where | What |
|---|---|
| `PanelModel.live()` | `IpcClientFactory.makeCoreClient(checkpointStore:session:)`: `LoopbackIpcClient` when `install/checkpoint.json` names `preflight.port`, otherwise `UnreachableCoreClient("The core is not installed yet.")`, so the panel keeps its cache-only behaviour (§14). Pairing happens lazily on the first request; before the Supervisor answers, every call is `unreachable` and the panel shows *Core unreachable* with the cached brief. Under XCTest the client stays `UnreachableCoreClient` and nothing pairs. `LXAI_MENUBAR_FIXTURE` (DEBUG) still swaps in `FixtureCoreClient` |
| `PanelPorts.live` | `SupervisorServiceControl(control:cache:uninstaller:session: IpcClientFactory.liveSession)` so `resetPairing()` replaces the grant |
| `InstallDependencies.live` | `healthProbe: IpcClientFactory.makeHealthProbe(bundle:checkpointStore:session:)` on the same session |
| `UninstallDependencies.live` | `sessionStore: KeychainSessionStore.default(bundleIdentifier:)`, cleared in step 5 |
| `IpcClientFactory.liveSession` | `IpcSession(store: KeychainSessionStore.default, control: SupervisorControlClientFactory.live())` — one per process |

## 10. Not done here (honest list)

- **Elevation flow** (§7.3): `IpcOperation.elevation` and `send(_:nonce:)` carry an elevation bound to
  one nonce (the `remote-access-enable` fixture replays byte for byte), but no surface calls
  `control.elevate(sessionId:operation:nonce:)` yet — the Remote Access screens are E4-1.
- **`oauth/callback`, `remote-access/*`**: reachable through the generic `send(IpcOperation)` and proven
  by the fixture replay; no typed helpers until their callers exist.
- **Final heartbeat on Quit UI** (§6.8 `quitting: true`): `heartbeat(panelOpen:quitting:)` exists;
  `PanelModel.quitUI()` still sends nothing (S1-09's test pins that). Reconcile in S1-09's follow-up.
- **"Reset security pairing" in Settings**: the port and the session replacement are wired; the control
  itself is open (menubar.md §9).
- **Real Keychain and real XPC pairing**: S1-14 lab build. In CI the store is in memory and the
  Supervisor is scripted.
- **Backoff on `429`/`503`**: the panel's refresh loop is fixed at 30 s, which already exceeds every
  `Retry-After` the server sends in Sprint 1; the value is surfaced in the error, not acted on.

## 11. Test matrix (contract §12, Swift side)

| Test | File | Proves |
|---|---|---|
| `IPCFixtureTests` | `LaravelExecutiveAITests/IPC/` | every `packaging/fixtures/ipc/*.json` request is reproduced byte for byte (method, target, canonical body, every recorded header, identical signature — except `error-signature-invalid`, whose bad signature the client must not reproduce) through a stub `URLProtocol`; every recorded answer is handled as the §8.2 table says (success envelopes, `304`, `503` health, `forbidden_scope`, `rate_limited` with `Retry-After`, `signature_invalid` → one re-pair then surface, `version_unsupported` with the hint); the heartbeat/pause/resume builders reproduce the recorded bodies; every signed recording tampered in status, body, nonce or with the signature removed is *unreachable* |
| `IpcResponseSigningTest` | | tampered body (with a valid-looking envelope), tampered status, another nonce, another session's key, missing signature → *unreachable*, body never data, on every `CoreClient` operation; the four allowed unsigned answers (`session_invalid` → one re-pair, `426` → hint + fail closed, `origin_rejected`, `secrets_unavailable` with hint and `Retry-After`); any other unsigned answer, and an unsigned success body on an allowed status, → *unreachable*; response major 2 → `versionUnsupported` even when signed; signed `304` over the empty-body hash; `404` → `notFound`; `policy_denied` → `forbidden`; connection failure; non-envelope body |
| `IpcTokenClientTest` | | expiring (≤ 24 h) and expired grants re-pair before the first request; a missing grant pairs once and is reused; a valid grant never pairs; `session_invalid` re-pairs once and retries with the new token; twice → surfaces after exactly one pairing and two requests, and the next refresh starts over (no loop); `signature_invalid` re-pairs once; a grant another writer rotated into the store is used without asking the Supervisor; `resetPairing()` through `SupervisorServiceControl` replaces the session grant and clears the cache; `timestamp_skew` resyncs from `server_time` once and keeps the offset; persistent skew surfaces; `nonce_replayed` retries with a fresh 22-char nonce; fresh nonce and ULID per request; no Supervisor → nothing on the wire, `PanelModel` shows *Core unreachable*; a refused pairing surfaces its sanitised reason; the factory returns `UnreachableCoreClient` until the checkpoint names a port |
| `IpcOriginClientTest` | | every request targets `http://127.0.0.1:<port>` (scheme, host, port; never `localhost`); the session configuration (no proxy, no cookies, no cache, no connectivity waiting, one connection); per-operation timeouts; no browser or proxy header and only the allowed `X-Lxai-*` set; a 302 to `localhost` and a signed 307 are errors and are not followed; a grant for another port is refused before sending; `/health/live` is plain and unsigned; the `IPC/` sources never name `localhost`, `[::1]`, `print(` or `NSLog(`; the `ServiceState` → contract name mapping |
| `IpcSessionStoreTest` | | in-memory round trip and failure injection; the Keychain item name; the base, read, add and update dictionaries (generic password, data-protection keychain, non-synchronisable, `AfterFirstUnlock`, PII-free label, **no access group**) — the real Keychain is never touched; the stored bytes are the grant's wire JSON; pairing persists once; a store that refuses writes keeps the grant in memory; a stored `supervisor` grant is ignored; concurrent first uses pair once; `forget()`; the uninstaller clears the item with the brief cache |
| `IpcHealthProbeTests` | | ready / not-ready (`503`, failing names only) / unreachable (connection, tampered, unsigned, persistent `session_invalid`) / invalid body / no recorded port → `HealthReadiness` and `HealthProbeError` per installer.md; `/health/ready` is signed with the menubar grant; the port is read when the probe runs; `InstallStateMachine` reaches `READY` through the live probe |
| `IpcRedactionClientTests` | | §8 above; log events are names and numbers only; every `Logger` line marks the request id `.private` and names no credential field |
| `IpcPanelIntegrationTests` | | `PanelModel` + `LoopbackIpcClient` against the recorded exchanges: fresh state, badge 2, brief and approvals rendered (`MenuBarPanelView` and `MenuBarLabel` through `ImageRenderer`), `panel_open` in the heartbeat, cache stored; second refresh revalidates with `If-None-Match` and keeps the cache on `304`; pause/resume bodies; `426` → "Update required" with nothing else called, cleared by the next proper answer; a tampered core → *Core unreachable* with the cached brief kept |

Run: `xcodebuild -project apps/macos/LaravelExecutiveAI.xcodeproj -scheme LaravelExecutiveAI -destination 'platform=macOS' CODE_SIGNING_ALLOWED=NO test -only-testing:LaravelExecutiveAITests/IPCFixtureTests` (and the other seven classes), or the whole suite as CI does (apps/macos/README.md).

## 12. Reopen conditions

- The contract adopts `healthy|failed` in §7.2 → drop `ServiceState.ipcContractName`.
- S1-09 decides "Quit UI" sends the §6.8 final heartbeat → `PanelModel.quitUI()` calls
  `heartbeat(panelOpen: false, quitting: true)` best-effort and the "sends nothing" test moves.
- A second GUI client (§14 of the contract) → the session store gains an `epoch`/revocation field with
  the Keychain envelope.
- The S1-14 lab run shows `errSecMissingEntitlement` on a Developer ID build → the GUI needs an explicit
  `keychain-access-groups` entry for its own default group, and this document's §2 is wrong about the
  implicit application identifier.
