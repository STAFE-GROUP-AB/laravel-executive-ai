# Service supervision: the Supervisor LaunchAgent (S1-07)

| | |
|---|---|
| **Status** | Implemented (S1-07, #35): `apps/macos/LaravelExecutiveAISupervisor/` (the headless tool, shared into the app module for tests), `apps/macos/LaravelExecutiveAI/Services/` (the GUI side: booter, XPC client, panel service control, uninstall order), `apps/macos/LaravelExecutiveAI/IPC/IpcSigning.swift` (token and signature arithmetic, shared with S1-08 part 2), `packaging/manifest/launchd/`, `app/Security/Secrets/` + `config/secrets.php` + `App\Security\Ipc\BrokerKeySource` (the PHP end of the broker). Real launchd, Keychain and XPC paths are exercised only on the S1-14 lab Mac; every test runs against recording doubles. |
| **Blueprint** | §2 (separate supervised processes; `php artisan serve` forbidden), §9 (automatic launch, crash restart, bounded retries, health probes, "tydligt om användarinloggning krävs"), §12.1 `BOOT_SERVICES`, §16 step 5 (kill/restart and uninstall verified on a clean Mac — S1-14) |
| **Decisions** | ADR-001 D3 (one LaunchAgent, the Supervisor; verify on every boot; backoff and bound; **user login required**), D4 (no `.env`, non-secret config as environment); ADR-003 D1 (what lives in the Keychain), D2 (one writer), D3 (the broker), D7 row 12 (no secret in any child's environment), D8 (reset pairing, uninstall); [ipc-contract.md](ipc-contract.md) §3.1–3.3 (`K`, pairing grant, tokens), §6.8/§7 (service control is XPC); [health-contract.md](health-contract.md) §1, §6 |
| **Tests** | `apps/macos/LaravelExecutiveAITests/Services/` (`ServiceSupervisorTests`, `SupervisorSpawnTests`, `LaunchAgentServiceBooterTests`, `SupervisorSecretsTests`, `SecretsBrokerTests`, `SupervisorControlServiceTests`, `SupervisorRuntimeTests`, `IPCSigningFixtureTests`, `SupervisorBundleTests`), `tests/Feature/Secrets/`, `packaging/manifest/launchd/test/supervisor-plist.test.mjs` |

## 1. Process tree

```
launchd (the user's Aqua session)
└── <prefix>.supervisor                      LaunchAgent, KeepAlive, ThrottleInterval 10 s
    └── LaravelExecutiveAISupervisor         Contents/MacOS/, headless Swift, the only Keychain writer
        ├── frankenphp run --config Caddyfile                               core      (loopback HTTP)
        ├── frankenphp php-cli app/artisan queue:work database …            worker
        └── frankenphp php-cli app/artisan schedule:work                    scheduler (+ schedule:run grandchildren)
```

`<prefix>` is `LXAI_BUNDLE_PREFIX` (`se.stafe.executive-ai` until A12). Three channels hang off the
Supervisor, each with a different peer and trust model (ipc-contract §0):

| Channel | Path | Peer | Trust |
|---|---|---|---|
| Secrets broker | `~/Library/Application Support/<bundle id>/run/secrets.sock` (dir 0700, socket 0600) | the three children and their descendants | peer verified before a byte is read: same uid, pid in the spawned tree, code satisfies the Team-ID requirement (ADR-003 D3.2) |
| Control (XPC) | Mach service `<prefix>.supervisor.control` (declared in the plist's `MachServices`) | the GUI app | `setCodeSigningRequirement` on both ends (ipc-contract §7) |
| Health probes | `http://127.0.0.1:<port>/health/live` and `/health/ready` | the core | `/health/ready` with the Supervisor's own `supervisor`-scoped token, request and response signed (ipc-contract §2, §4) |

ADR-001 D3 places the executable at `Contents/MacOS/LaravelExecutiveAISupervisor`; that is what
is built (a `com.apple.product-type.tool` target embedded by the app target, plist copied to
`Contents/Library/LaunchAgents/`). ADR-003 D5 expects the Supervisor to be a *bundle*
(`Contents/Helpers/LaravelExecutiveAISupervisor.app`) because `keychain-access-groups` is a
restricted entitlement that needs an embedded provisioning profile. The two ADRs disagree on the
container, not on the process model. S1-12 (signing) decides: if the data-protection keychain
refuses a bare tool, the target's product type changes to an application bundle and
`BundleProgram` gains `…app/Contents/MacOS/…` — a one-line plist change and a pbxproj
product-type change, nothing in the code. Flagged for the ADR owners in the PR.

## 2. Boot sequence (`SupervisorRuntime.boot()`)

1. Resolve identity (`SupervisorIdentity`): bundle prefix from the enclosing `.app`'s
   `CFBundleIdentifier`, Team ID from the running process's own signature. No Team ID → no Keychain
   access group, no code-signing requirement → everything that depends on a signature fails closed.
2. Resolve the installed runtime (`runtime/current` → `runtime/<version>/`) and the loopback port
   from `install/checkpoint.json` (`preflight.port`). Missing either → exit 78; launchd retries
   every 10 s, which is the right cadence for "the installer has not finished yet".
3. `SupervisorSecrets.ensureAppKey()` and `ensureIpcKey()`: first boot generates `APP_KEY` (32
   random bytes, Laravel's `base64:` form, envelope `laravel_app_key`) and `K` (32 random bytes,
   envelope `hmac_key`); later boots read them. An all-zero `K` is refused on write and replaced on
   read. Keychain unavailable → exit 78.
4. Start the secrets broker on `run/secrets.sock` and the XPC control listener — **before** any
   child, so a verification failure in step 5 is visible from the GUI and recoverable.
5. `PackageVerifier.verify(runtimeDirectory:)` — the same manifest + `SHA256SUMS` + per-file digest
   + no-unlisted-file + `SecStaticCode` check the installer ran, on every boot (ADR-001 D3). Failure:
   nothing is spawned, all three services are `failed` with the reason, the Supervisor keeps
   serving XPC. "Start services" from the GUI re-verifies before spawning.
6. Spawn the three children (`ServiceSpec.coreServices`) and run the health monitor.

SIGTERM (launchd's `ExitTimeOut` is 30 s) stops the monitor, the listener and the broker, then
every child with SIGTERM → 10 s → SIGKILL, and exits 0.

## 3. State machine per child

```
            spawn                    probe ok                   probe ok
 stopped ──────────▶ starting ───────────────▶ healthy ◀──────────────── degraded
    ▲                   │  ▲                      │                          ▲
    │ stopAll / stop    │  │ backoff elapsed      │ probe fail (1 of N)      │ probe fail
    │                   │  │                      ▼                          │
    │               exit│  └──────── starting ◀── (N consecutive fails: SIGTERM, counts as a crash)
    │                   ▼
    └──────────── restart budget exhausted ──────────────────────────────▶ failed
                                                                 (only a person's start leaves it)
```

| Service | Health signal | Probe | `ok` | `fail` | `skip` |
|---|---|---|---|---|---|
| core | `GET /health/live` → `{"status":"ok"}` | every 5 s | `healthy` | `degraded`; 3 in a row → stop + bounded restart | — |
| worker | `checks[name=queue_worker].status` from `GET /health/ready` | every 30 s | `healthy` | `degraded`; 3 in a row → stop + bounded restart | `degraded`, never restarted (a dependency such as migrations failed; a restart cannot fix it) |
| scheduler | `checks[name=scheduler_selftest].status` | every 30 s | `healthy` | as worker | as worker |

Rules the implementation pins with tests:

- **Never "process exists".** A child that runs but does not answer is `degraded`, not healthy.
- **`ready: false` as a whole never restarts anything** — only the two named checks are read.
  `menubar_integration` failing after "Quit UI" is not a worker problem (ipc-contract §6.5).
- A health-driven stop goes through the same bounded path as a crash, with
  `last_exit_reason: "stopped by the Supervisor: queue_worker failed 3 times in a row"`.
- An exit the Supervisor caused (`stopAll`, `restart`) is never counted against the budget.

## 4. Restart policy and the bound (ADR-001 D3)

| | |
|---|---|
| Backoff before restart *n* inside the window | 1, 2, 4, 8, 16, 30 s (the last repeats) |
| Bound | **5 restarts per 10 minutes**, sliding window |
| After the bound | `failed`, detail `restarted 5 times within 10 minutes; not retrying until you start it again`; nothing moves on its own |
| Leaving `failed` | a person's `start`/`startAll`/`restart` over XPC (menu bar "Restart services", installer Retry) resets the budget |
| Outer guarantee | launchd `KeepAlive` relaunches a crashed Supervisor, throttled to one launch per `ThrottleInterval` = 10 s |

`ServiceStatus` (what `services()` returns, ipc-contract §7.2): `name`, `state`
(`starting|healthy|degraded|failed|stopped` — `healthy` is the contract's `running`, `failed` is
the post-bound state the contract asks to surface), `pid`, `restarts_in_window`,
`last_exit_reason`, `since`, `detail`. Observers get every change; the GUI renders them.

## 5. Is user login required? **Yes.**

The Supervisor is a **user LaunchAgent** (`LimitLoadToSessionType: Aqua`), not a LaunchDaemon
(ADR-001 D3 and its Alternatives 6). Consequences, stated plainly so the installer and the
operations guide can repeat them:

| Situation | Services |
|---|---|
| User logged in, Mac awake | running |
| User logged in, **screen locked** | running — a LaunchAgent lives in the login session, and Keychain items are `kSecAttrAccessibleAfterFirstUnlock` precisely so the 05:00 brief can read secrets with the screen locked (ADR-003 D1). **RR-04**: Apple documents that attribute device-generically; the lab Mac proves a scheduled read at 05:00 behind a locked screen before this row is called closed (S1-14) |
| Mac asleep | not running; macOS resumes the session on wake, the scheduler catches up its next tick |
| **After a reboot or power cut, before login** | **not running.** FileVault needs the password to unlock the volume at all; even without FileVault a LaunchAgent is bootstrapped at login. A 05:00 brief scheduled while nobody is logged in is not generated at 05:00 |
| User logs out | stopped with the session; started again at the next login |
| Another user's session (fast user switching) | services run only in the owner's session |

What follows for the brief: generation must be **idempotent per day and catch up**, so that the
first `schedule:work` tick after login produces the day's brief if none exists yet, rather than
waiting for the next 05:00. That rule belongs to the brief generation item and is recorded here
as the Supervisor's consequence, not re-implemented here. The installer's Done screen already
carries the FileVault note (installer.md §7).

## 6. Secrets: `APP_KEY`, `K`, and the broker (ADR-003 D3, Sprint 1 minimum)

**Swift (the Supervisor).** `KeychainStore` is the protocol; `SecItemKeychainStore` writes
generic-password items with exactly ADR-003 D1's attributes (`kSecUseDataProtectionKeychain`,
`kSecAttrSynchronizable = false`, `kSecAttrAccessibleAfterFirstUnlock`, access group
`<TEAMID>.se.stafe.executive-ai.secrets`, label `Laravel Executive AI — <service>`);
`InMemoryKeychainStore` is what tests use. `SupervisorSecrets` owns the inventory
(`SecretService.inventory` = ADR-003 D1's ten service names, prefix-independent of A12) and the
envelopes:

```json
{"v":1,"kind":"laravel_app_key","current":"base64:<44 chars>","previous":[],"issued_at":"…"}
{"v":1,"kind":"hmac_key","key":"<base64 32 bytes>","issued_at":"…"}
```

**The broker socket** (`SecretsBrokerServer`): one length-prefixed JSON frame per connection
(4-byte big-endian length + UTF-8 JSON), operations `get | put | delete | list | health`
(D3.1), every operation a fresh Keychain read, audited by service, account, op, pid and result —
never the value. `PeerVerifying` runs **before the request is read**; a rejected peer gets the
connection closed and not a byte back. `StrictPeerVerifier` implements D3.2: `getpeereid` uid ==
own uid; `LOCAL_PEERPID` is one of the live children or a descendant (parent chain via
`sysctl KERN_PROC_PID`, ≤ 16 hops, never through pid 1); `LOCAL_PEERTOKEN` →
`SecCodeCopyGuestWithAttributes` → `SecCodeCheckValidity` against the Team-ID requirement.
Without a Team ID (unsigned build) every peer is rejected unless the DEBUG-only
`LXAI_SUPERVISOR_ALLOW_UNSIGNED_PEERS=1` is set for a lab run. The `locked` state exists
(`SecretsBrokerService.lock()`) and refuses every `get`; nothing triggers it yet (ADR-005's panic
stop, a later item).

**PHP (the core).** `App\Security\Secrets\SecretStore` (`get`, `put`, `delete`, `list`),
`SecretRef`, `SecretValue` (cannot be serialised, JSON-encoded, cast, cloned or dumped; buffer
overwritten on destruct, the same posture as `IpcKey`), drivers `SupervisorSecretStore`
(the socket client), `ArraySecretStore` (tests) and `UnavailableSecretStore` (fail closed).
`config/secrets.php` selects `supervisor` whenever `LEA_SECRETS_SOCKET` is in the environment —
which the Supervisor always passes (runtime-layout.md §3) — `array` under tests, `unavailable`
otherwise. **How PHP gets `APP_KEY` today:** `App\Security\Secrets\SecretsServiceProvider`
(first in `bootstrap/providers.php`) reads `core.app-key` through the store and sets `app.key` and
`app.previous_keys` in memory before the encrypter resolves; a broker failure there is fatal on
purpose. **How PHP gets `K`:** `App\Security\Ipc\BrokerKeySource` is bound as `IpcKeySource`
whenever the driver is `supervisor` (ipc-server.md §2); it re-reads the envelope on every call,
so "Reset security pairing" takes effect on the next request without a push notification.

**Deferred from ADR-003 D3, explicitly:**

| D3 element | Status |
|---|---|
| `SecretStore::rotate(SecretRef, callable)` and the D8 `APP_KEY` rotation job | not built; the envelope carries `previous[]` so Laravel's previous-keys path works once a rotation writes it |
| `secrets.changed` notification from the broker to the core | not built; `K` is re-read per request (equivalent for `K`); an `APP_KEY` rotation needs a core restart in Sprint 1 |
| `SodiumFileSecretStore` for unsigned developer builds (D3.4/D3.5) | not shipped; a developer checkout runs `unavailable` with `APP_KEY` from `.env`, and the IPC answers 503 `secrets_unavailable` |
| Broker audit into `audit_events` | the audit goes to the unified log (subsystem `<prefix>.supervisor`, category `secrets-broker`) — the Supervisor has no database; S1-13/E4 may forward it |
| D4 OAuth `put` from PHP | the `put` operation and `SecretStore::put` exist; no caller until the connectors land |
| D5 data-protection keychain from a Developer-ID helper (R5), 05:00 locked-screen read (R4) | prototype obligations for S1-12/S1-14 on a signed build |

## 7. Control surface (ipc-contract §7)

`SupervisorControlling` (Swift-native) is implemented in-process by `SupervisorControlService`
and carried over `NSXPCConnection` by `SupervisorControlXPC.swift` (listener in the Supervisor,
JSON `Data` payloads, sanitised error codes). The GUI uses `XPCSupervisorControlClient`, built by
`SupervisorControlClientFactory.live()`: Mach service `<prefix>.supervisor.control`, requirement
`anchor apple generic and certificate leaf[subject.OU] = "<TEAMID>"` derived from the app's own
signature, 10 s per call.

| Method | Does | Guard |
|---|---|---|
| `pair(client:) -> PairingGrant` | mints `session_id` (ULID), token, signing key, 30-day expiry (§3.2/§3.3); one live session per client | code-signing requirement |
| `resetPairing() -> PairingGrant` | regenerates `K` in the Keychain, drops every session, rotates the Supervisor's own probe grant, returns a fresh `menubar` grant | the GUI clears `BriefCache` (`SupervisorServiceControl.resetPairing()`) |
| `services() -> [ServiceStatus]` | §3 bookkeeping | — |
| `restart(service:)`, `stopAll()`, `startAll()` | process control with the §4 budget; `startAll` re-verifies the runtime first | `stopAll` leaves launchd `KeepAlive` in place: the Supervisor keeps running, PHP stops |
| `elevate(sessionId:operation:nonce:) -> Elevation` | `LAContext.evaluatePolicy(.deviceOwnerAuthentication)`, then a one-shot §7.3 token (60 s) | live `menubar` session, slug and 22-char nonce validated |
| `storeProviderApiKey(provider:apiKey:)` | SELECT_AI's own key → Keychain `ai-provider/<provider id>` through the one writer | provider must be a known adapter id |

Who calls what:

- **Installer `BOOT_SERVICES`** — `LaunchAgentServiceBooter` (the live `ServiceBooting`):
  `status()` → `register()` if needed → `.requiresLoginItemApproval` when macOS wants the click →
  otherwise polls `services()` until the Supervisor answers, hands over the SELECT_AI key parked in
  `PendingSecretHandoff` by `SupervisorAiSelectionApplier`, returns `.started`. A failed hand-off
  keeps the key in memory for Retry; it never touches disk.
- **Menu bar** — `SupervisorServiceControl` is the live `ServiceControlling` in `PanelPorts.live`:
  "Restart services" = `stopAll` + `startAll`; "Stop services" = `stopAll`; uninstall is S1-13.
- **S1-08 part 2** builds the loopback IPC client on `IPC/IpcSigning.swift` (`IpcSigning`,
  `PairingGrant`, `ULID`, `SecureRandom`) and gets its grant from `pair(client: .menubar)`; the
  installer's `HealthProbe` can be `LoopbackHealthProbe` with that grant (the Supervisor already
  uses it with its own `supervisor` grant).

## 8. Uninstall (S1-13 calls this, in this order)

```swift
try await ServicesUninstaller(registrar: SMAppServiceRegistrar(plistName:), secrets: SupervisorSecrets(store:)).remove()
// 1. registrar.unregister()        — SMAppService.unregister(): the agent cannot respawn a Supervisor
// 2. secrets.removeAll()           — every se.stafe.executive-ai.* item (ADR-003 D8, T-41)
// then S1-13: RuntimeUninstaller.remove(preservingData:in:), BriefCache.clear()
```

`LaunchAgentRegistering` is the whole launchd API surface: `register()`, `unregister()`,
`status() -> notRegistered | enabled | requiresApproval | notFound`, `openLoginItemsSettings()`.
`SMAppServiceRegistrar` is the real one; `RecordingRegistrar` is what every test uses. Keychain
deletion from the GUI process needs the shared access group only the Supervisor holds (ADR-003
D2); S1-13 decides whether the sweep runs through the Supervisor before step 1 or through the
uninstaller's own entitlement — the order above holds either way.

## 9. Logs

- Children: `~/Library/Logs/<bundle id>/{core,worker,scheduler}.log`, 0600, rotated at 5 MB,
  five generations kept (`RotatingLogFile`). Caddy logs JSON at `WARN`; Laravel's own channels
  carry the ADR-003 Redactor.
- Supervisor: unified log, subsystem `<prefix>.supervisor`, categories `boot`, `services`,
  `secrets-broker`, `control`. Every interpolated value is `.private` (T-39); only literals and
  enum names are `.public`.

## 10. What S1-14 verifies on the lab Mac (not provable in CI)

1. `SMAppService.register()` from a signed bundle; the Login Items approval sheet; `status()`.
2. `kill -9` of each child → respawn; six crashes in ten minutes → `failed` in the menu bar; "Restart
   services" recovers.
3. `kill -9` of the Supervisor → launchd relaunches it within 10 s; children are re-adopted by
   respawn (the old ones receive SIGHUP/SIGTERM from the dying parent's pipes closing — verify).
4. Uninstall → `launchctl print gui/$(id -u)/<prefix>.supervisor` reports nothing; Keychain Access
   shows no `Laravel Executive AI` items.
5. The 05:00 locked-screen Keychain read (RR-04) and the data-protection keychain from a
   Developer-ID helper (ADR-003 R5).
6. The broker's code-signature peer check against the signed FrankenPHP (T-36) — in CI only the
   uid, pid-tree and "no requirement → reject" conditions are provable.

## 11. Known limits

- Children are not placed in private process groups (`Foundation.Process` has no `setpgid`);
  `schedule:run` grandchildren of a SIGKILLed scheduler are orphaned until they finish. A SIGTERM
  path (`stopAll`, health-driven restart) lets `schedule:work` exit cleanly.
- `sun_path` holds 103 bytes; the socket path is
  `/Users/<name>/Library/Application Support/<bundle id>/run/secrets.sock` (~90 bytes with a
  12-character user name). A very long user name makes the broker fail to start with a visible
  boot error; S1-14 notes the longest name tested.
- Unsigned builds fail closed on every signature-dependent path: no Keychain access group, no XPC
  peer accepted, no broker peer accepted, no runtime verified. The DEBUG-only
  `LXAI_SUPERVISOR_ALLOW_UNSIGNED_PEERS=1` relaxes the two peer checks for a lab run and nothing
  else.
- `ServiceStatus.state` uses `healthy|failed` where ipc-contract §7.2 wrote `running` and had no
  post-bound state; S1-08 part 2 maps or the contract gains the two names in a minor bump.
