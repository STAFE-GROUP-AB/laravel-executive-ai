# ADR-005: Peekaboo runs as a pinned, verified child of a separate signed helper; MCP uses the `laravel/mcp` client behind code-enforced trust tiers

| | |
|---|---|
| **Status** | Proposed |
| **Date** | 2026-10-10 |
| **Deciders** | G (MCP/Automation) owner · A (macOS/Swift) for helper signing and IPC · H (Security/privacy) reviewer · Andreas (Owner) for ambiguity A1 and the Apple team identity (A12) |
| **Closes** | Blueprint §15 item 4 (MCP transport half; the SDK half is ADR-004), §15 item 5, OPEN-QUESTIONS A1 |

## Context

Blueprint §0 item 4 mandates "**Peekaboo** för valfri, tillståndsstyrd macOS-GUI-automation (Safari
och andra appar), utan OpenClaw och utan Playwright som obligatoriskt beroende", and §0's
out-of-scope list repeats "Inget Filament, OpenClaw, obligatoriskt Playwright, Browserless".
§6 fixes the non-negotiables: Peekaboo is optional and "ska inte blockera installation eller
CRM/AI-chat"; the agent only gets "ett begränsat `ComputerUseProvider` med explicit app/window
allowlist, user-visible session, per-action authorization, skärmbildsredaktion, timeouts och
panic-stop"; only "den verifierade versionen från definierad releasekälla" may run; and "Försök inte
kringgå macOS TCC, låsskärm eller andra systemsäkerhetskontroller. Inga lösenord eller 2FA-koder
får avläsas eller kringgås automatiskt." §6 also prescribes the helper shape: a separate approved
`launchd` service, "skyddad lokal IPC/Unix socket eller loopback med sessionsbunden token i
Keychain", versioning, origin check and request signing.

§7 fixes the MCP Manager flow "Discover → Assess → Explain → Approve → Install/Connect → Grant tools
→ Audit → Revoke", requires HTTPS/TLS/OAuth/host allowlists for remote servers, says of local stdio
servers "kör aldrig godtyckligt paket direkt på användarens Mac med fulla rättigheter. V1 stöder
endast allowlistad, manuellt granskad lokal MCP-runtime", feature-gates general third-party local
installs "tills riktig process-sandbox verifierats", and declares tool descriptions and MCP
responses untrusted input with "Policybeslut sker i kod, inte i prompt". §11 adds the blocking
gates: capability allowlist, trust tier, isolate untrusted processes, revoke immediately, no
arbitrary shell, SSRF controls, frozen immutable proposals for outbound actions.

Three things force the decision now:

1. **A1.** §0 bans "OpenClaw" while §18 pins Peekaboo at `https://github.com/openclaw/Peekaboo`.
   An agent reading §0 literally refuses the dependency; Sprint 3 Peekaboo work is blocked until
   this is ruled on.
2. **The MCP client side was left open.** `docs/OPEN-QUESTIONS.md` §15.4 recorded (verified
   2026-10-10 against https://laravel.com/ai/mcp) that `laravel/mcp` builds MCP *servers* and "is
   not an MCP client". This ADR's research shows that statement is **out of date for `laravel/mcp`
   v1.0.x**: the 1.x documentation and source contain a full `Laravel\Mcp\Client` with Streamable
   HTTP and stdio transports and an OAuth 2.1 client. The §15.4 note must be corrected, and the
   client decision made explicitly.
3. **S0-23 (threat model) depends on this ADR** for the core ↔ Peekaboo helper and core ↔ MCP
   server boundaries, and Sprint 3's "emergency stop demonstrably stops Peekaboo and MCP
   mid-action" acceptance test needs defined kill semantics.

## Decision

### D1 — Peekaboo trust model

1. **Pinned version and release source.** The only Peekaboo artefact that may execute is the
   standalone CLI from **GitHub Releases of `openclaw/Peekaboo`, tag `v4.9.0`** (source commit
   `7020aedd362038043e4fc1988a8634657270e476`, published 2026-10-07), artefact
   `peekaboo-macos-arm64.tar.gz` on Apple silicon and `peekaboo-macos-x86_64.tar.gz` on Intel. The
   Homebrew tap, the npm package `@steipete/peekaboo` (requires Node.js 22), the `Peekaboo.app`
   DMG, the Peekaboo daemon and any Bridge host are **not** install channels for this product. The
   pin lives in `packaging/peekaboo/manifest.json` (version, tag, commit, per-architecture URL,
   SHA-256, Team ID, signing identifier). Bumping it is a security-critical PR (two reviewers).
2. **Verification at install, fail closed.** The helper (D3) downloads over HTTPS to a private
   staging directory and refuses to install unless **all** of the following hold:
   `sha256(tarball) == manifest.sha256` (v4.9.0 arm64:
   `f56fcf948075aeca875d8c16d012db55f4a3d6b84d2c542fed205eea86699886`, x86_64:
   `c717313f49ee3f4b59f0f65995c100e291695d2cedc7c1d04342a45b7a9b77fd`, both equal to the vendor
   `checksums.txt`); `codesign --verify --strict --check-notarization -R='notarized'` exits 0;
   `codesign -d` reports `Authority=Developer ID Application: OpenClaw Foundation (FWJYW4S8P8)`,
   `TeamIdentifier=FWJYW4S8P8`, `Identifier=boo.peekaboo.peekaboo` and the hardened-runtime flag;
   the installed binary is re-verified against the same requirement **before every launch**.
   Nothing runs with admin privileges; the binary is installed under the user's
   `~/Library/Application Support/<App>/peekaboo/<version>/` with mode `0755`, owned by the user.
3. **Invocation.** Peekaboo is invoked **only** as a short-lived child process of the automation
   helper, by `argv` with `--no-remote` and JSON output, for one action at a time
   (`peekaboo see`, `image`, `window list`, `click`, `type`, `press`, `scroll`). The following are
   **forbidden**: `peekaboo agent` (it has its own model-provider layer, which bypasses ADR-004's
   provider neutrality and `LOCAL_ONLY`), `peekaboo mcp` (its 15 tools would reach the model
   through the generic MCP path instead of `ComputerUseProvider`), `peekaboo daemon`, any Bridge
   socket (`PEEKABOO_BRIDGE_SOCKET`, `daemon.sock`, `bridge.sock`), the `shell` tool, `clipboard`
   writes, and `set_value`/`select_text` on anything but explicitly allowlisted elements. The
   PHP core never spawns Peekaboo; it only talks to the helper (D3).
4. **TCC subject.** Screen Recording, Accessibility and (optionally) Event Synthesizing are granted
   to the **helper**, which is the launchd "responsible process" for the Peekaboo child. Peekaboo's
   own permissions guide confirms the grant goes to the process that launches the CLI, not to the
   CLI file. The helper requests permissions only when the user enables Automation in the PWA or
   menu bar, never during install (§0 lists "Peekaboo-behörigheter" as post-core-ready work).
   `peekaboo permissions status` is used read-only to display state; the app never writes to the
   TCC database or scripts System Settings.
5. **Allowlist.** `browser_sessions.allowlist` is a list of `{bundle_id, window_title_pattern?}`.
   v1 ships with exactly one entry, `com.apple.Safari`, and the UI lets the user add other apps
   one at a time with an explanatory sheet. Hard denylist regardless of user choice: `loginwindow`,
   `SecurityAgent`, `System Settings`, Keychain Access, password managers (1Password, Bitwarden,
   Strongbox, Apple Passwords `com.apple.Passwords`), authenticator apps, Terminal/iTerm, and any
   window whose focused element has role `AXSecureTextField`. Every `observe()`/`execute()` call
   checks the target's bundle id against the allowlist in the helper (Swift) **and** in PHP policy
   (`AutomationPolicy`) — two independent checks, both fail closed.
6. **Per-action authorization.** Every `execute(AuthorizedUiAction)` requires a row in
   `action_proposals` (frozen: app, window title, element role + label, action type, redacted
   value, screenshot hash, expiry, nonce) and a matching `action_approvals` row (approver identity,
   time), per §11 "frozen immutable proposal". `observe()` (screenshot / accessibility tree) is
   covered by the **session grant**, which the user gives when starting a session, is bound to
   the allowlist and expires after at most 15 minutes idle or 60 minutes total. Session grants
   and approvals are never issued from a prompt or a tool result; only from an authenticated,
   re-authenticated (§11) UI action.
7. **Screenshot redaction.** The helper redacts before anything leaves it: regions of
   `AXSecureTextField` elements, the menu bar's Wi-Fi/password menus, and any element whose label
   matches the secret-field heuristic (`password|passcode|otp|2fa|verification code|cvv`) are
   blacked out; non-allowlisted windows overlapping the target are masked using the window list.
   Redacted images are passed to the model provider only while a session is active and are **never
   written to disk, the database, logs, or the audit trail**; the audit event stores a SHA-256 of
   the redacted image for correlation only.
8. **Timeouts.** Per action: 10 s wall clock in the helper (then `SIGTERM`, 2 s, `SIGKILL`); per
   session: 60 min hard, 15 min idle; the model loop gets at most 25 actions per session before
   requiring a fresh grant.
9. **Panic stop.** Three equivalent triggers: the menu-bar "Stop automation" item, a global
   hotkey (default ⌃⌥⌘.), and `POST /automation/sessions/{id}/stop` in the PWA. Stop kills the
   current child process, releases held modifier keys and mouse buttons via the helper, marks the
   session `aborted`, revokes the session grant, and emits `audit_events{type:
   automation.panic_stop}`. Stop must complete within 1 s and works even when the PHP core is
   unresponsive (the menu bar app talks to the helper directly).
10. **Visible session.** The menu bar icon changes to an active-automation state with the target
    app name, and a floating, always-on-top banner "Automation active in Safari — Stop" is shown
    for the duration. Audit logs app bundle id, window title, action type, element role and
    approval id — never typed text, never screen content (§6).

### D2 — macOS session dependence and manual unlock

Mac GUI automation only works inside an unlocked, logged-in Aqua session with a lit display. The
helper gates every session on: console user == app owner, `CGSessionCopyCurrentDictionary()` shows
the session is on console and **not** `CGSSessionScreenIsLocked`, display not asleep. Behaviour per
scenario:

| Scenario | Behaviour | Manual step the user must take |
|---|---|---|
| Display asleep, screen not locked | Helper holds an `IOPMAssertionCreateWithName(kIOPMAssertPreventUserIdleDisplaySleep)` assertion **only while a session is active**, released at stop | None; the assertion is dropped at session end |
| Screen locked / screen saver with password | Session is paused (not failed), banner + notification "Unlock your Mac to continue"; actions time out after 5 min paused → `aborted` | Unlock with password/Touch ID. The app never types the password |
| User logged out / fast-user-switched | launchd agent is not running for that user; session recorded `aborted` on next helper start | Log in again, restart the task |
| FileVault pre-boot / machine off | Nothing runs; scheduled automation is skipped and reported in the brief as "skipped: Mac was not available" | None |
| Multiple displays / Spaces | Target window is brought to front on its current Space; Space switching is **not** performed automatically | Move the window if needed |

The app **never** disables the screen saver, changes lock settings, keeps the session awake outside
an approved session, uses `caffeinate -u`-style user-activity simulation, or sends keystrokes to the
lock screen. Scheduled automation (e.g. a nightly Safari task) is therefore explicitly documented as
"only runs if your Mac is awake and unlocked", and the scheduler never wakes or unlocks the machine.

### D3 — Helper signing and IPC model

1. **Process topology.** Three signed processes from the same bundle, all with the same Developer
   ID team (A12): the SwiftUI menu-bar app; the Laravel core service (ADR-001); and a **separate**
   user-level `launchd` agent `<bundle-prefix>.automation-helper`, registered with
   `SMAppService.agent(plistName:)` from the app bundle (macOS 13+), whose `status` is surfaced in
   the UI (`.requiresApproval` → "Open Login Items settings" button via
   `SMAppService.openSystemSettingsLoginItems()`). Only the helper holds Screen Recording /
   Accessibility and only the helper spawns Peekaboo. The core never gets those permissions; the
   menu-bar app never gets them either.
2. **Signing.** Hardened runtime, Developer ID, notarized, as part of the ADR-001 build pipeline.
   The helper is **not** App-Sandboxed (Accessibility APIs are unavailable to sandboxed processes;
   OPEN-QUESTIONS §15.3b records the same assumption). Entitlements are limited to what Peekaboo
   and the IPC need; no `com.apple.security.cs.disable-library-validation`, no
   `allow-unsigned-executable-memory`.
3. **IPC core ↔ helper.** A Unix domain socket at
   `~/Library/Application Support/<App>/run/automation.sock`, directory mode `0700`, socket
   `0600`. On accept the helper checks `LOCAL_PEERCRED` (uid == own uid, else drop) and obtains the
   peer PID via `LOCAL_PEERPID`, then verifies the peer's code signature with
   `SecCodeCopyGuestWithAttributes` + `SecCodeCheckValidity` against the requirement
   `anchor apple generic and certificate leaf[subject.OU] = "<TEAMID>"` (the bundled PHP binary
   must therefore be signed by our team — a requirement on ADR-001; until it is, the helper falls
   back to uid + token + signature below and logs a warning). TCP loopback is **not** used for this
   path: it offers no peer credentials.
4. **Session-bound token in Keychain.** At start the helper generates a 256-bit token, stores it in
   the Keychain item `<bundle-prefix>.automation.session` (per ADR-003 conventions, ACL limited to
   the helper and the core's signing identity), rotates it on every helper start and on panic stop.
5. **Request signing.** Every request is `{v, id, ts, nonce, method, params}` plus
   `sig = HMAC-SHA256(token, canonical_json(v,id,ts,nonce,method,params))`. The helper rejects
   unknown major `v`, `|now-ts| > 30 s`, replayed nonces (LRU of the last 10 000), and bad
   signatures; every rejection is an `audit_events{type: automation.ipc_rejected}`. Responses are
   signed the same way so the core can detect a hijacked socket.
6. **IPC menu-bar app ↔ helper.** XPC (`NSXPCConnection` to the agent's Mach service) with
   `setCodeSigningRequirement(_:)` (macOS 13+) set to the same team requirement, so the connection
   is invalidated if the peer is not ours. The stop command is available on this path even when the
   core is down.
7. **Versioning.** Protocol version is a major integer in every frame; the helper serves the
   current major and the previous one for one release cycle; the core refuses to start a session
   if `helper.version` is not in its compatible range and offers "Update/repair helper".

### D4 — MCP

1. **Client library: `laravel/mcp` `^1.0` (verified v1.0.1, 2026-09-24).** The `Laravel\Mcp\Client`
   shipped in 1.x provides `Client::web(url)` (Streamable HTTP via the Laravel HTTP client, with
   era detection, `MCP-Protocol-Version`, legacy `Mcp-Session-Id` handling and SSE response
   parsing), `Client::local(command, args, env)` (stdio via `symfony/process` with idle timeout),
   `withToken()`, `withOAuth()` (PKCE `S256` **required** by the implementation, RFC 8707
   `resource` on every request, RFC 9207 `iss` validation with "Possible mix-up attack" rejection,
   Client ID Metadata Documents with RFC 7591 dynamic-registration fallback),
   `withProtocolVersion()` pinning across `2024-11-05 … 2026-07-28`, `onlyTools()/exceptTools()`
   allowlists, `withTimeout()`, `tools()/callTool()/resources()/readResource()/prompts()`. This
   corrects OPEN-QUESTIONS §15.4: **the same package covers both halves of §7**, so no second MCP
   stack is introduced. Pin `laravel/mcp` exactly in `composer.lock`; write contract tests
   (`tests/Integration/Mcp/`) for every API above so an upstream change fails CI, not production.
2. **What we wrap, because the library does not do it.** `HttpTransport` does not enforce `https`,
   does not restrict hosts, and inherits the Laravel HTTP client's default redirect following. The
   `McpConnectionFactory` in `app/MCP` therefore (a) refuses any URL whose scheme is not `https`
   (loopback `http://127.0.0.1` allowed only for our own server), (b) resolves DNS once, rejects
   loopback/RFC1918/link-local (`169.254.0.0/16`, cloud metadata)/`fc00::/7`/`fe80::/10` targets and
   pins the resolved IP for the request (spec SSRF guidance; shared with `Browser/Fetch`, §11),
   (c) sets `allow_redirects => false` on the underlying client and treats any 3xx as an error,
   (d) applies the same validation to every OAuth discovery URL (`resource_metadata`,
   `authorization_servers`, token and authorization endpoints) and rejects non-`https`/`http`
   schemes for authorization URLs (spec: `javascript:`, `data:`, `file:` MUST be rejected), and
   (e) never opens authorization URLs through a shell. Only `McpConnectionFactory` may construct a
   `Client`; direct `Client::web`/`Client::local` calls elsewhere are a review failure.
3. **Transports allowed in v1.**
   - **Streamable HTTP** (spec revisions 2025-03-26 … 2026-07-28), HTTPS only, to hosts the user
     has approved. Preferred protocol version `2026-07-28` with dual-era fallback to legacy
     initialize-based revisions (most published servers are still legacy).
   - **stdio**, only for entries in the local allowlist (item 5) and for our own `Mcp::local`
     servers.
   - **Not supported**: the deprecated HTTP+SSE transport (2024-11-05; "New implementations
     SHOULD NOT adopt it"), custom transports, `http://` to non-loopback hosts, WebSocket.
4. **Trust tiers** (stored in `mcp_servers.trust_tier`, decided in code, never by the model):

   | Tier | What | Default tool policy |
   |---|---|---|
   | T0 | Our own tools exposed via `laravel/mcp` server (item 8) | Read-only tools callable after one-time grant; mutating tools go through action approvals |
   | T1 | Local stdio servers in the reviewed allowlist manifest | Per-tool grant; read-only after human review of the tool list; mutating → action approval |
   | T2 | Remote Streamable HTTP servers on user-approved hosts with OAuth 2.1 or bearer token | Per-tool grant; every tool treated as **mutating and open-world until reviewed**; action approval on every call that sends CRM data |
   | T3 | Everything else (arbitrary `npx`/`uvx`/`docker` installs, unapproved hosts, non-HTTPS) | Blocked; feature-gated behind the compile-time constant `McpFeatures::THIRD_PARTY_LOCAL = false` (not an `.env` switch, not UI-reachable) |

   Tool annotations (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`) are
   recorded but **never** used for policy: the spec says clients "MUST consider tool annotations to
   be untrusted unless they come from trusted servers", and only T0 is trusted.
5. **The allowlisted local runtime for v1.** `config/mcp-local-allowlist.json` is the only source of
   local stdio servers: `{name, version, command (absolute path inside our bundle or
   `~/Library/Application Support/<App>/mcp/`), args, sha256 of the executable, reviewed_by,
   reviewed_at, declared_egress, declared_fs_paths}`. The helper or core verifies the SHA-256
   before each spawn, runs the process with a minimal environment (no inherited `PATH`, `HOME`
   pointed at a per-server directory, no Keychain access, no inherited tokens), closes stdin on
   revoke, and never passes shell strings (`symfony/process` array form only; "no arbitrary
   shell", §11). **v1 ships this list empty of third-party servers.** Adding an entry is a
   security-critical PR: manual source review, pinned release, reproducible hash, documented OS
   permissions. The general one-click install of registry packages stays T3 until a process
   sandbox is verified (reopen condition below). Peekaboo is deliberately **not** on this list
   (D1.3).
6. **Discovery → Assess → Explain → Approve → Install/Connect → Grant → Audit → Revoke.**
   Discovery queries the official registry REST API at `https://registry.modelcontextprotocol.io`
   (`GET /v0/servers`, API in **preview**, "API freeze (v0.1)" announced, "breaking changes or data
   resets may occur") plus user-configured sources; each `server.json` is shown with name,
   namespace verification (GitHub login or DNS/HTTP domain proof), version, `packages[]`
   (`npm`, `pypi`, `oci`, `nuget`, `mcpb`) and `remotes[]` (`streamable-http`, `sse`). Assess is
   mechanical: tier classification, transport, OAuth metadata, declared scopes, license field,
   whether the publisher is verified; a registry listing is treated as a catalogue entry, **not** a
   security review. Explain shows the exact command or URL "without truncation" (spec local-server
   consent requirement) and the data each tool could receive. Approve requires re-auth (§11).
   Connect fetches `tools/list`, stores `mcp_tools.schema_hash = sha256(canonical tools JSON)` and
   pins `mcp_servers.version`; if a later `tools/list` differs (description, schema or new tool),
   all grants for that server are suspended until the user re-reviews (rug-pull protection). Grant
   is per tool into `mcp_grants`. Audit writes every call with server, tool, argument hash,
   outcome, duration — never raw CRM content. Revoke (item 7).
7. **Kill and revoke semantics.** `Revoke` = delete `mcp_grants` → mark server `revoked` →
   disconnect: HTTP: drop the client and, where the authorization server advertises a revocation
   endpoint, revoke the token, then delete it from the Keychain; stdio: close stdin, wait 2 s,
   `SIGTERM`, 2 s, `SIGKILL`, verify the PID is gone → `audit_events{type: mcp.revoked}`. The same
   panic stop as D1.9 kills all running MCP child processes and cancels in-flight HTTP requests.
   Any tool call arriving for a revoked server fails closed with no model-visible content.
8. **Tool poisoning and prompt injection.** Tool names, titles, descriptions, result text and
   resource contents are stored and rendered as **data**: they are never inserted into the system
   prompt, never allowed to change the tool list the model sees, and are passed to the model inside
   a delimited "untrusted tool output" block with the server name. Results above 64 KiB are
   truncated; `resource_link`/embedded `file://` URIs are not auto-fetched. All policy (allowlist,
   tier, grant, approval, egress, kill) is implemented in PHP policies/gates and Swift code paths,
   never in prompt text (§7).
9. **Egress controls.** `LOCAL_ONLY` (ADR-004) disables T2 entirely and blocks registry discovery.
   Otherwise outbound MCP traffic is permitted only to the approved host set, with the SSRF guard
   from item 2. For local stdio servers macOS offers no per-process network filter without a
   sandbox; this is exactly why T3 stays gated and why each T1 entry must declare its egress.
10. **Our own tools via `laravel/mcp` server.** `Mcp::web('/mcp/executive', ExecutiveServer::class)
    ->middleware(['auth:sanctum', 'throttle:mcp'])` on the loopback origin (ADR-006 governs remote
    exposure; the MCP route is **not** part of the Tailscale Serve surface in v1) and
    `Mcp::local('executive', ExecutiveServer::class)` for local editors. v1 tools: `crm.search`,
    `tasks.list`, `brief.read`, each annotated `#[IsReadOnly]`, each calling `$request->user()->can()`
    against the existing policies (§11: policies in every MCP tool), each rate-limited. No mutating
    tool ships in v1; when one does, it creates an `action_proposals` row and returns "pending
    approval" instead of acting. Authentication is a Sanctum personal access token with abilities,
    issued and revocable in the PWA; `Mcp::oauthRoutes()` (Passport) is deferred until a remote
    client needs it. The server validates `Origin` (spec MUST) and binds to `127.0.0.1` only.

### D5 — No bypass; residual risks are accepted, not hidden

The product **does not and will not** attempt to: write to or spoof the TCC database, grant itself
permissions, script System Settings, simulate user presence to defeat the lock screen or screen
saver, read or type passwords, read or type one-time codes or 2FA prompts (from screen, clipboard,
Messages, or mail), interact with `SecurityAgent`/`loginwindow`, disable Gatekeeper, or run any
unsigned download. Residual risks after all controls are listed below with the accepting role.

## Evidence

**An ADR without this section filled cannot move to Accepted.** Status stays **Proposed** until the
two measured prototypes marked *pending* exist.

### Measured (this ADR, 2026-10-10, Apple silicon, macOS Darwin 25.6.0)

- Downloaded `peekaboo-macos-arm64.tar.gz` and `checksums.txt` from
  `https://github.com/openclaw/Peekaboo/releases/download/v4.9.0/`. Local
  `shasum -a 256` = `f56fcf948075aeca875d8c16d012db55f4a3d6b84d2c542fed205eea86699886`, identical
  to `checksums.txt` and to the Homebrew formula's `sha256`.
- `codesign -dvv` on the extracted `peekaboo` binary: `Identifier=boo.peekaboo.peekaboo`,
  `Format=Mach-O thin (arm64)`, `flags=0x10000(runtime)`,
  `Authority=Developer ID Application: OpenClaw Foundation (FWJYW4S8P8)`,
  `TeamIdentifier=FWJYW4S8P8`, `Timestamp=7 Oct 2026 at 14:04:43`, `Runtime Version=15.0.0`.
- `codesign --verify --strict --check-notarization -R='notarized'` → exit 0 (notarization ticket
  accepted). `spctl -a -t execute` reports `origin=Developer ID Application: OpenClaw Foundation
  (FWJYW4S8P8)`; its "does not seem to be an app" rejection is expected for a bare CLI and is why
  D1.2 uses `codesign` rather than `spctl` as the gate.
- `peekaboo --version` → `Peekaboo 4.9.0 (release/4.9.0/7020aedd3, built: 2026-10-07T04:57:43-07:00)`.
- Script and outputs: session scratchpad `verify-peekaboo.sh`; to be committed as
  `packaging/peekaboo/verify.sh` in Sprint 3 (S3 Peekaboo work), with the manifest.

### Pending prototypes (block Accepted)

1. **Helper TCC attribution.** A signed launchd agent spawning `peekaboo see --no-remote` must
   produce the Screen Recording / Accessibility prompt **for the helper**, and the grant must
   survive replacing the Peekaboo binary with the next pinned version. Artefact:
   `apps/macos/AutomationHelper/` spike + screenshot of the TCC prompt + `tccutil`-free evidence
   (System Settings screenshot).
2. **Panic stop under load.** Stop during a `type` action must complete < 1 s and leave no stuck
   modifier keys; measured on the ADR-001 reference machine profile.

### Vendor documentation

- **Peekaboo repository** https://github.com/openclaw/Peekaboo (fetched 2026-10-10; default branch
  `main`, pushed 2026-10-10; 5 275 stars; license MIT, `Copyright (c) 2025 Peter Steinberger`):
  "Peekaboo is a macOS CLI & optional MCP server that enables AI agents to capture screenshots of
  applications, or the entire system, with optional visual question answering through local or
  remote AI models." "The released CLI and app require macOS 15 or later." "The npm package
  requires Node.js 22 or later and includes the CLI plus its MCP launcher." `Package.swift`:
  `platforms: [.macOS(.v14)]`, library products `PeekabooFoundation`, `PeekabooProtocols`,
  `PeekabooAutomationKit`, `PeekabooBridge`.
- **Peekaboo `docs/permissions.md`** (main, 2026-10-10): Screen Recording "CGWindow capture and
  multi-app automation"; Accessibility "window, menu, dialog, and action-based element/query
  automation"; optional **Event Synthesizing** for "background keyboard input … and explicitly
  foreground synthetic pointer input"; "Peekaboo does not require Apple Events Automation
  permission"; grant permissions to the "Terminal/shell running peekaboo" / "the host reported by
  `peekaboo permissions status`, not only to the shell that launched the CLI".
- **Peekaboo `docs/MCP.md`**: server started with `peekaboo mcp` (`peekaboo mcp serve --transport
  stdio`); "`stdio`: supported and default", HTTP/SSE "recognized but not yet implemented"; tools
  `image, see, click, scroll, type, press, set_value, select_text, action, app, window, menu,
  analyze, clipboard, sleep`.
- **Peekaboo `docs/bridge-host.md`**: Bridge is "a socket-based broker for permission-bound
  operations" over UNIX-domain sockets; the host "Validates the peer's code signature TeamID via
  Security.framework (`SecCodeCopyGuestWithAttributes`). Rejects any process not signed by an
  allowlisted TeamID (default: `FWJYW4S8P8` …)"; debug escape hatch
  `PEEKABOO_ALLOW_UNSIGNED_SOCKET_CLIENTS=1`; third-party hosting via `import PeekabooBridge` /
  `PeekabooEmbeddedBridgeRuntime.make(configuration: .init(socketPath:, allowlistedTeams:,
  allowlistedBundles:))`.
- **Peekaboo `docs/RELEASING.md`**: signing identity "Developer ID Application: OpenClaw Foundation
  (FWJYW4S8P8)"; release verification "codesign --verify --strict --check-notarization
  -R=notarized" for CLI, app and DMG; Sparkle EdDSA appcast signing; npm publish via GitHub OIDC
  trusted publisher. `docs/install.md`: "The CLI is signed, notarized, and lives in
  openclaw/homebrew-tap"; "The app and CLI are separate installs". Release `v4.9.0` assets:
  `Peekaboo-4.9.0.app.zip`, `Peekaboo-4.9.0.dmg`, `peekaboo-macos-{arm64,x86_64,universal}.tar.gz`,
  `steipete-peekaboo-4.9.0.tgz`, `checksums.txt` (SHA-256), `appcast.xml`, `release-proof.md`.
- **Peekaboo `docs/security.md`**: "normal Agent toolsets omit `shell`, and the execution boundary
  refuses it under both Agent policies"; argument validation "runs before execution authority is
  evaluated". The document contains **no** statements about lock screen, secure text fields or
  password handling — those controls are ours (D1.5, D1.7).
- **OpenClaw** https://docs.openclaw.ai/ (2026-10-10): OpenClaw is "a self-hosted gateway that
  connects your favorite chat apps — Discord, Google Chat, iMessage, Matrix, Microsoft Teams,
  Signal, Slack, Telegram, WhatsApp, Zalo, and more via channel plugins — to AI coding agents",
  "developed in the open by the OpenClaw Foundation, an independent 501(c)(3)", "MIT licensed".
  GitHub org `openclaw`: "Your personal, open source AI assistant." (96 public repos).
  https://docs.openclaw.ai/platforms/mac/peekaboo: "OpenClaw can host **PeekabooBridge** as a
  local, permission-aware UI automation broker (`PeekabooBridgeHostCoordinator`, backed by the
  `openclaw/Peekaboo` Swift package)." — i.e. OpenClaw is an agent/gateway *product* that consumes
  Peekaboo; Peekaboo is a library/CLI that predates the org move (npm scope `@steipete`,
  copyright Peter Steinberger).
- **MCP specification, revision 2026-07-28** (https://modelcontextprotocol.io/specification/latest,
  fetched 2026-10-10): transports are "stdio" and "Streamable HTTP"; "Removal of the GET stream
  endpoint. Removal of protocol-level sessions." "Servers **MUST** validate the `Origin` header on
  all incoming connections to prevent DNS rebinding attacks." "When running locally, servers
  **SHOULD** bind only to localhost (127.0.0.1)". HTTP+SSE (2024-11-05) "has been deprecated since
  protocol version `2025-03-26` … New implementations **SHOULD NOT** adopt it". Authorization:
  "Implementations using an STDIO transport **SHOULD NOT** follow this specification, and instead
  retrieve credentials from the environment"; "Authorization servers **MUST** implement OAuth 2.1";
  "MCP servers **MUST** implement OAuth 2.0 Protected Resource Metadata (RFC9728)"; "MCP clients
  **MUST** implement Resource Indicators for OAuth 2.0 as defined in RFC 8707"; "Access tokens
  **MUST NOT** be included in the URI query string"; "MCP servers **MUST NOT** accept or transit any
  other tokens." Tools: "there **SHOULD** always be a human in the loop with the ability to deny
  tool invocations"; "clients **MUST** consider tool annotations to be untrusted unless they come
  from trusted servers"; clients SHOULD "Show tool inputs to the user before calling the server",
  "Implement timeouts for tool calls", "Log tool usage for audit purposes". Security best
  practices (`/specification/latest/basic/security_best_practices`): local servers — the client
  "**MUST**: Show the exact command that will be executed, without truncation", "Execute MCP server
  commands in a sandboxed environment with minimal default privileges"; SSRF — "Reject `http://`
  URLs except for loopback addresses", block `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`,
  `127.0.0.0/8`, `169.254.0.0/16`, `fc00::/7`, `fe80::/10`, "Consider pinning DNS resolution results
  between check and use"; OAuth URLs — "**MUST** reject `javascript:`, `data:`, `file:`,
  `vbscript:`", "**MUST NOT** use shell commands … to open URLs".
- **Official MCP Registry** https://github.com/modelcontextprotocol/registry (README, 2026-10-10):
  "The registry has launched in preview"; "this is still a preview release and breaking changes or
  data resets may occur"; "The Registry API has entered an API freeze (v0.1)"; live API
  `https://registry.modelcontextprotocol.io/v0/servers` returns `{servers[], metadata}` with
  `remotes[].type = "streamable-http"` entries; `server.json` package registries `npm, pypi, oci,
  nuget, mcpb`; namespace proof via GitHub login or DNS/HTTP verification.
- **`laravel/mcp`** https://laravel.com/framework/docs/mcp.md and
  https://github.com/laravel/mcp (v1.0.1 released 2026-09-24; `composer.json`: `php ^8.2`,
  `illuminate/support ^11.45.3|^12.41.1|^13.0`, `symfony/process ^7.4.5|^8.0.5`; MIT). Docs:
  "You may connect to an HTTP-accessible MCP server using the `Client::web` method"; "To connect to
  a local MCP server that runs as a command, use the `Client::local` method"; "The authorization
  server must advertise support for the `S256` PKCE code challenge method … Laravel will reject the
  authorization attempt if PKCE support is not advertised"; "Laravel will use a Client ID Metadata
  Document when the authorization server supports them, falling back to dynamic client
  registration". Source (`main`, 2026-10-09): `src/Client.php` — `public static function
  local(string $command, array $args = [], array $env = []): static`, `public static function
  web(string $url): WebClient`, `withTimeout(float)`, `onlyTools(array)`, `exceptTools(array)`,
  `withProtocolVersion(?ProtocolVersion)`, `tools()`, `callTool(Tool|string, array): ToolResult`,
  `readResource()`, `getPrompt()`; `src/Client/Transport/{HttpTransport,StdioTransport}.php`
  (`Accept: application/json, text/event-stream`, SSE reader, `captureSessionId` for legacy
  revisions, `new Process([$this->command, ...$this->args], env: $this->env)`, idle timeout);
  `src/Enums/ProtocolVersion.php` cases `2026-07-28, 2025-11-25, 2025-06-18, 2025-03-26,
  2024-11-05`; `src/Client/OAuth/OAuthClient.php` — `'code_challenge_method' => 'S256'`,
  `'resource' => $this->resourceUrl`, `validateIssuer()` throwing "The OAuth issuer (iss) parameter
  did not match the expected issuer. Possible mix-up attack." Server side (https://laravel.com/ai/mcp):
  "Built-in OAuth 2.1 authentication via Laravel Sanctum", "Web server — Serve over HTTP with SSE
  streaming", "Local server — Run as a stdio process", `Mcp::web()`, `Mcp::local()`,
  `php artisan mcp:start`, `php artisan mcp:inspector`, annotations `IsReadOnly`, `IsDestructive`,
  `IsIdempotent`, `IsOpenWorld`.
- **Alternatives surveyed (Packagist, 2026-10-10):** `mcp/sdk` (official PHP SDK, PHP Foundation +
  Symfony) v0.8.1 2026-08-29, "Experimental until 1.0", Apache-2.0 for new code / MIT legacy, PHP
  8.1+, client with "STDIO and Streamable HTTP", 4.6 M installs; `logiscape/mcp-sdk-php` v2.0.1
  2026-08-18, MIT, PHP 8.1+, client + server, curl-based, dual-era `2024-11-05 … 2026-07-28`,
  215 k installs; `php-mcp/client` v1.0.1 2025-05-06, MIT, PHP 8.1+, ReactPHP, transports
  "stdio" and "HTTP POST and Server-Sent Events (SSE)" only (the deprecated transport), 47 k
  installs.
- **Apple**: `SMAppService` (https://developer.apple.com/documentation/servicemanagement/smappservice)
  — register "login items, launch agents, and launch daemons directly from your app bundle",
  macOS 13.0+, `agent(plistName:)`, `register()/unregister()`, `status` ∈ `{notRegistered, enabled,
  requiresApproval, notFound}`, `openSystemSettingsLoginItems()`.
  `NSXPCConnection.setCodeSigningRequirement(_:)` — macOS 13.0+, "If the peer does not meet the
  specified code signing requirement, the connection will be invalidated." Apple Platform Security
  guide (https://support.apple.com/guide/security/protecting-app-access-to-user-data-secddd1d86a6/web):
  accessibility and automation "require user permission to help ensure that they don't circumvent
  other protections"; grants are made through prompts or System Settings, never by the app.

### Legal / licensing input

Peekaboo (MIT), `laravel/mcp` (MIT) and `symfony/process` (MIT) are MIT-compatible. Whether a
Peekaboo **binary** may be downloaded at runtime (not redistributed in the DMG) changes nothing
legally but is recorded for ADR-007's inventory. ADR-007 owns the legal review; engineers do not
accept legal risk here.

## Alternatives considered

**A1 reading and Peekaboo sourcing** (the ADR survives either answer):

1. **Chosen, PENDING ANDREAS CONFIRMATION:** read §0's "OpenClaw" ban as targeting the OpenClaw
   agent/gateway *product* and its Bridge hosting, not the Peekaboo library that lives in the same
   GitHub org. Evidence: §6 and §18 clearly intend Peekaboo; OpenClaw's own docs describe Peekaboo
   as a Swift package OpenClaw *consumes*; Peekaboo predates the org (npm scope `@steipete`,
   copyright Peter Steinberger). Under this reading nothing OpenClaw-branded is installed,
   referenced in UI, or connected to: no OpenClaw gateway, no Bridge host, no `Clawdbot.app`
   fallback sockets (D1.3 forbids all bridge sockets). The signing identity of the binary is
   "OpenClaw Foundation" — this is disclosed in the install sheet and in ADR-007's inventory.
2. **If Andreas rules that the org itself is banned:** fork `openclaw/Peekaboo@7020aedd` into
   `STAFE-GROUP-AB/peekaboo` (MIT permits it), build the CLI in our CI, sign it with our Developer
   ID, and point `packaging/peekaboo/manifest.json` at our GitHub Release. D1.2's checks change
   only in Team ID and identifier; D1.3–D1.10, D2 and D3 are unchanged. Cost: we own security
   patches and a rebuild per upstream release. Alternatively link the SwiftPM product
   `PeekabooAutomationKit` (platform `.macOS(.v14)`) directly into the helper at a pinned commit —
   no runtime download at all, but a larger TCC-holding binary and compiled-in third-party code.
3. **If Peekaboo is dropped entirely:** implement `ComputerUseProvider` on Apple's Accessibility
   (`AXUIElement`), `ScreenCaptureKit` and `CGEvent` APIs directly in the helper (Peekaboo's own
   `AXorcist` dependency shows the shape). Ruled out for v1 only by effort: several engineer-months
   to reach Peekaboo's element-targeting quality, which would push automation past v1.

**Peekaboo integration shape:**

- *Peekaboo as an MCP server (`peekaboo mcp`) consumed by our MCP client* — ruled out: its 15 tools
  would reach the model through the generic MCP path, bypassing `ComputerUseProvider`'s allowlist,
  per-action approval and redaction; `clipboard` and `set_value` would be model-reachable.
- *`peekaboo agent`* — ruled out: carries its own model-provider configuration, violating ADR-004
  neutrality and `LOCAL_ONLY`.
- *Our helper hosting `PeekabooBridge` and the CLI connecting as a client* — deferred: it requires
  allowlisting the OpenClaw Team ID `FWJYW4S8P8` as an authorized *caller* of our broker and adds a
  second socket; the child-process model gives the same single-TCC-subject property with less
  surface. Reconsider if the prototype shows TCC is not attributed to the helper.
- *Playwright/Browserless* — banned as mandatory by §0.

**MCP client:**

- *Official `mcp/sdk`* — ruled out for v1: "Experimental until 1.0" with breaking changes in 0.6.0
  and 0.8.0, and it would be a second MCP stack beside the `laravel/mcp` server we need anyway.
  Strong candidate to revisit at 1.0 if `laravel/mcp`'s client stalls.
- *`logiscape/mcp-sdk-php` v2* — ruled out: mature and dual-era, but a single-vendor dependency
  overlapping `laravel/mcp`; no Laravel-native OAuth account-linking routes.
- *`php-mcp/client`* — ruled out: last release 2025-05, only the deprecated HTTP+SSE transport.
- *Swift sidecar MCP client (official swift-sdk) with a loopback bridge to PHP* — ruled out for v1:
  it moves policy decisions across another IPC boundary and duplicates grant/audit code in two
  languages. Kept as the design for **T1/T3 sandboxing**: if a process sandbox is ever needed for
  local servers, the helper (already a privileged broker) is where spawning belongs.

**Helper IPC:**

- *XPC only* — PHP cannot speak XPC; used only menu-bar ↔ helper.
- *TCP loopback* — any local process can connect and there are no peer credentials; rejected for
  core ↔ helper (allowed only for the Laravel HTTP origin per ADR-006).

## Consequences

Easier: one MCP dependency for both directions; one TCC subject (the helper); Peekaboo can be
updated by changing one manifest and re-running the same verification; S0-23 gets three
well-defined boundaries (core ↔ helper socket, helper ↔ Peekaboo child, core ↔ MCP server).

Harder: every Peekaboo bump is a two-reviewer PR with a re-run of `verify.sh`; users on Intel
Macs get a separate artefact; remote MCP servers that only speak HTTP+SSE (2024-11-05) cannot be
connected; local third-party MCP servers are unavailable in v1 — the UI must say so honestly.

**Forbidden in code review from now on:**

- Any `Client::web`/`Client::local` outside `app/MCP/McpConnectionFactory`; any `http://` MCP URL
  to a non-loopback host; any redirect following on MCP or OAuth requests.
- Spawning `peekaboo` from PHP, or with `agent`, `mcp`, `daemon`, bridge flags or env, or without
  `--no-remote`; any Peekaboo install path other than `packaging/peekaboo/manifest.json`.
- Policy decisions derived from tool annotations, descriptions, results, screenshots or prompts.
- Persisting screenshots or typed text; logging tokens or screen content; `TRUNCATE`/writes to TCC.
- `.env` toggles that enable T3; shell-string process spawning; `PEEKABOO_ALLOW_UNSIGNED_SOCKET_CLIENTS`.
- Requesting Screen Recording/Accessibility during install or from the core/menu-bar processes.
- Any code that keeps the display awake, suppresses the screen saver or sends input while the
  session is locked.

## Residual risks

| Risk | Why it remains | Accepting role |
|---|---|---|
| Peekaboo runs with the helper's Accessibility and Screen Recording rights; a vulnerability in the pinned binary could read any on-screen content while a session is active | macOS has no finer-grained AX scope; the allowlist is enforced by *our* code around a privileged child | H, with Andreas sign-off (A12 identity owner) |
| Supply-chain compromise of a future Peekaboo release before our review | We pin and verify, but we trust the upstream build at review time | H (per-bump review), J for license inventory |
| Prompt injection via page content steering the agent inside the allowlisted Safari window (e.g. clicking a malicious link) | Per-action approval limits blast radius but cannot judge intent; SSRF/egress controls do not apply to the user's own browser | H; user is told in the first-run screen |
| A local malicious process with the same uid could reach `automation.sock`; mitigated by peer code-signature check + HMAC token, defeated only by a process that already has Keychain access to the token | macOS user-level isolation limit | H |
| Local stdio MCP servers (T1) run unsandboxed with user privileges; v1 ships none | No verified process sandbox on macOS without App Sandbox entitlement rework | G + H; gate stays until reopen condition met |
| Remote MCP servers receive whatever arguments the model sends after approval; data minimisation depends on review quality | Inherent to tool use | H; per-tool review recorded in `mcp_grants` |
| Registry is preview-stage; data resets or breaking changes may break discovery | Vendor status | G (operational, not security) |
| `laravel/mcp` 1.x client is new (first 1.0 on 2026-09-14); defects possible | Mitigated by contract tests and the ability to swap to `mcp/sdk` at 1.0 behind `McpConnectionFactory` | G |
| Automation silently never runs when the Mac is locked/asleep | By design (D2); perceived as unreliability | Product (Andreas) |

## Blueprint references

§0 (item 4, out-of-scope), §3 (`ComputerUseProvider`), §3.1, §6, §7, §8, §11, §12.1, §13.2, §14,
§15 (items 3, 4, 5), §16 (step 2, step 9), §17, §18.

## Reopen conditions

- Andreas rules on A1 (either answer changes only D1.1–D1.2 and the Evidence identity lines).
- Pending prototype 1 shows TCC is **not** attributed to the helper for the Peekaboo child → switch
  to the `PeekabooBridge`-host or SwiftPM-embedding alternative.
- Peekaboo publishes a release that changes signing identity, drops notarization, or requires
  macOS > the ADR-001 support matrix.
- `laravel/mcp` removes or breaks the `Client` API, or `mcp/sdk` reaches 1.0 with OAuth
  account-linking parity → re-evaluate the client choice.
- A verified process sandbox for local MCP servers exists (e.g. a tested App-Sandbox-style profile
  or Apple's Containerization framework on the supported macOS range) → revisit T3 gating.
- MCP revision after 2026-07-28 removes dual-era fallback or changes the authorization profile.
- The official registry leaves preview or publishes a vetting/moderation policy → update the
  Assess step.
