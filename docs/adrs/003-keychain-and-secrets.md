# ADR-003: Secrets live in the macOS data protection keychain and reach PHP only through the Supervisor's authenticated local IPC; SQLite stays unencrypted in v1

| | |
|---|---|
| **Status** | Proposed |
| **Date** | 2026-10-10 |
| **Deciders** | H (Security/privacy lead, owner) · A (macOS/Swift lead, entitlements and Supervisor) · B (Laravel platform lead) reviews · Andreas signs the residual-risk acceptances and A12 |
| **Closes** | Blueprint §15 item 3 (second half: Keychain service access, sandboxing feasibility given Peekaboo); `docs/OPEN-QUESTIONS.md` §15.3b; feeds S0-23 (threat model, issue #23) and S0-15 (IPC contract) |

## Context

Blueprint §3.1 names the hazard precisely: *"SQLite krypteras inte automatiskt av FileVault när användaren
är inloggad: lagra credentials i Keychain, gör hotbilder och dokumentera kvarstående risker; utvärdera
SQLCipher separat i security ADR om databaskryptering behövs."* §5.6 requires *"Macens Keychain eller
annan välgranskad krypterad secret store per tjänsteidentitet; aldrig credentials i vanliga
databastabeller, PWA-localStorage, loggar, verktygsbeskrivningar eller AI-prompter"*, OAuth through
`ASWebAuthenticationSession` with PKCE, and *"Säker tokenförnyelse, återauktorisering och
återkallning."* §6 puts the helper's IPC token in the Keychain and asks whether the app can be
sandboxed given Peekaboo's Screen Recording and Accessibility needs. §11 forbids tokens in *"app logs,
crash dumps, prompts, push, screenshots, mailbrief eller repo."*

The hard part is structural, not cryptographic. ADR-001 fixes the process model: a headless Swift
launchd agent, the **Supervisor** (`Contents/Helpers/LaravelExecutiveAISupervisor.app`, registered with
`SMAppService.agent`), spawns and supervises three FrankenPHP children (core HTTP, queue worker,
scheduler) from an immutable runtime directory; ADR-001 ships **no `.env`** and passes only non-secret
configuration through the environment. PHP has no Keychain API. Something signed by us therefore has to
read the Keychain and hand values to PHP, and it has to do so at 05:00 with the screen locked and no
user present, because the Executive Brief (§1.3, §5.5) needs the database, possibly a cloud AI provider
key, and the sender account's OAuth token.

If this stays open, Sprint 1 cannot start (`docs/adrs/README.md`), S0-15 cannot specify what the
Supervisor must authenticate, S0-23 has no data-at-rest or secret-handling baseline, and workstream F
cannot design the connector contract (`EmailConnector::authorization/disconnect`, §5.6).

## Decision

### D1. Where secrets live

Every secret is a **generic-password item in the macOS data protection keychain**, one item per
*service identity*, written with:

| Attribute | Value | Why |
|---|---|---|
| `kSecClass` | `kSecClassGenericPassword` | small opaque blobs |
| `kSecUseDataProtectionKeychain` | `true` | Apple: "highly recommended… for all keychain operations"; enables access groups and `kSecAttrAccessible` on macOS (Evidence E1, E2) |
| `kSecAttrSynchronizable` | `false`, always | credentials must never leave the Mac via iCloud Keychain (§10 Europe-first, §5.6) (E3) |
| `kSecAttrAccessible` | `kSecAttrAccessibleAfterFirstUnlock` | Apple's recommendation for "items that need to be accessed by background applications"; the 05:00 brief runs with the screen locked but the user logged in (E4) |
| `kSecAttrAccessGroup` | `<TEAMID>.se.stafe.executive-ai.secrets` | the only shared group; `<TEAMID>` is pending A12 (E5) |
| `kSecAttrService` | `se.stafe.executive-ai.<service>` | stable, independent of bundle id and Team ID so A12 cannot force a migration |
| `kSecAttrAccount` | identity within the service (ULID of the DB row, or `default`) | never an email address or other PII in keychain metadata |
| `kSecAttrLabel` | `Laravel Executive AI — <service>` | what the user sees in Keychain Access; no PII |
| `kSecValueData` | versioned JSON envelope (below) | one atomic `SecItemUpdate` per rotation |
| `kSecAttrAccessControl` | **not set** for service credentials | `userPresence`/biometry would block unattended brief generation (E6) |

**Service identity inventory (v1).** The `se.stafe.executive-ai.<service>` convention is shared with
ADR-006, which already names `se.stafe.executive-ai.webpush`. **A12 flag:** the Team ID in the access
group and the bundle-id prefix (ADR-001 currently uses the placeholder `com.stafe.laravelexecutiveai`)
are Andreas's A12 decision; ADR-001, ADR-003 and ADR-006 must end up on one prefix, and the
`kSecAttrService` names above are deliberately independent of it so that decision never forces a
Keychain migration.

| `kSecAttrService` | `kSecAttrAccount` | Envelope `kind` | Written by | Read by |
|---|---|---|---|---|
| `se.stafe.executive-ai.core.app-key` | `default` | `laravel_app_key` (`current` + `previous[]`) | Supervisor at install | every PHP process at boot |
| `se.stafe.executive-ai.ipc.menubar` | `default` | `hmac_key` for menu-bar → core session tokens (S0-15) | Supervisor at install | core HTTP |
| `se.stafe.executive-ai.email` | `<email_connections.id>` ULID | `oauth2` or `password` (IMAP app-specific password) | PHP via broker | PHP sync/send jobs |
| `se.stafe.executive-ai.email-sender` | `<brief sender connection id>` | reference to the `email` item (no copy) | PHP | brief delivery |
| `se.stafe.executive-ai.github` | `<external_accounts.id>` ULID | `github_app` (installation token cache) or `oauth2` | PHP | GitHub sync |
| `se.stafe.executive-ai.ai-provider` | `<ai_providers.id>` ULID | `api_key` | PHP | `AiProviderAdapter` |
| `se.stafe.executive-ai.mcp` | `<mcp_servers.id>` ULID | `oauth2` or `bearer` for remote MCP servers | PHP | MCP broker |
| `se.stafe.executive-ai.webpush` | `vapid` | `vapid_keypair` | PHP | push delivery (ADR-006) |
| `se.stafe.executive-ai.tunnel` | `default` | adapter credential if Tailscale Serve needs one (ADR-006) | PHP | tunnel adapter |
| `se.stafe.executive-ai.backup` | `default` | `symmetric_key` for the §9 encrypted local backup | Supervisor at install | backup job |

Envelope (illustrative, not drop-in):

```json
{"v":1,"kind":"oauth2","access_token":"…","refresh_token":"…","token_type":"Bearer",
 "expires_at":"2026-10-10T05:30:00Z","scopes":["https://www.googleapis.com/auth/gmail.readonly"],
 "obtained_at":"2026-10-10T04:30:00Z","provider":"google"}
```

Database rows (`email_connections`, `external_accounts`, `ai_providers`, `mcp_servers`) store only
`secret_service`, `secret_account` and `secret_version`; **no column anywhere may hold a token,
password, key or verifier**, encrypted or not. Laravel's `encrypted` casts remain for *sensitive
attributes* that are not credentials (§3.1), and the `APP_KEY` that protects them is itself a Keychain
item (`core.app-key`), never a file.

### D2. Who may touch the Keychain

Exactly **one process holds the shared access group: the Supervisor** (ADR-001). It carries
`keychain-access-groups = [<TEAMID>.se.stafe.executive-ai.secrets]`. The FrankenPHP binary carries
`com.apple.security.cs.allow-jit` only (ADR-001) and **no** keychain entitlement; the GUI menu-bar app
needs none for secrets (it never sees a token — see D4); the Peekaboo automation helper (ADR-005) has
none and is forbidden from ever holding a credential.

Consequence proven by Apple's rules (E5, E7): a process outside the group that queries the item gets
`errSecItemNotFound`, and one that tries to write into the group gets `errSecMissingEntitlement`; access
is decided by `securityd` from the code-signing entitlements, with no user prompt and no ACL dialog to
social-engineer.

### D3. How PHP obtains secrets: **Option A, implemented as a Supervisor "secrets broker"**

```
                 ┌─────────────────────────┐   SecItemCopyMatching / SecItemAdd / Update / Delete
  Keychain  ◀────│  Supervisor (Swift,     │◀──────────────────────────────────────────────┐
  (data prot.)   │  launchd agent, holds   │                                               │
                 │  the access group)      │   audit log: service, account, op, pid, result│
                 └───────────┬─────────────┘   (never the value)                           │
                             │ Unix socket  ~/Library/Application Support/LaravelExecutiveAI/run/secrets.sock
                             │ dir 0700, socket 0600, peer-verified (D3.2)
          ┌──────────────────┼───────────────────┐
          ▼                  ▼                   ▼
   FrankenPHP core     queue worker         scheduler (+ schedule:run children)
   in-memory only      in-memory only       in-memory only
   SecretStore::get(SecretRef)  → SecretValue (memzero'd on destruct, unserializable)
```

**D3.1 Protocol.** Length-prefixed JSON frames, versioned (`{"v":1,"op":"get","service":"…","account":"…"}`),
operations `get`, `put`, `delete`, `list` (returns accounts for a service, never values), `health`.
The broker never caches; every `get` is a Keychain read (single-digit milliseconds). PHP caches a
`SecretValue` in process memory for at most the current request/job and re-fetches on provider `401`.

**D3.2 Who the broker answers.** The Supervisor accepts a connection only when all of the following hold:

1. `LOCAL_PEERCRED` uid equals its own uid.
2. The peer pid is one of the children it spawned, or a descendant of one (ppid chain walked via
   `sysctl KERN_PROC`), which covers `schedule:run` grandchildren.
3. The peer's audit token (`LOCAL_PEERTOKEN`) resolves through
   `SecCodeCopyGuestWithAttributes(nil, [kSecGuestAttributeAudit: token])` to code that satisfies
   the FrankenPHP designated requirement for our Team ID (E8). The audit token carries the pid
   version, which defeats pid reuse.
4. The broker is not in **locked** state (set by the menu bar's panic/stop action, §6, §11; cleared by
   an explicit user action).

No shared bearer token is needed for PHP → Supervisor; the peer identity is the credential. The
menu-bar → core direction keeps its session-bound token per §6, with its HMAC key in
`ipc.menubar` (S0-15 owns the token format; this ADR only owns where its key lives).

**D3.3 Laravel boot.** `App\Security\Secrets\SecretsServiceProvider::register()` fetches
`core.app-key` and sets `app.key` / `app.previous_keys` in memory *before* the encrypter resolves.
`bootstrap/cache/config.php` lives in the immutable runtime (ADR-001) and must contain placeholders
only; a test greps it for the fixture secret corpus (D7).

**D3.4 PHP contract.** `app/Security/Secrets/` provides `SecretStore` (interface: `get`, `put`,
`delete`, `list`, `rotate(SecretRef, callable)`), `SecretRef` (service + account, value object),
`SecretValue` (final; `__serialize()`, `jsonSerialize()`, `__debugInfo()` and `__toString()` throw or
mask; `sodium_memzero()` in `__destruct()`), and three drivers: `SupervisorSecretStore` (production,
socket), `ArraySecretStore` (tests), `SodiumFileSecretStore` (unsigned developer builds only; refuses
to construct when `app()->isProduction()`; libsodium `sodium_crypto_secretbox` with a key from
`LXAI_DEV_SECRETS_KEY`, E9). The driver is selected in `config/secrets.php`; a feature test asserts
the packaged configuration selects `supervisor`.

**D3.5 Developer builds.** Unsigned `swift build` binaries cannot join an access group. Developers run
the Supervisor from Xcode with a Development-signed build and a development provisioning profile, or
use `SodiumFileSecretStore`. Neither path is reachable in a notarised build.

### D4. OAuth data flow (§5.6)

PHP generates `state` and the PKCE `code_verifier` and keeps them in the cache (file store under
`data/`, 10-minute TTL, verifier hashed in the cache key, never logged). The authorization URL is opened
by `ASWebAuthenticationSession` (menu-bar app, custom callback scheme, E10) or by the system browser
(PWA, loopback redirect). The callback URL carries only `code` and `state`; the menu-bar app forwards it
to the core over S0-15 IPC. **PHP performs the token exchange** and calls `SecretStore::put`. Neither
the GUI app nor the Supervisor's logs ever hold a token in a form other than a transient Keychain write.

### D5. App Sandbox verdict

**No executable is App-Sandboxed in v1.** This is evidence, not assumption:

- Apple, *Protecting user data with App Sandbox*: "Certain activities are forbidden by the operating
  system when an app runs in a sandbox… The restricted activities are: Use of Authorization Services
  API. Use of accessibility APIs in assistive apps. Sending Apple Events to arbitrary apps. …
  Terminating other running apps." (E11). Peekaboo requires Accessibility ("Accessibility (required)
  – enables window, menu, dialog, and action-based element/query automation", E12), and §9 requires
  Authorization Services for the `pmset` guidance with explicit administrator approval.
- Apple DTS confirms there is no entitlement that unlocks it: "It's not possible to use the
  Accessibility APIs from a sandboxed app… `com.apple.security.accessibility`… isn't a thing. AFAICT
  your only path forward here is to directly distribute your app using Developer ID signing." (E13).
  The Entitlements catalogue contains no Accessibility-control entitlement (E14).
- A sandboxed app's children inherit its sandbox: an embedded tool "must inherit the containing app's
  sandbox configuration" via `com.apple.security.inherit` (E15). The Supervisor spawns FrankenPHP;
  if the Supervisor were sandboxed, PHP would be too, with only the network/file entitlements listed in
  E16 — feasible in principle, but it moves the data directory into `~/Library/Containers`, forbids the
  `pmset` path, and brings no Keychain benefit (access groups work identically for sandboxed and
  non-sandboxed processes, E5).

**What changes if the Peekaboo helper is a separate, non-sandboxed process (ADR-005 design):**
the GUI app loses the Accessibility blocker, but still keeps the Authorization Services blocker (§9)
and the "terminating other running apps" blocker (panic-stop of a runaway automation). The Supervisor
cannot be sandboxed while it must spawn a non-sandboxed PHP. So sandboxing the GUI app becomes
*possible* only once (a) power management guidance moves to a privileged helper or is dropped, and
(b) panic-stop is implemented by signalling the helper's own launchd job rather than killing it. Recorded
as a reopen condition, not pursued in v1. Mac App Store distribution is out of scope (Developer ID + notarisation only).

**Required entitlements per executable (v1):**

| Executable | Hardened runtime | Sandbox | Keychain | Other |
|---|---|---|---|---|
| `LaravelExecutiveAI.app` (GUI) | yes | no | default private group only (its S0-15 token, if stored) | none |
| `LaravelExecutiveAISupervisor` | yes | no | `keychain-access-groups: [<TEAMID>.se.stafe.executive-ai.secrets]` | none |
| FrankenPHP (`frankenphp`) | yes | no | **none** | `com.apple.security.cs.allow-jit` (ADR-001) |
| Peekaboo automation helper (ADR-005) | yes | no | **none** | TCC grants (Screen Recording, Accessibility) attach to this process, not to the GUI app (E12) |

**Packaging implication for A (verify in the S1 prototype):** `keychain-access-groups` and
`com.apple.application-identifier` are restricted entitlements that Apple enforces "through code
signing, provisioning profiles, and the Apple Developer Program" (E17). A bare Mach-O cannot embed a
provisioning profile, so the Supervisor must be packaged as a bundle
(`Contents/Helpers/LaravelExecutiveAISupervisor.app`, referenced by the agent plist's
`BundleProgram`, as ADR-001 D3 now specifies) with a Developer ID provisioning profile embedded. If the prototype shows the data
protection keychain is unusable for a Developer-ID helper, the fallback is the file-based login
keychain with a code-signing ACL (`SecAccess` trusted application by designated requirement) in the
Supervisor's own keychain — same broker, same contract, weaker isolation (ACL prompts, no access
groups). The fallback is **not** the plan.

### D6. SQLCipher: **no for v1**, designed so it can be turned on later

- **Licensing.** SQLCipher Community Edition is BSD-3-Clause (E18) — MIT-compatible — but Zetetic adds
  an attribution condition: the licence must be reproduced "in the application interface and/or
  materials… in a user accessible location" (E19). Fine for an about screen; a legal review is still
  required before shipping it (§15.10, ADR-007).
- **Build cost.** PHP ≥ 7.4 links an external `libsqlite3`; the bundled copy was removed (E20).
  SQLCipher presents the SQLite C API with `SQLITE_HAS_CODEC` and `SQLITE_TEMP_STORE=2` and an OpenSSL,
  CommonCrypto or LibTomCrypt backend (E21). FrankenPHP is built with static-php-cli (ADR-001), which
  documents `sqlite3`/`pdo_sqlite` but no SQLCipher substitution (E22): adopting it means a patched
  static-php-cli recipe, a second crypto library in the SBOM, and `PRAGMA key` issued as the first
  statement on every connection (E23) through a `ConnectionEstablished` listener. That is a
  maintained fork of our runtime build for the lifetime of the product.
- **Key custody.** Would be a 32-byte raw key in `se.stafe.executive-ai.db` fetched through D3, used as
  `PRAGMA key = "x'…'"` so the 256 000-iteration PBKDF2 is skipped (E23). The key would reach every PHP
  process by the same path as every other secret, so an attacker who can make the core read secrets
  (see R1) also gets the database key. SQLCipher raises the bar against *offline copies* of the file,
  not against the same-user attacker §3.1 worries about.
- **Measured performance (E24).** CLI-level, same workload, WAL, 200 000 rows of ~1.2 KiB each on the
  machine profile in E24: inserts 1.15 s → 2.08 s (+81 %), full-table aggregate 0.29 s → 0.98 s
  (+240 %), 5 000 indexed point lookups 23 ms → 28 ms (+22 %), bulk update 0.86 s → 1.01 s (+18 %),
  connection open + one query 4.8 ms → 6.0 ms (+1.2 ms) with a raw key, **51.7 ms** with a passphrase.
  Expectation for the product: sync-heavy writes and FTS rebuilds roughly 2× slower, interactive
  reads within noise, provided the raw-key form is used.
- **Decision.** Not in v1. Reasons in order: it does not address the §3.1 attacker, it forks the runtime
  build ADR-001 just stabilised, and the write-path cost lands exactly on the 100 000-mail sync
  acceptance test (§5.6 criterion 3). The schema and the `SecretStore` leave room for it: the DB layer
  must not assume the file is readable without a connection hook, and `db` is reserved in the inventory.
- **Residual risk in plain language (§3.1).** FileVault encrypts the whole volume at rest: when the Mac
  is off, the data is unreadable without the user's password or recovery key (E25). **While the user
  is logged in, every process running as that user can read the SQLite file in clear**, including
  mail bodies, CRM notes and brief drafts. FileVault does not distinguish our app from a malicious
  script in the same session. Mitigations in v1: credentials are never in the file (D1); the data
  directory is excluded from Time Machine with `isExcludedFromBackupKey` (E26) so unencrypted copies do
  not accumulate on backup disks, with the §9 app-owned encrypted backup as the sanctioned copy
  (default pending Andreas, see open questions); `PRAGMA secure_delete=ON`; the WAL and `-shm` files
  live in the same 0700 directory.

### D7. Redaction points — mechanism and the test that proves it (feeds S0-23 / issue #23)

A shared fixture, `tests/Security/fixtures/secrets-corpus.json`, holds synthetic secrets in every
format we handle (Google `ya29.`/`1//` tokens, Microsoft JWTs, GitHub `ghp_`/`github_pat_`, `sk-`
style API keys, app-specific passwords, VAPID keys, our own IPC tokens, `base64:` APP_KEYs). Every
test below injects the corpus and asserts zero matches in the sink. `App\Security\Secrets\Redactor`
is the single implementation used by all PHP sinks (key-based: `authorization`, `token`,
`*_token`, `password`, `client_secret`, `code_verifier`, `api_key`, `cookie`; pattern-based: the
corpus formats; plus registered live values: every `SecretValue` the process has fetched registers
its value with the redactor so even unknown formats are caught).

| # | Sink | Mechanism | Proof |
|---|---|---|---|
| 1 | Laravel log channels | Monolog processor `RedactSecretsProcessor` registered through `tap` on **every** channel in `config/logging.php` (E27); `Log::shareContext` carries request/job ids, never credentials; `Context::addHidden` for anything that must travel with a job but not into logs (E28) | `LogRedactionTest`: logs corpus via every configured channel, greps files; `LoggingConfigTest`: every channel has the tap |
| 2 | Exception reporting and rendering | `APP_DEBUG=false` in packaged config (Laravel: debug in production risks "exposing sensitive configuration values", E29); `withExceptions`: `context()` only adds ids; HTTP client exceptions wrapped so `RequestException` messages never contain headers or URLs with `access_token=`; `dontFlash` for `password`, `code`, `client_secret` | `ExceptionRedactionTest`: throws with corpus in message/context, asserts log and failed job record; `PackagedConfigTest` asserts debug off |
| 3 | Queue payloads and `failed_jobs` | Jobs receive `SecretRef`/model ids, never `SecretValue` (`__serialize()` throws); `SerializesModels` stores identifiers only (E30); any job carrying provider responses implements `ShouldBeEncrypted` (E30); `failed_jobs.exception` passes through the `Redactor` | Pest arch test: no job property typed `SecretValue`; `QueuePayloadTest`: dispatch with corpus, dump `jobs`/`failed_jobs`, zero matches |
| 4 | AI prompts, tool schemas, tool transcripts | Tools take `connection_id`, never credentials (schema lint: no parameter named like a secret); `ai_runs`/`tool_runs` persistence passes prompts and transcripts through the `Redactor`; `SecretValue` never reaches the `AiRequest` DTO (arch test: `App\AI` must not depend on `SecretValue`) | `ToolTranscriptRedactionTest` with synthetic transcripts (§14) containing corpus; `ToolSchemaTest` |
| 5 | Outbound HTTP | Global `Http` middleware strips `Authorization` from any recorded request/response dump; `Http::preventStrayRequests()` in tests | `HttpClientRedactionTest` |
| 6 | Swift logs and crash reports | `os.Logger` with interpolated values at default (`.private`) privacy; `.public` only for string literals (E31); SwiftLint custom rule forbids `privacy: .public` on non-literals; crash reports contain backtraces, registers and binary images (E32), not heap, but the Supervisor zeroes secret buffers after each frame and never puts a value in an exception message | CI lint + `SupervisorLoggingTests` asserting the unified log contains `<private>` for a corpus value |
| 7 | Push payloads (ADR-006) | Payload schema allowlist: `brief_id`, `version`, generic title; no customer text, no tokens (§8) | `PushPayloadSchemaTest` snapshot |
| 8 | Screenshots (Peekaboo, ADR-005) | Screenshots are session-scoped files in `Caches/`, deleted at session end; `audit_events` and `tool_runs` store hash + dimensions + app/window only (§6); secure text fields blacked out by AX role before the model sees the frame | `AuditNoBlobTest` (no binary columns, no image paths in audit rows); redaction fixture with a password field |
| 9 | Diagnostics export (§14) | Allowlisted sources only (health events, redacted logs, versions, entitlements dump); never `data/`, never the Keychain, never `bootstrap/cache`; runs the `Redactor` over every byte; requires a user click | `DiagnosticsExportTest`: export with corpus everywhere, zero matches, and no `data/` paths in the archive |
| 10 | Mail brief | `App\Briefs\Render` may not depend on `App\Security\Secrets` (arch test); the brief JSON schema has no credential field | Pest arch test + schema snapshot (S0-13) |
| 11 | Repository and CI | `gitleaks` in CI (S0-05) with the corpus formats as custom rules; `.gitignore` for `storage/`, `*.keychain-db`, `*.p12`, `*.provisionprofile` | CI job red on a planted fixture in a throwaway branch |
| 12 | Process environment and `ps` | No secret is ever placed in a child's environment or argv (ADR-001 passes non-secret config only); the broker socket is the sole channel | `SupervisorSpawnTests`: environment of spawned children contains no corpus value |

### D8. Rotation and revocation at the storage layer

- **Refresh (automatic).** Per connection a `Cache::lock("secret:{service}:{account}")` (10 s)
  serialises refreshers so at most one process refreshes. New token set written as one `put`
  (atomic `SecItemUpdate`); the previous envelope is kept in memory only until the write succeeds.
  Microsoft returns a new refresh token on every use and says to "securely delete the old refresh
  token after acquiring a new one" (E33) — the single-item envelope does exactly that. `invalid_grant`
  → DB status `needs_reauthorization`, item left in place (so `Reconnect` can revoke it), UI shows
  `Reconnect` without any secret in logs (§5.6 criterion 5).
- **Reauthorize.** Runs D4 again; on success `put` replaces the envelope and the old access/refresh
  tokens are revoked at the provider best-effort (Google: `https://oauth2.googleapis.com/revoke`,
  E34; Microsoft: no app-initiated revoke endpoint for refresh tokens, so the old token is simply
  discarded, E33).
- **Disconnect.** Order: provider revoke (best-effort, 10 s timeout) → `SecretStore::delete` →
  `email_connections.status = disconnected` → `audit_events` row (service, account, outcome; no value)
  → retention job purges synced data per the connection's policy. Delete is never skipped because
  revoke failed.
- **Uninstall.** The uninstaller enumerates `kSecAttrService LIKE "se.stafe.executive-ai.*"` in the
  group and deletes every item; the Keychain does not remove items when an app is deleted, so this is
  an explicit step and a user-visible promise.
- **`APP_KEY` rotation.** `core.app-key` envelope holds `current` and `previous[]`; Laravel decrypts
  with previous keys when the current fails (E35). Rotation is an admin action in the PWA that writes
  the envelope and re-encrypts `encrypted` attributes in a queued job; sessions are invalidated.
- **IPC key rotation.** `ipc.menubar` is regenerated on every reinstall and by a "Reset security
  pairing" action; the menu bar re-pairs through the Supervisor (S0-15).
- **Compromise response.** The broker's **locked** state (D3.2) refuses all `get`s instantly from the
  menu bar; the audit log of `get` operations (service, account, pid, timestamp) is the first artefact
  an incident review reads.

## Evidence

**Vendor documentation** (all retrieved 2026-10-10; Apple pages read through the documentation JSON
endpoint because the HTML is client-rendered).

- **E1** `kSecUseDataProtectionKeychain` — https://developer.apple.com/documentation/security/ksecusedataprotectionkeychain
  (macOS 10.15+): "Set the value for this key to `true` in the query dictionary when accessing a macOS
  keychain item that behaves like an iOS keychain item. For example, use the data protection key when
  adding, searching for, or deleting an item to which the `kSecAttrAccessible` or `kSecAttrAccessGroup`
  attributes apply." … "It's highly recommended that you set the value of this key to `true` for all
  keychain operations."
- **E2** `kSecAttrAccessible` — https://developer.apple.com/documentation/security/ksecattraccessible:
  "You can use this attribute for macOS keychain items only if you also set a value of `true` for the
  `kSecUseDataProtectionKeychain` key, the `kSecAttrSynchronizable` key, or both."
- **E3** `kSecAttrSynchronizable` — https://developer.apple.com/documentation/security/ksecattrsynchronizable:
  "indicates whether the item in question is synchronized to other devices through iCloud" … "To create
  a keychain item in macOS that behaves like an iOS keychain item without making it synchronizable, use
  `kSecUseDataProtectionKeychain` instead."
- **E4** `kSecAttrAccessibleAfterFirstUnlock` — https://developer.apple.com/documentation/security/ksecattraccessibleafterfirstunlock
  (macOS 10.9+): "The data in the keychain item cannot be accessed after a restart until the device has
  been unlocked once by the user. After the first unlock, the data remains accessible until the next
  restart. This is recommended for items that need to be accessed by background applications." Apple
  Platform Security, *Keychain data protection* (https://support.apple.com/guide/security/secb0694df1a/web):
  "Apps that use background refresh services can use kSecAttrAccessibleAfterFirstUnlock for keychain
  items that need to be accessed during background updates." `kSecAttrAccessibleWhenUnlocked` is "the
  default value for keychain items added without explicitly setting an accessibility constant" and is
  "recommended for items that need to be accessible only while the application is in the foreground" —
  hence the explicit choice. **Prototype obligation (S1):** lock the screen at 04:59 and prove a
  scheduled `get` at 05:00 succeeds; Apple's macOS wording is device-generic.
- **E5** `kSecAttrAccessGroup` — https://developer.apple.com/documentation/security/ksecattraccessgroup:
  "For an app to access a keychain item, one of the groups to which the app belongs must be the item's
  group. The list of an app's access groups consists of the following string identifiers, in this
  order: The strings in the app's `keychain-access-groups`; The app ID string; The strings in the
  `com.apple.security.application-groups`." … "This attribute applies to macOS keychain items only if
  you also set a value of `true` for the `kSecUseDataProtectionKeychain` key, the
  `kSecAttrSynchronizable` key, or both." *Sharing access to keychain items among a collection of apps*
  (https://developer.apple.com/documentation/security/sharing-access-to-keychain-items-among-a-collection-of-apps):
  "This kind of sharing doesn't require interaction with or permission from the user, but limits sharing
  to apps that are delivered by a single development team." … "Xcode automatically prefixes keychain
  groups with your team ID." Entitlement reference:
  https://developer.apple.com/documentation/bundleresources/entitlements/keychain-access-groups
  ("The identifiers for the keychain groups that the app may share items with… enable the Keychain
  Sharing capability in Xcode").
- **E6** `kSecAttrAccessControl` / `SecAccessControlCreateFlags.userPresence` (macOS 10.10+) —
  https://developer.apple.com/documentation/security/secaccesscontrolcreateflags/userpresence:
  "Constraint to access an item with either biometry or passcode." Deliberately not applied to
  service credentials.
- **E7** `errSecMissingEntitlement` — https://developer.apple.com/documentation/security/errsecmissingentitlement:
  "The `SecItemAdd` method returns this error when you specify an access group to which your app
  doesn't belong." And from E5's article: "If you specify a group to which your app doesn't belong, no
  items match and the query returns the `errSecItemNotFound` status."
- **E8** `SecCodeCopyGuestWithAttributes` — https://developer.apple.com/documentation/security/seccodecopyguestwithattributes(_:_:_:_:)
  (macOS 10.0+): "Asks a code host to identify one of its guests given the type and value of specific
  attributes of the guest code… Pass `NULL` to indicate that the code signing root of trust (currently,
  the system kernel) should be used as the code host." Guest attribute `kSecGuestAttributeAudit`
  (macOS 10.0+) accepts an audit token.
- **E9** PHP sodium — https://www.php.net/manual/en/sodium.installation.php: "Since PHP 7.2.0 the sodium
  extension is bundled with PHP." (`sodium_crypto_secretbox`, `sodium_memzero`).
- **E10** `ASWebAuthenticationSession` — https://developer.apple.com/documentation/authenticationservices/aswebauthenticationsession
  (macOS 10.15+): "In macOS, the system opens the user's default browser if it supports web
  authentication sessions, or Safari otherwise." … "ensures that only the calling app's session receives
  the authentication callback."
- **E11** *Protecting user data with App Sandbox* —
  https://developer.apple.com/documentation/security/protecting-user-data-with-app-sandbox, section
  "Review functionality that is incompatible with App Sandbox": "Certain activities are forbidden by
  the operating system when an app runs in a sandbox… The restricted activities are: Use of
  Authorization Services API. Use of accessibility APIs in assistive apps. Sending Apple Events to
  arbitrary apps. Sending `userInfo` dictionaries in distributed notifications to other tasks. Loading
  kernel extensions. Simulating user input in Open and Save dialogs. Accessing or modifying preferences
  in other apps. Configuring network settings. Terminating other running apps."
- **E12** Peekaboo `docs/permissions.md` (openclaw/Peekaboo, main): "Screen Recording (required) –
  enables CGWindow capture and multi-app automation." "Accessibility (required) – enables window, menu,
  dialog, and action-based element/query automation." "Grant Screen Recording and Accessibility to that
  host process" … "grant permissions to the host reported by `peekaboo permissions status`, not only to
  the shell that launched the CLI." README: MIT licence; distributed as Homebrew CLI, npm MCP package and
  a signed DMG menu-bar app.
- **E13** Apple DTS (Quinn "The Eskimo!"), https://developer.apple.com/forums/thread/805556: "It's not
  possible to use the Accessibility APIs from a sandboxed app." … "`com.apple.security.accessibility`…
  isn't a thing." … "AFAICT your only path forward here is to directly distribute your app using
  Developer ID signing." Corroborated in https://developer.apple.com/forums/thread/749494: "This will
  not work even if the user manually grants it permission through Privacy & Security."
- **E14** Entitlements catalogue — https://developer.apple.com/documentation/bundleresources/entitlements:
  the only entitlement containing "accessibility" is `com.apple.developer.accessibility.merchant-api-control`
  (Tap to Pay), none grants AX control of other apps. *Configuring the macOS App Sandbox*
  (https://developer.apple.com/documentation/xcode/configuring-the-macos-app-sandbox) lists the
  sandbox resource entitlements: network server/client, camera, audio input, USB, printing, Bluetooth,
  contacts, location, calendar, and file access categories — nothing for UI control.
- **E15** *Embedding a command-line tool in a sandboxed app* —
  https://developer.apple.com/documentation/xcode/embedding-a-helper-tool-in-a-sandboxed-app: "The
  tool's entitlements include just `com.apple.security.app-sandbox` and `com.apple.security.inherit`."
  E11: "If your macOS app embeds a command-line tool, that tool must inherit the containing app's
  sandbox configuration."
- **E16** `com.apple.security.network.server` — https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.security.network.server:
  "A Boolean value indicating whether your app may listen for incoming network connections."
- **E17** Apple Platform Security, *Keychain data protection*: "the securityd daemon determines which
  keychain items each process or app can access. Keychain Access APIs result in calls to the daemon,
  which queries the app's Keychain-access-groups, application-identifier, and application-group
  entitlements." … "The prefix requirement and application group uniqueness are enforced through code
  signing, provisioning profiles, and the Apple Developer Program." … "Keychain items are encrypted
  using two different AES-256-GCM keys: a table key (metadata) and a per-row key (secret key)… The
  secret key always requires a round trip through the Secure Enclave."
- **E18** SQLCipher Community Edition licence text, https://www.zetetic.net/sqlcipher/license/SQLCIPHER_COMMUNITY_EDITION_LICENSE.txt:
  "Copyright (c) 2008-2026, ZETETIC, LLC… Redistribution and use in source and binary forms, with or
  without modification, are permitted provided that the following conditions are met…" (three
  BSD clauses: retain notice; reproduce in binary distributions; no endorsement).
- **E19** https://www.zetetic.net/sqlcipher/license/: the Community Edition "is available under a
  BSD-style license and requires attribution and reproduction of license grants in the application
  interface and/or materials… must be a user accessible location."
- **E20** PHP `pdo_sqlite` — https://www.php.net/manual/en/ref.pdo-sqlite.php: "PHP 7.4.0+: Requires
  system/external libsqlite ≥ 3.5.0… The bundled libsqlite was removed as of PHP 7.4.0."
- **E21** SQLCipher design — https://www.zetetic.net/sqlcipher/design/: AES-256-CBC per page, per-page
  random IV, "a Message Authentication Code (HMAC-SHA512) of the ciphertext and the initialization
  vector", "PBKDF2-HMAC-SHA512… 256,000 iterations for key derivation", crypto via "OpenSSL libcrypto,
  LibTomCrypt, and CommonCrypto", build flags `SQLITE_HAS_CODEC` and `SQLITE_TEMP_STORE=2`.
- **E22** static-php-cli extensions — https://static-php.dev/en/guide/extensions.html: lists `sqlite3`
  and `pdo_sqlite`; no SQLCipher or SQLite-encryption option.
- **E23** SQLCipher API — https://www.zetetic.net/sqlcipher/sqlcipher-api/: "PRAGMA key should generally
  be called as the first operation on a database." Raw key form
  `PRAGMA key = "x'2DD29CA8…'";` bypasses key derivation; default "256,000 PBKDF2 iterations"; "the
  majority of the accessible API is identical to the C/C++ interface for SQLite 3." Performance page
  (https://www.zetetic.net/sqlcipher/performance/): "as little as 5-15% overhead"; key derivation causes
  "a substantial delay for the first operation that accesses a database after the connection is opened".
- **E25** Apple Platform Security, *Volume encryption with FileVault in macOS*
  (https://support.apple.com/guide/security/sec4c6dc1b6e/web, published 2026-01-28): "FileVault uses the
  AES-XTS data encryption algorithm to protect full volumes… After a user turns on FileVault on a Mac,
  their credentials are required during the boot process." … "Without valid login credentials or a
  cryptographic recovery key, the internal APFS volumes remain encrypted and are protected from
  unauthorized access even if the physical storage device is removed." The page says nothing about
  per-process protection after login because there is none: once unlocked, the volume key serves every
  process.
- **E26** `URLResourceKey.isExcludedFromBackupKey` (macOS 10.8+) —
  https://developer.apple.com/documentation/foundation/urlresourcekey/isexcludedfrombackupkey: "A key for
  indicating whether the system excludes the resource from all backups of app data… Set this property
  each time you save a file because some common file operations cause this property to reset to `false`."
- **E27** Laravel 13 logging — https://laravel.com/docs/13.x/logging, "Customizing Monolog for Channels":
  "define a `tap` array on the channel's configuration. The `tap` array should contain a list of classes
  that should have an opportunity to customize (or 'tap' into) the Monolog instance after it is created."
- **E28** Laravel 13 context — https://laravel.com/docs/13.x/context, "Hidden Context": "This hidden
  information is not appended to logs, and is not accessible via the data retrieval methods documented
  above."
- **E29** Laravel 13 errors — https://laravel.com/docs/13.x/errors: "In your production environment, the
  value of `APP_DEBUG` should always be `false`. If the value is set to `true` in production, you risk
  exposing sensitive configuration values to your application's end users."
- **E30** Laravel 13 queues — https://laravel.com/docs/13.x/queues: "If your queued job accepts an
  Eloquent model in its constructor, only the identifier for the model will be serialized onto the
  queue." "Encrypted Jobs… add the `ShouldBeEncrypted` interface to the job class… Laravel will
  automatically encrypt your job before pushing it onto a queue."
- **E31** `OSLogPrivacy` (macOS 11+) — https://developer.apple.com/documentation/os/oslogprivacy:
  "Because people have access to log messages that your app generates, use the privacy options to hide
  potentially sensitive information." Example: `Logger().info("…: \(accountNumber, privacy: .private)")`.
- **E32** *Examining the fields in a crash report* —
  https://developer.apple.com/documentation/xcode/examining-the-fields-in-a-crash-report: a report
  contains header, exception information, backtraces per thread, "the CPU registers and their values
  for the crashed thread", and binary images; heap contents are not part of a crash report.
- **E33** Microsoft identity platform, *Refresh tokens* (updated 2026-06-15) —
  https://learn.microsoft.com/en-us/entra/identity-platform/refresh-tokens: "Refresh tokens replace
  themselves with a fresh token upon every use. The Microsoft identity platform doesn't revoke old
  refresh tokens when used to fetch new access tokens. Securely delete the old refresh token after
  acquiring a new one. Refresh tokens need to be stored safely like access tokens or application
  credentials." Default lifetime "90 days for all other scenarios"; "they can be revoked by the sign-in
  service at any time… Your app should handle such revocations gracefully by redirecting the user to an
  interactive sign-in prompt."
- **E34** Google OAuth 2.0 for web server apps — https://developers.google.com/identity/protocols/oauth2/web-server:
  revocation endpoint `https://oauth2.googleapis.com/revoke`; "Your application should store both tokens
  in a secure, long-lived location that is accessible between different invocations of your application."
- **E35** Laravel 13 encryption — https://laravel.com/docs/13.x/encryption, "Gracefully Rotating
  Encryption Keys": "Laravel allows you to list your previous encryption keys in your application's
  `APP_PREVIOUS_KEYS` environment variable… when decrypting values, Laravel will first try the current
  key, and if decryption fails using the current key, Laravel will try all previous keys."

**Measured prototype (E24).** SQLite vs SQLCipher, CLI level.

- Machine: Mac Studio-class Apple M4 Max, 36 GiB, macOS 26.6.2 (25G83), internal SSD, FileVault on.
- Binaries: `/usr/bin/sqlite3` 3.51.0 (Apple) vs Homebrew `sqlcipher` 4.19.0 community (SQLite 3.53.4).
- Workload: WAL, `synchronous=NORMAL`, table `messages(id, thread_id, account_id, subject, body ~1.2 KiB, received_at)`
  with two composite indexes; 200 000 rows inserted in one transaction; 5 000 indexed point lookups;
  one full-table `GROUP BY` aggregate; one bulk update of 10 % of rows; 20 × (open + one query).
- Raw numbers (seconds unless stated; single run, warm cache; variance across three warm-up runs < 5 %):

| Step | sqlite3 | SQLCipher raw key | SQLCipher passphrase |
|---|---|---|---|
| insert 200 k | 1.145 | 2.079 | 2.118 |
| 5 000 point lookups | 0.023 | 0.028 | 0.079 |
| full-table aggregate | 0.289 | 0.983 | 1.025 |
| bulk update 20 k rows | 0.857 | 1.011 | 1.086 |
| open + query, avg of 20 | 4.8 ms | 6.0 ms | 51.7 ms |
| file size | 267 MiB | 267 MiB | 267 MiB |

- Script (kept here so the measurement is reproducible without adding tooling to the repo):

```zsh
KEY="x'2DD29CA851E7B56E4697B0E1F08507293D761A05CE4D1B628663F411A8086D99'"
schema="PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;
CREATE TABLE messages(id INTEGER PRIMARY KEY, thread_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
  subject TEXT NOT NULL, body TEXT NOT NULL, received_at INTEGER NOT NULL);
CREATE INDEX messages_thread ON messages(thread_id, received_at);
CREATE INDEX messages_account ON messages(account_id, received_at);"
insert="BEGIN; WITH RECURSIVE seq(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM seq WHERE i<200000)
INSERT INTO messages(thread_id, account_id, subject, body, received_at)
SELECT (i % 20000), (i % 8), 'Re: invoice ' || i, hex(randomblob(600)), 1700000000 + i FROM seq; COMMIT;"
point="WITH RECURSIVE seq(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM seq WHERE i<5000)
SELECT count(*) FROM seq WHERE (SELECT id FROM messages WHERE thread_id=(seq.i*7)%20000
  ORDER BY received_at DESC LIMIT 1) IS NOT NULL;"
scan="SELECT account_id, count(*), sum(length(body)) FROM messages GROUP BY account_id;"
update="BEGIN; UPDATE messages SET subject = subject || '!' WHERE id % 10 = 0; COMMIT;"
# run each step with:  time <bin> <db> "<prefix> <sql>"   where prefix is "" for sqlite3,
# "PRAGMA key=\"$KEY\";" for the raw-key run and "PRAGMA key='correct horse battery staple';" for the passphrase run.
```

- Limits of the measurement: CLI, not PHP; no FTS5; single machine. It is sufficient for a *no in v1*
  decision; it is **not** sufficient to size a future *yes* — see reopen conditions.

**Legal / licensing input.** None required for v1 because SQLCipher is not adopted. If it is
revisited, the BSD-3 + attribution terms (E18, E19) go to the ADR-007 licence review together with the
chosen crypto backend's licence. Peekaboo is MIT (E12); its permission model, not its licence, is the
constraint here.

## Alternatives considered

| Option | What an attacker with the user's shell gets | 05:00 unattended | Keychain access control | Testability | Why rejected |
|---|---|---|---|---|---|
| **B. Signed CLI `keychain-bridge` invoked by PHP via argv** | **Everything, trivially.** Any same-user process can `exec` the bridge; it cannot tell the real core from `php -r 'exec("keychain-bridge get …")'` run with our own bundled interpreter, and a parent-pid check is defeated the same way. No code tampering needed. Also a process spawn per secret read and `proc_open` inside the security kernel of the app | yes | the CLI would need the restricted entitlement and a bundle to carry its profile — same packaging cost as the Supervisor | easy (fake binary on `PATH`) | turns the Keychain into an unauthenticated local oracle; strictly worse than A |
| **C. PHP-side libsodium store, master key injected at boot** | the master key is in every PHP process's memory and the encrypted blob is on disk; an attacker who can run code in the core has both. Also contradicts §5.6/§11 "Keychain per tjänsteidentitet där möjligt" — it *is* possible | yes | one Keychain item, so per-service access decisions, per-service audit and per-service revocation disappear | best (pure PHP) | fewer Keychain round-trips is not worth losing per-identity custody; retained only as the **developer-build** driver (D3.4) |
| **A. Supervisor broker (chosen)** | an attacker who can execute code *inside* the live core (e.g. by writing to a mutable PHP path) can request anything the core can; an attacker who merely has a shell cannot: the socket is peer-verified against our code signature and process tree, the file on disk holds nothing, and `security`/`ps`/`cat` yield nothing | yes (`AfterFirstUnlock`, user logged in) | one access group, one process; audit per `get`; instant **locked** state | needs a fake broker socket in Laravel tests (`ArraySecretStore` for units; a Swift test double for IPC contract tests) | — |
| File-based login keychain with code-signing ACL (legacy `SecAccess`) | ACL dialogs can be clicked through; `security find-generic-password` prompts the user instead of failing | yes while the login keychain is unlocked, **but** users can set the login keychain to lock on sleep/inactivity, which would break the 05:00 brief silently | per-item trusted-application lists, fragile across updates | ok | kept only as the fallback in D5 if the data protection path fails the S1 prototype |
| Secure Enclave key + `userPresence` for every secret | strongest at rest; useless unattended | **no** — Touch ID or password required per access | excellent | hard | incompatible with §1.3/§5.5 scheduled brief; may return as an opt-in "approval-bound secret" class for `send` credentials (reopen condition) |
| SQLCipher in v1 | same as A for the key; stronger against offline file copies | yes (raw key) | n/a | needs a custom FrankenPHP build in CI | D6 |

## Consequences

Easier:
- One process, one access group, one audit point. The threat model (S0-23) has a single trust boundary
  for secrets: *Supervisor ↔ PHP over the broker socket*.
- Provider adapters (F) get a stable `SecretStore` contract from Sprint 1 and can be tested with
  `ArraySecretStore` and fixtures, no Keychain in CI.
- A12 (Team ID) can land late: only the access-group prefix changes, service names do not.

Harder:
- The Supervisor is now on the critical path for *every* secret read; its availability is the
  product's availability. ADR-001's `KeepAlive` plus bounded restart covers it; the core must degrade
  (queue the job, surface "Secrets unavailable" in health) rather than crash when the socket is absent.
- The Supervisor must be a bundle with a Developer ID provisioning profile (D5), which complicates
  CI signing (S0-05, A12).
- Every new credential-bearing integration must add a row to the D1 inventory and a redaction test
  to D7 before merge.

**Forbidden in code review from now on:**
1. Any column, cache entry, `.env` line, config file, `bootstrap/cache/*`, launchd plist, process
   environment or argv containing a token, password, key, verifier or `APP_KEY`.
2. `SecretValue` in a job, event, DTO sent to a model, Livewire property, API resource, log context,
   exception message or `dd()`.
3. Keychain API calls anywhere except the Supervisor (and the GUI app's own S0-15 pairing item).
4. `kSecAttrSynchronizable = true`; `kSecAttrAccessible` other than `AfterFirstUnlock` for service
   credentials without an ADR amendment.
5. A log channel without the `RedactSecretsProcessor` tap; `privacy: .public` on a non-literal in Swift.
6. Tool schemas with credential-like parameters; tools that accept a provider token from the model.
7. Shell interpolation of any secret; `exec`/`proc_open` with secrets in argv.
8. Writing PHP code into any path the core executes from a mutable directory (compiled views ship
   precompiled in the immutable runtime; `view.compiled` points there; OPcache
   `validate_timestamps=0`) — this is what keeps R1 a *raised bar* instead of a doorstep.

## Residual risks

| # | Risk, in plain language | Mitigation in v1 | Accepted by |
|---|---|---|---|
| R1 | **A same-user attacker who can run code inside the live core gets every secret the core can fetch.** Any mechanism that lets the brief run at 05:00 without a person present can be reused by code that manages to execute as the core. The Keychain protects against disk copies, other users, backups, accidental logging and casual `cat`/`ps` — not against this. | Peer verification on the socket (D3.2); immutable runtime with manifest verification (ADR-001); no executable PHP in mutable paths (Consequence 8); audit of every `get`; **locked** state; hardened runtime on all our binaries so the core cannot be debugged or injected into by a same-user process | H proposes · **Andreas accepts** (§13 signed risk acceptance) |
| R2 | **The SQLite file is readable in clear by every process in the logged-in session.** FileVault only helps when the Mac is off or before login. | No credentials in the file (D1); Time Machine exclusion (E26) plus app-owned encrypted backup (§9); 0700 data directory; `secure_delete` | H proposes · **Andreas accepts**; revisit per reopen condition 3 |
| R3 | Secrets exist in PHP process memory and in the Supervisor's memory for the lifetime of a request/job; swap is encrypted by macOS, core dumps are off by default (`ulimit -c 0`), but a memory-disclosure bug in PHP, FrankenPHP or Caddy could expose them. | `sodium_memzero` on destruct; no long-lived caches; Supervisor zeroes buffers per frame; dependency scanning (§11) | H |
| R4 | `kSecAttrAccessibleAfterFirstUnlock` semantics on macOS are documented device-generically; if the data protection keybag locks with the screen on some configuration, the 05:00 brief fails. | S1 prototype test (E4 obligation); health probe reports "secrets unavailable" and the brief is retried when unlocked, never silently skipped | A + H |
| R5 | The data protection keychain path may require a Developer ID provisioning profile and bundle packaging for the Supervisor (D5); if Apple's rules differ from our reading, the fallback is the legacy keychain with ACLs, which is weaker and can prompt. | Prototype in S1 before any provider integration; fallback documented | A |
| R6 | Keychain items outlive the app if the user drags it to the Trash without running the uninstaller. | Uninstaller step (D8); documented in the installer guide | J |
| R7 | Redaction is pattern- and key-based; a provider that introduces a new token format could pass through until the corpus is updated. Registered live values (D7) catch anything the process fetched, but not values that never passed through `SecretStore` (e.g., a provider-side error echoing a code). | Corpus owned by H, reviewed on every new adapter; `Redactor` also matches high-entropy strings ≥ 32 chars in known header/field positions | H |
| R8 | The Supervisor is a single point of failure for secrets; a crash loop blocks sync and briefs. | ADR-001 `KeepAlive`/backoff; core degrades gracefully; health UI | A + B |

## Blueprint references

§1.3, §2, §2.1, §3.1, §5.5, §5.6, §6, §8, §9, §10, §11, §13, §13.2, §14, §15 (item 3), §16 (step 2).

## Reopen conditions

1. The S1 prototype shows `SecItemCopyMatching` with `kSecUseDataProtectionKeychain` failing from the
   Supervisor at 05:00 with the screen locked (R4), or the Developer-ID helper cannot join an access
   group (R5) → switch to the documented fallback and amend D1/D5.
2. Apple publishes an entitlement or API permitting Accessibility-based UI control from a sandboxed
   process, or ADR-005 moves all UI automation *and* §9 power guidance out of the GUI app → re-evaluate
   sandboxing the GUI app and the Supervisor.
3. A customer/compliance requirement (§10 mapping, ADR-007) demands encrypted data at rest *while
   logged in*, or the measured PHP-level SQLCipher overhead on the §5.6 100 000-mail sync is below 25 % →
   re-evaluate SQLCipher with the `db` key already reserved in D1.
4. Any redaction test in D7 fails in CI, or a secret appears in a diagnostics export, crash log or
   push payload in the field → incident; this ADR's D7 table is amended before the fix is merged.
5. ADR-001 changes the process model (e.g., PHP no longer spawned by the Supervisor) → D3.2 peer
   verification must be redesigned before the change lands.
6. A12 decides the Team ID → replace `<TEAMID>` in D1/D5; no migration of items is needed because
   service names are prefix-independent.
