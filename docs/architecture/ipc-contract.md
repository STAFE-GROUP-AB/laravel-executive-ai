# Swift ↔ Laravel IPC contract (S0-15)

**Status:** Frozen at contract version **1.1.0** (wire major `1`) — see §15 for what the minor added · **Owners:** Workstream A (Swift client) and B (Laravel server); H reviews
**Schema file:** [`packaging/fixtures/ipc.schema.json`](../../packaging/fixtures/ipc.schema.json) (JSON Schema draft 2020-12)
**Examples:** [`packaging/fixtures/ipc/`](../../packaging/fixtures/ipc/) — one recorded exchange per operation plus the error cases
**Validation:** `node packaging/fixtures/validate-schema.mjs` (CI step, S0-05) — also recomputes every fixture signature with the test key (§4.6)
**Implements:** Blueprint §2 ("localhost authenticated IPC / loopback HTTPS"), §6 (versioning, origin check, request signing, session-bound token in Keychain), §11 (no bypass of server-side policy), §1.1 (what the menu bar needs)
**Binding inputs:** ADR-001 D3/D4, ADR-003 D1/D3/D4/D7/D8, ADR-005 D1.9/D3, ADR-006 D3/D6, [health-contract.md](health-contract.md) §6, [brief-schema.md](brief-schema.md) §4/§6

Two parties implement opposite ends of this contract in Sprint 1 (S1-08): the SwiftUI menu-bar app
(`apps/macos/LaravelExecutiveAI/IPC/`) and the Laravel core (`app/Support/Health/IpcController.php` + middleware
under `app/Security/Authorization/`). It is specified once, here, so neither side improvises. Nothing
in this document is drop-in code.

## 0. Scope: three channels, this document owns one

The Mac side has three local IPC channels. They are deliberately separate, with different peers,
different transports and different trust properties. Conflating them is a review failure.

| Channel | Peers | Transport | Owner |
|---|---|---|---|
| **Menu bar → core** (this contract) | GUI app (`LaravelExecutiveAI.app`) → Laravel core HTTP | loopback HTTP, `/ipc/v1/…`, signed requests, session token from the Keychain | **S0-15** |
| Secrets broker | PHP children → Supervisor | Unix socket `run/secrets.sock`, peer-verified, no token | ADR-003 D3 — **not** reachable by the GUI, never carries menu-bar traffic |
| Automation helper | core → helper (Unix socket `run/automation.sock`), GUI → helper (XPC) | HMAC-signed frames / `NSXPCConnection` with code-signing requirement | ADR-005 D3 |
| GUI → Supervisor control (§7) | GUI app → Supervisor | XPC, code-signing requirement | **S0-15 defines the surface**; it is the pairing, elevation and service-control path |

Panic stop of automation (ADR-005 D1.9) must work when the core is down; it therefore goes GUI → helper
over XPC and is **not** an operation of this contract. "Pause AI actions" (§1.1) is a different,
cooperative action that *is* (§6.4).

## 1. Transport

### 1.1 Loopback HTTP on the core's fixed port

- Every operation is an HTTP/1.1 request to `http://127.0.0.1:<port>/ipc/v1/<operation>`. `<port>` is
  the fixed loopback port chosen at preflight (ADR-001 D3); the client learns it from the pairing grant
  (§3.2), never from a guess or a scan. `8787` is the placeholder used in this document and in the
  fixtures, as in ADR-006.
- The core binds **`127.0.0.1` only** (ADR-001 D3 Caddyfile `bind 127.0.0.1`; ADR-006 D6.5 "the
  listener bind address is a constant, not configuration"). It does not bind `::1` in v1; the client
  must use the literal `127.0.0.1`, never `localhost`, so that resolver configuration cannot redirect it.
- **Why nothing is reachable off-host.** The socket is bound to the loopback interface, which the
  kernel never routes to or from a physical interface; a packet from another host cannot carry a
  `127.0.0.1` destination through a NIC. The only other path onto the port is the Tailscale Serve proxy
  (ADR-006), and §5 rejects every request that arrives through it. There is no TLS on this hop
  (ADR-001 D3 "no TLS on loopback") because the bytes never leave the kernel; the §2 phrase "loopback
  HTTPS" refers to the Serve origin, not to this channel.
- No request or response may exceed **64 KiB** of body except `GET /ipc/v1/briefs/latest`
  (1 MiB, the brief schema's practical ceiling). Larger bodies are rejected with `413` / `payload_too_large`.

### 1.2 Why not a Unix socket for this channel — and what that costs

ADR-005 chose a Unix socket for core ↔ helper precisely because TCP loopback "offers no peer
credentials". That trade-off is real and it applies here too, so it is stated rather than hidden:

| | Unix domain socket | TCP loopback (chosen) |
|---|---|---|
| Who can connect | processes that can open the path (`0700` dir) **and** whose `LOCAL_PEERCRED`/audit token the server can verify | **any process running as the same user** |
| Peer identity | kernel-provided, verifiable against our code signature | none at the transport layer |
| Fits the core | PHP behind Caddy never sees the peer credential; the Caddy listener would have to be duplicated on a socket, and every PHP request would still arrive without `LOCAL_PEERCRED` | the core **is already** a loopback HTTP server (ADR-001); the Supervisor already probes it over HTTP; one listener, one middleware stack, one set of tests |
| Port hijack | n/a | if the core is down, any same-user process can bind `<port>` and impersonate it |

The core is an HTTP server by construction and PHP cannot read a peer credential through Caddy, so a
Unix socket would buy nothing the server could check. Instead the missing transport-layer guarantees
are reconstructed at the application layer, and these are **mandatory**, not defence in depth:

1. **Client authentication** by a session-bound token whose key lives only in the Supervisor's
   Keychain item and the core's memory (§3).
2. **Request signing** so that a request is bound to one session, one method, one path, one body and
   one moment in time (§4).
3. **Response signing** so that the client detects a hijacked port and shows *core unreachable*
   instead of a forged brief (§4.5). This is the compensating control for the last row above.

Reopen condition: if FrankenPHP exposes verified peer credentials to PHP for Unix-socket listeners,
re-evaluate; until then the TCP + signed-envelope design stands.

## 2. Principals and scopes

| `client` | Who | May call |
|---|---|---|
| `menubar` | the GUI app, one session per pairing | every operation in §6 |
| `supervisor` | the Supervisor's own health probing (ADR-001 D3 probes `/health/ready` every 30 s; the health contract requires the IPC token on that route) | `GET /health/ready`, `GET /ipc/v1/health` **only** |

The `client` is embedded in the token (§3.3) and the route table enforces the scope; a `supervisor`
token on any other route is `403` / `forbidden_scope`. There is exactly one live session per client in
v1; pairing again replaces it.

On the server, a verified session resolves to a synthetic principal `IpcClient { client, session_id }`
bound to the **owner** account (v1 is single-owner), and every operation runs its gate as
`Gate::forUser($owner)->authorize(<ability>, ['via' => 'ipc'])`. The `via: ipc` context exists so that
policies which require re-authentication (§11) can deny the IPC path explicitly.

### 2.1 The one operation with no owner-bound principal (decision, 1.1.0)

There is exactly one moment in the product's life when the paragraph above cannot hold: the
installer's `CREATE_OWNER` step (§12.1), where the owner account the principal would bind to is
precisely what the operation creates. `POST /ipc/v1/owner` (§6.9) is therefore the one operation
that runs **no gate**. That is a decision with a stated justification, not a hole:

1. **What the owner binding actually buys, and why it is absent here.** The binding is not
   authentication — authentication is the session token (§3) — it is *authorisation*: it answers
   "may this person do this?" against a policy. Before `CREATE_OWNER` there is no person to ask
   about and no policy to ask. Binding to a null owner would not be safer; it is what the server
   does for every *other* operation, and the answer is `403` / `policy_denied` (fail closed, see
   point 4).
2. **What stands in its place.** Two conditions, and only both together are the authorisation:
   a live `menubar` session — which already proves our signed application, paired through the
   Supervisor over XPC with `setCodeSigningRequirement` pinned to our Team ID on both ends (§3.2,
   §7.1), reaching the core over loopback (§5.2) with a request signed inside a ±30 s window and a
   nonce that has not been seen (§4) — **and** the absence of an owner, re-read from the database
   on every single request. The second condition is what makes the first sufficient: the window in
   which this operation does anything at all is the window between the first boot and the first
   owner.

   That session is obtained the ordinary way, lazily, when this operation makes its first request
   (§3.2 step 2); it is **not** inherited from a pairing that some earlier installer state
   performed, because no state does that. What `CREATE_OWNER` relies on is only that a Supervisor
   exists to pair *with*, which `INSTALL_CORE` guarantees by registering it (#124).
3. **Why it is not a way in.** The window closes permanently and for every caller the instant an
   owner exists: from then on the route answers `409` / `owner_exists` (§8.2), creates nothing,
   names nobody, and writes `auth.owner_create_refused`. It has no read side, so it cannot be used
   to learn anything: its only successful answer is `{"created": true}`, and its only refusal
   carries no detail — not the existing owner's address, id or organization. There is no field in
   its request through which a caller can ask for anything but the one owner this install does not
   yet have (`additionalProperties: false`, §11.4), and no credential anywhere in the exchange,
   because sign-in is a one-time code mailed to the address that was submitted
   ([auth.md](auth.md) §3). An attacker who could call it would already be a process able to
   produce our code signature to the Supervisor on this user's Mac before the owner existed — at
   which point they could run the installer itself.
4. **The pre-owner path is deliberate, not incidental.** `IpcPrincipal` resolves the owner lazily;
   with no owner `owner()` is null, `actor()` degrades to the `job:IpcServer` provenance actor, and
   `authorize()` **throws `policy_denied`**. So every gated operation in §6 is refused on an
   ownerless core by construction, and the single pre-owner operation never calls `authorize()` at
   all. `IpcPolicyArchTest` pins both halves: the pre-owner set is exactly one route, it carries
   `IpcRequireNoOwner`, and it names no ability — a second entry is a contract change with two
   reviewers (§13.2), not a test edit.

The same rule in one line: **an operation may skip the owner-bound gate only if an owner existing
makes it refuse.**

## 3. Authentication: a session-bound token issued at pairing

### 3.1 Key material (ADR-003 D1)

| Item | Where | Written by | Read by |
|---|---|---|---|
| `K` — 256-bit HMAC key, Keychain item `se.stafe.executive-ai.ipc.menubar`, envelope `{"v":1,"kind":"hmac_key","key":"<base64 32 bytes>","issued_at":"…"}` | data-protection keychain, shared access group | Supervisor on its own first boot (§3.2); regenerated by "Reset security pairing" and by every reinstall (ADR-003 D8) | core HTTP at boot through `SecretStore::get` (ADR-003 D3); held in memory as a `SecretValue`, never cached to disk |
| Pairing grant (token + signing key, §3.2) | the **GUI app's own private Keychain item** `se.stafe.executive-ai.ipc.menubar-session` in its default access group (ADR-003 D5: "default private group only (its S0-15 token)"), `kSecAttrAccessibleAfterFirstUnlock`, `kSecAttrSynchronizable = false` | GUI app after pairing | GUI app |
| Supervisor's own grant | Supervisor memory, re-derived at every start | Supervisor | Supervisor |

The GUI app never holds `K`; the core never holds a grant. Both can verify a token because the token
is derived from `K` (§3.3), so the server needs **no session table** and nothing to leak.

### 3.2 Pairing (GUI → Supervisor over XPC, §7)

> **Corrected 2026-10-13 (#124).** This section used to open "At the installer's `BOOT_SERVICES`
> state … the Supervisor generates `K`". Both halves were wrong, and the error was expensive: it
> was read as "the installer holds a pairing grant from `BOOT_SERVICES` onwards", which was used
> to justify calling `POST /ipc/v1/owner` from `CREATE_OWNER` in #121 — a state that runs **two
> states earlier** (`WELCOME → PREFLIGHT → PACKAGE_VERIFY → INSTALL_CORE → CREATE_OWNER →
> SELECT_AI → BOOT_SERVICES → HEALTH_CHECK → READY`, blueprint §12.1). The first signed build
> failed there with "pairing failed: the Supervisor did not answer". `K` is generated on the
> Supervisor's **own first boot**, not at any installer state, and pairing is **lazy**, not tied to
> a state at all. Written correctly below.

1. `K` is generated by the Supervisor **on its first boot**, before it serves anything
   ([services.md](services.md) §2 step 3), and again on "Reset security pairing" (ADR-003 D8). The
   Supervisor's first boot is whenever launchd starts it, which is after
   `SMAppService.register()` — and that is called at the **end of `INSTALL_CORE`**, because every
   state after it needs a core and only the Supervisor can make one (ADR-001 D3 as amended,
   [installer.md](installer.md) §6). No installer state generates `K` itself.
2. The GUI app calls `pair(client: .menubar)` on the Supervisor's XPC service (§7) **lazily, on
   its first request that needs a grant** — `IpcSession` pairs when it has none, or when the one
   it has expires within 24 h (step 4). There is no "pairing state" in the installer: the first
   caller to need a session pairs, and from `CREATE_OWNER` onwards that is whichever of the owner
   creation, the health probe or the menu bar panel runs first. The connection has
   `setCodeSigningRequirement` set to our Team ID on both ends, so only our signed GUI can pair.
3. The Supervisor mints a `session_id` (ULID), `expires_at` (`issued_at + 30 days`), and returns the
   **pairing grant**:

```json
{
  "v": 1,
  "client": "menubar",
  "session_id": "01M4G0QK2T6Z5P9Y3R7W1A8C4E",
  "token": "lxai1.menubar.01M4G0QK2T6Z5P9Y3R7W1A8C4E.1794240000.<32 hex>",
  "signing_key": "<base64url 32 bytes>",
  "issued_at": "2026-10-10T18:00:00+02:00",
  "expires_at": "2026-11-09T18:00:00+02:00",
  "port": 8787,
  "ipc_version": 1
}
```

4. The GUI stores the grant in its private Keychain item and discards it from memory except for use.
   It re-pairs automatically when a request fails with `401` / `session_invalid` (the key was rotated)
   or when `expires_at` is within 24 h. Re-pairing is silent for the user; it never shows a dialog.

### 3.3 Token format and derivation

```text
token        = "lxai1." client "." session_id "." expires_at "." mac
mac          = hex( HMAC-SHA256(K, "lxai-ipc/v1/token\n" client "\n" session_id "\n" expires_at) )[0:32]
signing_key  = HMAC-SHA256(K, "lxai-ipc/v1/signing-key\n" client "\n" session_id)          (32 bytes)
```

- `client` ∈ `{menubar, supervisor}`; `session_id` is a ULID; `expires_at` is Unix seconds (decimal).
- `mac` is the first 128 bits of the HMAC, hex-encoded (32 characters). The token is sent as
  `Authorization: Bearer <token>` on every request.
- The **signing key is never transmitted**. It signs requests (§4) and verifies responses (§4.5).
- The server verifies a token by recomputing `mac` under `K` (constant-time compare), checking
  `expires_at > now`, and checking `client` against the route scope. Nothing else is looked up.

### 3.4 Scope, rotation, revocation

- **Scoped**: the token carries `client`; the route table (§2) is the scope. A token cannot be
  escalated because the scope is inside the MAC.
- **Rotatable**: "Reset security pairing" (Settings → Diagnostics) regenerates `K`. Every token and
  signing key derived from the old `K` becomes invalid at once; the core re-reads `K` on the next
  request after a `secrets.changed` notification from the broker (ADR-003 D3), with a 5 s upper bound.
  The GUI then re-pairs (§3.2 step 4). Writes `audit_events{type: ipc.pairing_reset}`.
- **Revocable**: the same action is revocation. Because v1 has one client and stateless verification,
  there is no per-session revocation list; adding a second GUI client (a future Sprint) must add a
  `revoked_sessions` cache or an `epoch` in the Keychain envelope — recorded as a reopen condition.
- **Expiring**: 30 days, renewed by silent re-pairing. The Supervisor's own grant lives for the
  Supervisor's process lifetime.
- **Uninstall** deletes `K` with the rest of the `se.stafe.executive-ai.*` items (ADR-003 D8) and the
  GUI deletes its private item.

## 4. Request signing, time window, replay protection

### 4.1 Required request headers

| Header | Value | Checked by |
|---|---|---|
| `Host` | `127.0.0.1:<port>` exactly | §5 |
| `Authorization` | `Bearer <token>` (§3.3) | §3.3 |
| `X-Lxai-Ipc-Version` | the wire major, `1` | §5.1 |
| `X-Lxai-Timestamp` | Unix seconds, decimal, client clock | `|now − ts| ≤ 30 s`, else `401` / `timestamp_skew` |
| `X-Lxai-Nonce` | 16 random bytes, base64url without padding (22 chars) | replay cache (§4.3) |
| `X-Lxai-Signature` | `v1=<64 hex>` (§4.2) | constant-time compare |
| `X-Lxai-Request-Id` | ULID, optional; echoed in the response and in logs | — |
| `Content-Type` | `application/json` on every request with a body | `415` otherwise |
| `Accept` | `application/json` | — |
| `If-None-Match` | only on `GET /ipc/v1/briefs/latest` (§6.2) | — |
| `X-Lxai-Elevation` | only on elevated operations (§7.3) | §7.3 |

Any other `X-Lxai-*` request header is rejected (`400` / `bad_request`). `Origin`, `Referer`,
`Cookie` and any `Sec-Fetch-*` header **must be absent** (§5.2).

### 4.2 Canonical request string and signature

```text
canonical = "LXAI-IPC-REQ/1" LF
            METHOD                LF        -- upper-case, as sent
            PATH_AND_QUERY        LF        -- exactly the request-target bytes, e.g. "/ipc/v1/approvals?state=pending&limit=20"
            X-Lxai-Timestamp      LF
            X-Lxai-Nonce          LF
            hex(SHA-256(body))              -- body bytes as sent; SHA-256 of the empty string when there is no body
signature = "v1=" hex( HMAC-SHA256(signing_key, canonical) )
```

`LF` is `0x0A`; there is no trailing newline. The server rebuilds the string from the request it
actually received, so a proxy that rewrites the path or re-encodes the body breaks the signature by
design. The body hash makes `Content-Length` and chunking irrelevant. Query-string ordering is whatever
the client sent; the server does not normalise it.

### 4.3 Replay protection

- The server keeps the nonces it has accepted for **120 s** (four times the skew window) in the
  Laravel cache (`ipc:nonce:<session_id>:<nonce>`), bounded to 10 000 entries per session (LRU). A
  repeated nonce inside the window is `401` / `nonce_replayed`; a nonce older than the window is already
  outside the timestamp check.
- Nonces are scoped per `session_id`, so re-pairing cannot resurrect an old nonce.
- The pair `(timestamp, nonce)` is logged in rejections (`audit_events{type: ipc.rejected}`) so an
  incident review can distinguish clock drift from replay.

### 4.4 Verification order on the server (fail closed, cheapest first)

1. Origin and host (§5) → `403` / `origin_rejected`
2. `X-Lxai-Ipc-Version` present and supported → `426` / `version_unsupported`
3. `Authorization` parses as `lxai1.` token, MAC valid, not expired → `401` / `session_invalid`
4. `client` allowed on this route → `403` / `forbidden_scope`
5. `X-Lxai-Timestamp` within ±30 s → `401` / `timestamp_skew`
6. `X-Lxai-Nonce` well-formed and unseen → `401` / `nonce_replayed`
7. `X-Lxai-Signature` matches → `401` / `signature_invalid`
8. Rate limit (§9) → `429` / `rate_limited`
9. Elevation, where required (§7.3) → `403` / `elevation_required`
10. Body schema → `422` / `validation_failed`
11. Policy/gate for the operation (§11) → `403` / `policy_denied` or `403` / `reauth_required`

Steps 1–7 run in one middleware (`IpcAuthenticate`) before routing resolves a controller; the error
body never says *which* of 3–7 failed beyond the code, and never echoes the token or signature.

### 4.5 Response signing

Every response — success, error, `304`, `503` — carries:

```text
X-Lxai-Ipc-Version: 1
X-Lxai-Request-Id: <echoed or server-generated ULID>
X-Lxai-Signature: v1=hex( HMAC-SHA256(signing_key,
                     "LXAI-IPC-RES/1" LF status LF request-nonce LF hex(SHA-256(body)) ) )
Cache-Control: no-store
X-Lxai-Origin: 127.0.0.1:<port>          -- set by ADR-006's ResolveRequestOrigin on every response
```

The client verifies the signature **before parsing the body**. A missing or invalid response signature
is treated exactly like a connection failure: the panel shows *Core unreachable* (health contract §7)
and the client never renders the body. The exception is a response that fails step 1–3 of §4.4
(the server has no `signing_key` yet because it rejected the token): those carry no signature and the
client treats `401`/`403`/`426` without a signature as *authentic enough to act on* only in the sense
of re-pairing or showing the update/repair hint — never as data.

### 4.6 Test vectors

Every fixture in `packaging/fixtures/ipc/` is signed with the **test key** `K_test = 32 zero bytes`
(`0000…00`), session `01M4G0QK2T6Z5P9Y3R7W1A8C4E`, so that `signing_key`, `mac` and every
`X-Lxai-Signature` in the fixtures are reproducible. `validate-schema.mjs` recomputes all of them; a
Swift or PHP implementation that signs the fixture requests with the same inputs must produce
byte-identical headers. `K_test` is never a valid production key: the Supervisor refuses to write an
all-zero key, and the core refuses to load one outside the test environment. Fixture bodies are JSON
values; the bytes a conformance test must put on the wire for them are the RFC 8785 (JCS) canonical
form, which is what the signatures cover. `packaging/fixtures/ipc-signing.mjs` is the reference
implementation of §3.3, §4.2, §4.5 and §7.3; `sign-ipc-fixtures.mjs` re-signs the fixtures after an
edit.

## 5. Origin and version checks

### 5.1 Versioning

- The contract version is semver (`1.1.0`, this document; same bump rules as the brief schema §4).
  The wire carries only the **major** in `X-Lxai-Ipc-Version`, which is therefore still `1`: a minor
  adds operations, fields and error codes and never changes the meaning of anything already
  specified, so a 1.0.0 client and a 1.1.0 server understand each other on every 1.0.0 operation.
  The full semver appears in two places only — this document and the heartbeat's
  `contract_version` (§6.5) — and the server never refuses a client for naming an older minor.
- The server supports the **current major and the previous major for one release** (the same rule as
  ADR-005 D3.7). A request with a missing or unsupported major fails closed:

```json
HTTP/1.1 426 Upgrade Required
{
  "ok": false,
  "request_id": "01M4G0R2Y3K8F6V1N7T5B9D4HA",
  "server_time": "2026-10-10T18:00:05+02:00",
  "error": {
    "code": "version_unsupported",
    "message": "This IPC version is not supported by the installed core.",
    "hint": "Update or repair Laravel Executive AI (Settings → Updates → Repair).",
    "supported_versions": [1]
  }
}
```

- The client compares the response `X-Lxai-Ipc-Version` with the majors it implements. A server major
  above the client's maximum means the GUI is older than the core; the GUI shows **Update required**
  and stops calling mutating operations. No partial or best-effort behaviour on mismatch in either
  direction (brief schema §4 "never a partial brief" applies by analogy).
- `GET /health/live` is outside this contract and needs no version header (health contract §6).

### 5.2 Origin check (ADR-006 D3 consistent)

A request is accepted only when **all** of the following hold; otherwise `403` / `origin_rejected`
and `audit_events{type: ipc.rejected, reason: origin}`:

1. The TCP peer address is `127.0.0.1` (the resolved request origin per ADR-006's
   `ResolveRequestOrigin` is `http://127.0.0.1:<port>`).
2. `Host` is exactly `127.0.0.1:<port>`. `localhost`, `[::1]` and the Tailscale DNS name are rejected
   on `/ipc/*` even though `TrustHosts` admits the first two elsewhere.
3. **No** `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Proto` or `Tailscale-User-*` header is
   present. `tailscaled` sets these on every proxied request (ADR-006 D3), so their presence proves the
   request did not come from a local native client. `/ipc/*` and `/health/*` are therefore never
   reachable through Serve, independently of any route allowlist.
4. **No** `Origin`, `Referer`, `Cookie` or `Sec-Fetch-*` header is present. Browsers always send at
   least `Sec-Fetch-Mode`; a native client never does. This closes DNS-rebinding and cross-site
   request paths from any web page, including our own PWA.
5. `Content-Type` is `application/json` when a body is present (no form encodings, so no
   simple-request CSRF).

## 6. Operations (v1)

Every success body is the **success envelope**; every failure is the **error envelope** (§8). `data`
is operation-specific. All timestamps are RFC 3339 with offset. The exact JSON of each exchange is in
`packaging/fixtures/ipc/<operation>.json`.

| # | Operation | Method · path | Mutates | Policy ability | Fixture |
|---|---|---|---|---|---|
| 6.1 | Health | `GET /ipc/v1/health` | no | — (scope only) | `health.json` |
| 6.2 | Latest brief | `GET /ipc/v1/briefs/latest` | no | `viewBrief` | `briefs-latest.json`, `briefs-latest-not-modified.json` |
| 6.3 | Pending approvals | `GET /ipc/v1/approvals?state=pending` | no | `viewApprovals` | `approvals-pending.json` |
| 6.4 | Pause AI actions | `POST /ipc/v1/ai/pause` | yes | `pauseAi` | `ai-pause.json` |
| 6.4 | Resume AI actions | `POST /ipc/v1/ai/resume` | yes | `resumeAi` | `ai-resume.json` |
| 6.5 | Heartbeat | `POST /ipc/v1/heartbeat` | yes (timestamp) | — (scope only) | `heartbeat.json` |
| 6.6 | OAuth callback | `POST /ipc/v1/oauth/callback` | yes | `completeOAuth` | `oauth-callback.json` |
| 6.7 | Remote access status | `GET /ipc/v1/remote-access` | no | `viewRemoteAccess` | `remote-access-status.json` |
| 6.7 | Enable remote access | `POST /ipc/v1/remote-access/enable` | yes, **elevated** | `enableRemoteAccess` | `remote-access-enable.json` |
| 6.7 | Disable remote access | `POST /ipc/v1/remote-access/disable` | yes | `disableRemoteAccess` | `remote-access-disable.json` |
| 6.8 | Services | — | — | — | not HTTP; see §7 |
| 6.9 | Create the owner — **1.1.0** | `POST /ipc/v1/owner` | yes | — (**no gate**: §2.1, no owner exists yet) | `owner.json`, `error-owner-exists.json` |

### 6.1 `GET /ipc/v1/health`

The IPC-enveloped alias of `GET /health/ready`: `data` **is** the health contract's `ready` document,
unchanged, and the HTTP status mirrors it (`200` when `ready`, `503` otherwise, same body shape). The
bare `/health/ready` route keeps its un-enveloped body for the Supervisor and the installer (S0-14);
both routes sit behind the same `IpcAuthenticate` middleware. Cost and caching rules are the health
contract's (≤ 5 s, 5-second global cache). The menu bar polls this every 30 s while the panel is closed
and on every panel open.

### 6.2 `GET /ipc/v1/briefs/latest`

Returns the latest brief whose `delivery_state` is `frozen` or later (never a `draft`), as a full
document validating against `brief.schema.json` (`data` → `$ref` to the brief schema). `404` /
`not_found` when `brief_state` is `none`.

Caching, so the panel opens instantly from cache (§14 "native menu opens instantly from cached brief",
brief schema §4):

- `ETag: "<id>:<version>"`. A frozen version is immutable (brief schema §5), and the menu bar does not
  render delivery bookkeeping, so the ETag deliberately ignores `delivery_state`/`deliveries`.
- The client sends `If-None-Match` with its cached ETag; the server answers `304 Not Modified` with no
  body (and a response signature over the empty-body hash, §4.5). The client **never** fetches on
  panel open before rendering: it renders the cache, then revalidates. Health's `latest_brief.{id,
  version}` tells it whether a fetch is needed at all.
- The GUI persists the cached document in its own container (`~/Library/Application
  Support/LaravelExecutiveAI/menubar/brief-cache.json`, mode `0600`, excluded from Time Machine with
  `isExcludedFromBackupKey`, same posture as ADR-003 R2). It is deleted on "Reset security pairing"
  and on uninstall.
- The client declares its maximum supported brief `schema_version` (brief schema §4) and refuses to
  render a higher major, showing *Update required* — the same state as §5.1.

### 6.3 `GET /ipc/v1/approvals?state=pending`

Read-only list for the panel's "requires approval" section. Query: `state` (only `pending` in v1;
anything else `422`), `limit` (1–50, default 20), `cursor` (opaque). `data`:

```json
{
  "items": [
    {
      "id": "01M4FJ7XM84RV70ZSNAMPQ03HX",
      "kind": "email_send",
      "title": "Send delivery confirmation to Officina Ferrari",
      "summary": "To giulia.ferrari@example.it · drafted from brief item 01M4FJ7KVR644NVGHTFEGR4VEY",
      "created_at": "2026-10-10T05:31:02+02:00",
      "expires_at": "2026-10-11T05:31:02+02:00",
      "brief_item_id": "01M4FJ7KVR644NVGHTFEGR4VEY",
      "open_url": "http://127.0.0.1:8787/approvals/01M4FJ7XM84RV70ZSNAMPQ03HX"
    }
  ],
  "total_pending": 2,
  "next_cursor": null
}
```

`kind` is a free-form identifier (like `model_provenance.provider` in the brief schema) so a new
proposal type is not a major bump; v1 values: `email_send`, `crm_delete`, `github_write`,
`external_form`, `automation_action`, `mcp_tool_call`. `summary` is sanitised (≤ 500 chars, no
secrets, no mail bodies). **There is no approve/reject operation.** Tapping an item opens `open_url`
in the PWA, where the owner re-authenticates (§11); `total_pending` equals health's
`pending_approvals` and is the only badge source.

### 6.4 `POST /ipc/v1/ai/pause` and `POST /ipc/v1/ai/resume`

Flips the **global cooperative cancellation token** of ADR-004 D7 ("Pause AI actions in the menu bar
flips a global token"). Request body `{"reason": "<≤ 200 chars, optional>"}` or `{}`. `data`:

```json
{ "ai_actions": "paused", "changed_at": "2026-10-10T18:02:11+02:00", "paused_since": "2026-10-10T18:02:11+02:00", "in_flight_runs_cancelled": 1 }
```

- Pause is **idempotent**: pausing an already paused core returns `200` with the existing
  `paused_since` and `in_flight_runs_cancelled: 0`. Resume likewise.
- While paused: no new `ai_runs` start, in-flight runs stop within one step or stream chunk and are
  recorded `cancelled` (ADR-004 D7), the scheduler skips brief generation and reports it in health as
  `brief_state: stale` once `stale_after` passes, queued jobs that need a model are released back to
  the queue with a delay. CRM, previous briefs and admin keep working (§4.2).
- Pause never requires elevation: it only reduces activity. Resume runs `resumeAi`, which the policy
  denies while the automation broker is **locked** (ADR-003 D3.2) until the user clears the lock in
  the PWA. Both write `audit_events{type: ai.paused | ai.resumed, via: ipc}`.
- Pause is **not** the panic stop. Panic stop (ADR-005 D1.9) kills automation through the helper's
  XPC path and locks the secrets broker; it works with the core down. The menu bar's "Stop automation"
  item calls that path *and then* this operation best-effort.

### 6.5 `POST /ipc/v1/heartbeat`

Drives the health check `menubar_integration` (health contract §2: ok when a heartbeat arrived
within `threshold_seconds`, default 120). Sent every **30 s** while the GUI runs, and immediately after
pairing and after wake from sleep. Request:

```json
{ "client": "menubar", "client_version": "1.0.0 (120)", "contract_version": "1.1.0", "panel_open": false, "since": "2026-10-10T17:30:00+02:00" }
```

`data`: `{ "received_at", "next_heartbeat_in_seconds": 30, "threshold_seconds": 120, "server": { "app_version", "runtime_version" } }`.
The server stores only the receive time and `client_version` in the cache; the heartbeat is the
**only authenticated signal** the check reads — an unauthenticated or `supervisor`-scoped request
never updates it. Consequence noted for the health contract: "Quit UI" (§1.1) stops heartbeats, so
`menubar_integration` fails and `ready` drops 120 s later. The Supervisor must not treat that
particular `ready: false` as a reason to restart PHP children (ADR-001 D3 restarts on probe failure of
`/health/live`, not on `ready`); the installer's `READY` gate runs with the GUI alive, so it is
unaffected.

### 6.6 `POST /ipc/v1/oauth/callback`

ADR-003 D4: the callback URL from `ASWebAuthenticationSession` carries only `code` and `state`; the
GUI forwards exactly those two and **nothing else** (no fragment, no full URL, no provider name).

```json
{ "state": "st_01M4G0S7...", "code": "4/0AX4XfW..." }
```

The core looks the `state` up in its 10-minute cache, performs the token exchange with the stored PKCE
verifier, writes the result through `SecretStore::put` and answers:

```json
{ "status": "connected", "connection_id": "01M4G0T1P8X3M2K7Q9R5V4W6YB", "provider": "google", "open_url": "http://127.0.0.1:8787/integrations/01M4G0T1P8X3M2K7Q9R5V4W6YB" }
```

`status` ∈ `connected` · `pending` (exchange still running after the 15 s server budget; it finishes in
the queue and the PWA shows the result) · `failed` (provider rejected the code; `error_reason` is a
sanitised category such as `invalid_grant`, never provider prose). Unknown or expired `state` is
`422` / `oauth_state_invalid`. The `code` is **never** logged, never echoed, never stored (ADR-003 D7
row 1 key-based redaction covers `code`, `state`, `code_verifier`); the request body of this route is
excluded from any request dump. Gate: `completeOAuth` on the `email_connections`/`external_accounts`
row the `state` belongs to, as the owner.

### 6.7 Remote access (`TunnelProvider`, ADR-006)

The Mac app owns the Remote Access *screens*; the PHP `TailscaleServeTunnelProvider` owns the CLI
calls. The IPC carries the three contract methods:

- `GET /ipc/v1/remote-access` → `inspect()`. `data`: `{ "state": "<TunnelStatus>", "origin": "https://my-mac.example-tailnet.ts.net" | null, "tailscale": { "variant": "standalone" | "app_store" | "unsupported" | null, "version": "1.98.9" | null }, "detail": "<sanitised>", "checked_at" }`. `state` is exactly ADR-006 D2's enum: `NotInstalled`, `NotLoggedIn`, `HttpsNotEnabled`, `Ready`, `Active`, `Drifted`, `FunnelDetected`.
- `POST /ipc/v1/remote-access/enable` → `configure()`. **Elevated** (§7.3) and gated by
  `enableRemoteAccess`. Request `{ "confirmed_origin": "https://my-mac.example-tailnet.ts.net", "replace_existing": false }`. The server re-runs `inspect()` and refuses (`409` / `state_conflict`) unless the state is `Ready`, or `Drifted` with `replace_existing: true`, **and** `confirmed_origin` equals the origin it derives from `tailscale status --json` — the user must have seen the exact origin that becomes reachable (ADR-006 D1.3). It never enables from `FunnelDetected`. Success `data`: `{ "state": "Active", "origin": "https://…", "verified_at": "…" }` after ADR-006 D2's self-request verification; the self-request's `X-Lxai-Origin` check is performed by PHP. Writes `audit_events{type: remote_access.enabled, via: ipc}` and persists `remote.origin` in the settings table (ADR-006 D3 says "written by the Mac app"; through this operation the Mac app writes it *via the core*, which keeps ADR-001's "no `.env`" rule intact).
- `POST /ipc/v1/remote-access/disable` → `disable()`. Not elevated (it only reduces exposure). Body `{}`. `data`: `{ "state": "Ready" | "<whatever inspect() reports afterwards>", "origin": null, "remaining_handlers": [] }` — handlers the app did not create are listed, never removed (ADR-006 D2).

Both POSTs have a **30 s** server budget (two CLI calls plus a TLS self-request); the client timeout
is 35 s. ADR-006 D6's `TunnelFunnelGuardTest` covers every argv these operations can produce; nothing
in a request body reaches a CLI argument (`confirmed_origin` is compared, never passed).

### 6.8 Services — not an HTTP operation (decision)

`GET /ipc/v1/services` and `POST /ipc/v1/services/{core|worker|scheduler}/restart` are **reserved and
not implemented**; a request to them is `404` / `not_found`. Service control goes **GUI → Supervisor
over XPC** (§7.2). Reasons, in order:

1. The operation the user needs most — "Restart services" on the *Core unreachable* panel state
   (health contract §7) — is exactly the case where an HTTP request to the core cannot be answered.
2. The core cannot restart itself cleanly, and relaying `worker`/`scheduler` restarts would require a
   core → Supervisor command channel. The only existing core → Supervisor channel is the secrets broker
   socket, whose protocol is `get/put/delete/list/health` by ADR-003 D3.1; extending it with process
   control would conflate secrets custody with service control, which §0 forbids.
3. The Supervisor already owns process lifecycle, health probing and restart budgets (ADR-001 D3); an
   XPC call lands the request where the state lives, with `setCodeSigningRequirement` giving the peer
   identity a loopback request can never have.

"Quit UI" (§1.1) is a plain application quit: it sends a final heartbeat with `"panel_open": false`
and `"quitting": true`, then exits; it never stops services. "Stop services" / "Start services" are
XPC operations with a confirmation sheet.

### 6.9 `POST /ipc/v1/owner` — **added in 1.1.0**

The installer's `CREATE_OWNER` step (§12.1, [installer.md](installer.md) screen 4) over a
`menubar` session, so the person never sees a Terminal. The session is paired lazily on this
operation's first request (§3.2), against the Supervisor that `INSTALL_CORE` registered — so this
operation needs no new credential, prompt or authentication step, but it **does** need the core to
be up, which is why starting the services is `INSTALL_CORE`'s last step and not `BOOT_SERVICES`'s
(#124; `BOOT_SERVICES` is two states *later* than this one). Its authorization is a live `menubar`
token **and** the absence of an owner — §2.1 is the decision and the reasoning; this section is
the wire.

The client waits for the core's unsigned `/health/live` before its first signed request: the
Supervisor was registered moments ago and still has to mint the keys, migrate the database and
spawn the core, and a core that is merely slow must not be reported as a pairing failure.

Request:

```json
{ "name": "Ada Owner", "email": "ada.owner@example.test", "timezone": "Europe/Stockholm", "language": "sv" }
```

| Field | | |
|---|---|---|
| `name` | required, ≤ 200 chars, at least one non-whitespace character | screen 4's name field |
| `email` | required, RFC-valid, ≤ 254 chars | screen 4's address field. Identifies the account, receives the one-time sign-in codes and the Executive Brief; stored lower-cased |
| `organization` | optional, 1–200 chars | workspace name. **The Mac installer omits it** — screen 4 does not ask for one — and the core then names the workspace after its owner, renameable later in the PWA |
| `timezone` | optional, IANA zone | the zone the first brief is scheduled in. The Mac sends its own; omitted falls back to the core's configured zone |
| `language` | optional, BCP-47 | the language the briefs are written in. The Mac sends the installer's; omitted falls back to the core's configured locale |

`additionalProperties: false`, so `user_id`, `owner`, `role`, `organization_id` or `password` in the
body is `422` / `validation_failed` (§11.4). There is no password field anywhere in the product.

Success is `200` with `data`:

```json
{ "created": true }
```

**One fact and nothing else** — no token, no one-time code, no user, organization or membership id,
and not even the address that was submitted. The installer needs exactly one boolean
(`OwnerHandoff { alreadyExisted }`), so one boolean is what crosses the wire, and this answer is
given to a caller that has not yet proved it owns anything.

**The refusal is the interesting half.** When an owner already exists the operation answers
`409` / `owner_exists`, every time, creating nothing and naming nobody (`details: null`). That is
not an error path bolted on: it is how a **data-preserving reinstall** works. The installer reads
it as `OwnerHandoff(alreadyExisted: true)` and **advances to the next screen** rather than showing
a failure — the existing account and the existing briefs in the untouched `data/` are kept, and the
person signs in to them with a one-time code. The refusal is signed like any other answer (§4.5),
so a hijacked port cannot talk the installer *out* of creating the owner either.

Server behaviour:

- The work is `App\Security\Authorization\CreateOwner`, the same action the artisan command and the
  in-app Livewire form call ([auth.md](auth.md) §2): one organization, one user, one owner
  membership, in one transaction behind `Cache::lock('lea:create-owner')`, moving the install
  marker from `not_installed` to `installing`. There is no IPC-only path into the domain (§11.1).
- `Middleware\IpcRequireNoOwner` is the guard and re-reads "does an owner exist" per request;
  `CreateOwner`'s own check **inside** the lock is the backstop for the race, and a request that
  loses it lands on the same `owner_exists`. Two concurrent calls therefore produce exactly one
  owner (threat model T-72).
- Audit (§10): `auth.owner_created` on success and `auth.owner_create_refused` on refusal, both
  with `via: ipc`, the `client`, the `session_id` and the `request_id`, the success actor being
  `job:IpcCreateOwner` so the trail names which of the three doors was used. Neither record carries
  the submitted name or address beyond what `CreateOwner` already stores.
- Client mapping: `200` → `alreadyExisted: false`; `409 owner_exists` → `alreadyExisted: true`;
  `404` or `426` → *the core is too old, repair it*; `422`/`403` → the form is wrong and screen 4
  says so; everything else → *core unavailable*. `IpcOwnerCreator` owns that split
  ([ipc-client.md](ipc-client.md) §5).

## 7. GUI ↔ Supervisor XPC surface

Mach service `<bundle-prefix>.supervisor.control` (prefix pending A12), served by the Supervisor's
`NSXPCListener`; both sides set `setCodeSigningRequirement("anchor apple generic and certificate
leaf[subject.OU] = \"<TEAMID>\"")`. Protocol `SupervisorControl` (Swift, `@objc`), all calls
asynchronous with a 10 s timeout:

| Method | Does | Guard |
|---|---|---|
| 7.1 `pair(client:) -> PairingGrant` | §3.2 | code-signing requirement; one live session per client |
| 7.1 `resetPairing()` | regenerates `K`, notifies the core via the broker, returns a fresh grant | confirmation sheet in the GUI; `audit_events{type: ipc.pairing_reset}` |
| 7.2 `services() -> [ServiceStatus]` | `{name: core\|worker\|scheduler, state: running\|starting\|stopped\|degraded, pid?, restarts_in_window, last_exit_reason?, since}` from the Supervisor's own bookkeeping (ADR-001 D3) | — |
| 7.2 `restart(service:)`, `stopAll()`, `startAll()` | process control with ADR-001's backoff and budget; `stopAll` leaves launchd `KeepAlive` in place (the Supervisor keeps running, PHP stops) | confirmation sheet; `restart` of `core` while a `remote-access/enable` is in flight is refused |
| 7.3 `elevate(sessionId:operation:nonce:) -> Elevation` | prompts for device-owner authentication (`LAContext.evaluatePolicy(.deviceOwnerAuthentication)`, Touch ID or login password) and, on success, returns a one-shot elevation token | the prompt is the Supervisor's, with the operation named in the reason string |
| 7.2 `removeAllSecrets(keepApplicationKey:) -> [KeychainItemRef]` — **§7.2 addition, S1-13; `keepApplicationKey` added by S2-04 (A25)** | uninstall: deletes `se.stafe.executive-ai.*` Keychain items through the one writer (ADR-003 D2, D8) and drops every live session. With `keepApplicationKey: true` — the *keep my data* choice — `core.app-key` and only it is spared, every account under it, because from S2-04's first `encrypted:` cast it is the only thing that can read the rows the person chose to keep (A25); `false`, the default, takes the key too. `ipc.menubar` goes either way, so the sessions always die. Returns `{service, account}` names only, never a value, and only for the items actually removed — a spared key is not counted | the uninstaller passes the person's data choice and calls it before `SMAppService.unregister()`; confirmation sheet in the GUI with the keep-or-delete data choice ([services.md](services.md) §8, [`docs/operations/uninstall.md`](../operations/uninstall.md)). The argument is a `BOOL` on the `@objc` XPC protocol. Additive and XPC-only: it is not carried on the HTTP wire, so it needed no contract bump of its own — the later 1.1.0 minor (§15) is unrelated to it, and `X-Lxai-Ipc-Version` is `1` either way |

### 7.3 Elevation (re-authentication for critical IPC operations, §11)

The menu bar has no PWA session, so §11's "re-auth för kritiska åtgärder" is satisfied through the
Supervisor, which can both prompt the user and prove it to the core:

```text
elevation = "lxai1e." operation "." nonce "." expires_at "." mac
mac       = hex( HMAC-SHA256(K, "lxai-ipc/v1/elevation\n" session_id "\n" operation "\n" nonce "\n" expires_at) )[0:32]
```

`operation` is the route slug (`remote-access-enable`), `nonce` is the `X-Lxai-Nonce` the GUI will use
on that single request, `expires_at` is `now + 60 s`. The GUI sends it as `X-Lxai-Elevation`; the core
verifies under `K`, checks the nonce matches the request and is unused, and only then runs the
operation. v1 requires elevation for exactly one operation (`remote-access/enable`); **approvals are
never granted through IPC at all**, elevated or not (§11).

## 8. Envelopes and errors

### 8.1 Success

```json
{ "ok": true, "request_id": "01M4G0R2Y3K8F6V1N7T5B9D4HA", "server_time": "2026-10-10T18:00:05+02:00", "data": { } }
```

### 8.2 Error

```json
{ "ok": false, "request_id": "…", "server_time": "…",
  "error": { "code": "signature_invalid", "message": "Request signature did not verify.", "hint": "Re-pair the menu bar with the core (Settings → Diagnostics → Reset security pairing).", "retry_after_seconds": null, "supported_versions": null, "details": null } }
```

| HTTP | `error.code` | When | Client behaviour |
|---|---|---|---|
| 400 | `bad_request` | malformed headers, unknown `X-Lxai-*` header, bad JSON | log, do not retry |
| 401 | `session_invalid` | token malformed, MAC wrong, expired | re-pair silently (§3.2), retry once |
| 401 | `timestamp_skew` | outside ±30 s | resync clock (use `server_time`), retry once |
| 401 | `nonce_replayed` | nonce seen within 120 s | new nonce, retry once |
| 401 | `signature_invalid` | §4.2 mismatch | re-pair, retry once; if it persists show *Core unreachable* |
| 403 | `origin_rejected` | §5.2 | never retry; surface in diagnostics |
| 403 | `forbidden_scope` | `supervisor` token on a menu-bar route | bug; log |
| 403 | `elevation_required` / `elevation_invalid` | §7.3 | run `elevate`, retry once |
| 403 | `policy_denied` | gate denied | show the `message`; no retry |
| 403 | `reauth_required` | the operation needs the PWA session (approvals) | open `details.open_url` in the PWA |
| 404 | `not_found` | no brief yet; reserved route | show the documented empty state |
| 409 | `state_conflict` | remote-access precondition failed | re-run `inspect()`, show the state |
| 409 | `owner_exists` — **1.1.0** | `POST /ipc/v1/owner` on a core that already has one (§6.9) | **not a failure for the installer**: report `alreadyExisted: true` and advance. Never retried |
| 413 | `payload_too_large` | §1.1 limits | bug; log |
| 415 | `unsupported_media_type` | body without `application/json` | bug; log |
| 422 | `validation_failed` / `oauth_state_invalid` | body or query invalid | show `details` (field → problem) |
| 426 | `version_unsupported` | §5.1 | *Update required* / repair hint |
| 429 | `rate_limited` | §9; `Retry-After` header set | back off `retry_after_seconds` |
| 500 | `internal` | unexpected; `request_id` is the correlation key | show *Open diagnostics* |
| 503 | — | `GET /ipc/v1/health` with `ready: false`: **no error code**; the body is the success envelope carrying the health document, exactly as `/health/ready` returns `503` with a normal body | health contract §7 |
| 503 | `secrets_unavailable` | the core could not read `K` (broker down or **locked**, ADR-003 D3.2) | *Core unreachable* with the broker hint |

`message` is a fixed English sentence per code, localised by the client; it never contains user data,
paths or stack traces. `details` is an object or `null`; `supported_versions` is set only on `426`;
`retry_after_seconds` only on `429` and `503`.

## 9. Rate limits, timeouts, sizes

| Operation | Server limit (per session) | Server budget | Client timeout |
|---|---|---|---|
| `health` | 30 / min | 5 s (health contract) | 6 s |
| `briefs/latest` | 30 / min | 2 s | 5 s |
| `approvals` | 30 / min | 2 s | 5 s |
| `ai/pause`, `ai/resume` | 10 / min | 2 s (cancellation is asynchronous beyond that) | 5 s |
| `heartbeat` | 6 / min | 500 ms | 3 s |
| `oauth/callback` | 5 / min | 15 s, then `pending` | 20 s |
| `owner` — **1.1.0** | 5 / min | 10 s (≤ 5 s waiting on `lea:create-owner`, then one transaction) | 12 s |
| `remote-access` (GET) | 10 / min | 5 s | 8 s |
| `remote-access/enable`, `/disable` | 3 / min | 30 s | 35 s |
| all routes together | 120 / min | — | — |

Limits use Laravel's `RateLimiter` keyed by `session_id`; `429` carries `Retry-After`. The client never
retries `429` sooner than the header says and never retries a `POST` whose response it did not receive
except `heartbeat`, `ai/pause` and `remote-access/disable`, which are idempotent by construction.
Connection: the client keeps one HTTP/1.1 keep-alive connection, `URLSession` with `waitsForConnectivity
= false` and proxies disabled (`connectionProxyDictionary = [:]`), so a system proxy can never see the
loopback traffic.

## 10. Logging and audit (ADR-003 D7)

- The request log line is `{ts, request_id, session_id, client, method, path, status, duration_ms}`.
  **Never** logged: `Authorization`, `X-Lxai-Signature`, `X-Lxai-Elevation`, the pairing grant, the
  body of `oauth/callback`, any brief content. The ADR-003 `Redactor` runs on every channel anyway;
  `lxai1.` and `lxai1e.` are added to its pattern corpus (`tests/Security/fixtures/secrets-corpus.json`)
  so a token that slips into an exception message is caught (D7 row 1, 2).
- Rejections write `audit_events{type: ipc.rejected, reason: <code>, client?, session_id?, ts, nonce}`
  — the reason code, never the presented token.
- Mutating operations write their own audit events (`ai.paused`, `ai.resumed`, `oauth.completed`,
  `remote_access.enabled`, `remote_access.disabled`, `ipc.pairing_reset`) with `via: ipc` and the
  `request_id`.
- Swift side: `os.Logger` with the grant and every header value interpolated at `.private` (D7 row 6);
  the SwiftLint rule from ADR-003 applies to `apps/macos/LaravelExecutiveAI/IPC/`.
- Diagnostics export (D7 row 9) includes the request log lines and `ipc.rejected` events, never the
  Keychain items or the brief cache.

## 11. No policy bypass (Blueprint §11)

1. **Every mutating operation runs the same Laravel policy/gate as the PWA**, as the owner, with
   `via: ipc` in the context. `IpcController` methods call the same application services the PWA's
   Livewire actions call; there is no IPC-only code path into the domain. Pest architecture test:
   every route under `/ipc/` carries `IpcAuthenticate` **and** a named ability in the `can:` middleware
   or an explicit `authorize()` in the controller; a route without one fails CI. The single
   exception is §2.1's pre-owner operation, which has no owner to run a gate as: it carries
   `IpcRequireNoOwner` instead, the same architecture test pins that the pre-owner set is exactly
   one route, and it too calls a shared application service (`CreateOwner`) rather than its own
   path into the domain.
2. **Approvals are never granted via IPC**, with or without elevation. The IPC exposes a read-only
   list and a deep link; approving, rejecting, cancelling and the re-authentication they require happen
   in the PWA session (§11 "re-auth för kritiska åtgärder"; ADR-005 D1.6 "only from an authenticated,
   re-authenticated UI action"). A future "approve from the menu bar" needs a new contract version and
   a security review; it is not a minor bump.
3. **Elevation is additive, not a substitute**: `X-Lxai-Elevation` is checked *before* the gate, and
   the gate still runs.
4. **Policies never trust request content for identity**: `client`, `session_id` and the owner binding
   come from the verified token; a body field named `user_id`, `owner`, `approver` or similar is
   rejected by the request schema (`additionalProperties: false`).
5. **No IPC operation starts a model run, sends mail, writes to the CRM or calls an MCP tool.**
   The mutating set is exactly: the cancellation token (pause/resume), the heartbeat timestamp, the
   OAuth exchange that the user initiated in the GUI, the `TunnelProvider` state, and — since
   1.1.0 — the one-time creation of the owner account (§6.9). Adding a mutating
   operation is a security-critical PR (two reviewers, §13.2) and a contract minor bump at least.
6. **An operation may skip the owner-bound gate only if an owner existing makes it refuse** (§2.1).
   v1 has exactly one such operation and it is `POST /ipc/v1/owner`. This is not a general
   exemption for "setup" operations: anything else the installer needs after `CREATE_OWNER` runs as
   the owner, through a gate, like everything else.

## 12. Tests the implementation must ship (S1-08, §14 "IPC tests")

| Test | Proves |
|---|---|
| `IpcFixtureConformanceTest` (PHP) and `IPCFixtureTests` (Swift) | every `packaging/fixtures/ipc/*.json` request signed with `K_test` is accepted/rejected exactly as recorded, and the recorded response signature verifies |
| `IpcTokenTest` | missing, malformed, wrong-MAC, expired and wrong-`client` tokens → the recorded `401`/`403`, one test per case (S1-08 criterion) |
| `IpcReplayTest` | same nonce twice → `nonce_replayed`; timestamp −31 s / +31 s → `timestamp_skew`; −29 s accepted |
| `IpcOriginTest` | request with `Host: localhost`, with `X-Forwarded-For`, with `Origin`, with `Sec-Fetch-Mode` → `origin_rejected`; request from a non-loopback bind is impossible (`IpcBindTest` asserts the listener has no non-loopback socket, S1-08 "not reachable from another host") |
| `IpcVersionTest` | missing / `0` / `2` → `426` with `supported_versions: [1]` |
| `IpcPolicyArchTest` | every `/ipc/*` route has a gate; no route under `/ipc/` resolves to an approval-mutating service |
| `IpcRedactionTest` | corpus tokens (`lxai1.…`, `lxai1e.…`) in exception messages never reach logs; `oauth/callback` body never appears in any sink |
| `IpcResponseSigningTest` (Swift) | tampered body, tampered status, missing signature → *core unreachable*, body never parsed |
| `HeartbeatCheckTest` | `menubar_integration` only moves on an authenticated `menubar` heartbeat; `supervisor` heartbeat rejected with `forbidden_scope` |
| `OwnerCreateTest` (PHP, **1.1.0**) | §6.9: a fresh install creates organization, user and owner membership and answers `{created: true}` and nothing else — no credential, no id, not the submitted address; a repeated install is `409 owner_exists`, creates nothing and audits `auth.owner_create_refused` with `via: ipc`; every further attempt refuses too; the request waits on `lea:create-owner` instead of creating an owner beside a concurrent run; a `supervisor` token is `forbidden_scope` and an absent or wrong-MAC token is `session_invalid`; an identity-asserting body is `422`; the 5/min budget |
| `IpcOwnerCreatorTests` (Swift, **1.1.0**) | `200` → `OwnerHandoff(alreadyExisted: false)`, `409 owner_exists` → `alreadyExisted: true` and never a retry, every other code onto the right half of `OwnerCreationError`; an **unsigned** `409` is still refused (§4.5); the recorded `owner.json` request is reproduced byte for byte; the draft never reaches the client log; and the installer advances past `CREATE_OWNER` on both hand-offs while stopping on a rejection or an unreachable core |

## 13. What this document does not decide

- The bundle-id prefix and Team ID in the XPC service name and code-signing requirement — A12.
- Threshold values (`menubar_integration` 120 s) — `config/health.php` (S1-01).
- The PWA's own authentication, sessions and re-auth UX (§11) — Workstream C.
- Brief caching *policy* in the GUI beyond what §6.2 fixes (eviction, how many versions) — S1-09.
- Whether a second GUI client (a future iPhone companion over the tailnet) ever uses this contract;
  it would need per-session revocation (§3.4) and a transport with TLS — a new major, not a patch.

## 14. Reopen conditions

- A second client type is added → per-session revocation, `epoch` in the `ipc.menubar` envelope.
- FrankenPHP/Caddy exposes verified peer credentials for Unix-socket listeners to PHP → revisit §1.2.
- The health contract removes `menubar_integration` from `ready` or redefines "Quit UI" semantics →
  §6.5 note is void.
- ADR-001 changes the process model (core no longer an HTTP server on loopback) → the transport
  section is rewritten before the change lands.
- Any `ipc.rejected` with `reason: origin` seen in the field from a `127.0.0.1` peer → incident; the
  §5.2 rule set is reviewed, not relaxed.

## 15. Version history

Bump rules are the brief schema's §4 (optional field or new member → minor; anything that changes
the meaning of what is already specified → major). The wire carries only the major, so every entry
below leaves `X-Lxai-Ipc-Version` at `1`. **Every place the version string is pinned** must move in
the same PR: this document's header and §5.1, the heartbeat payload (`contract_version`, §6.5), the
`schema_version` const in `packaging/fixtures/ipc.schema.json` and in every
`packaging/fixtures/ipc/*.json`, and the Swift constant `IpcOperation.contractVersion`. (Neither the
brief schema's own `1.0.0` nor the health contract's is this version; they are separate documents
with separate histories.)

| Version | Date | Change | Compatibility |
|---|---|---|---|
| 1.0.0 | 2026-10-10 (S0-15) | the contract as frozen: §6.1 – §6.8 | — |
| **1.1.0** | 2026-10-13 (#121, S2) | **adds** §6.9 `POST /ipc/v1/owner` and its §2.1 decision, the `owner_exists` error code (§8.2), its §9 budget, and the fixtures `owner.json` and `error-owner-exists.json` | Purely additive. Nothing already specified changed meaning: every 1.0.0 operation, error code, header, envelope, budget and fixture is untouched, a 1.0.0 client keeps working against a 1.1.0 core, and a 1.1.0 client sees `404` / `not_found` from a 1.0.0 core — which the installer reports as *repair the installation*. Security-critical PR under §13.2 (two reviewers) because it adds a mutating operation (§11.5) |
