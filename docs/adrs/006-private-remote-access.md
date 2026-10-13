# ADR-006: Private remote access is Local Only by default and, when opted in, a single Tailscale Serve origin on a private tailnet — never Funnel

| | |
|---|---|
| **Status** | Proposed |
| **Date** | 2026-10-10 |
| **Deciders** | A (macOS/Swift) and C (TALL/PWA) own; H (Security/privacy) reviews the ACL posture and the push model; B (Laravel platform) reviews the config matrix |
| **Closes** | Blueprint §15 item 8; `docs/OPEN-QUESTIONS.md` §15.8 |

## Context

Blueprint §0 decision 8 promises "privat fjärråtkomst till PWA via valfri Tailscale Serve" that is configured *after* the five-minute local install. §8 fixes the shape of it: "**Standard:** Local Only. **Rekommenderad remote-adapter:** Tailscale Serve (privat tailnet), inte Funnel. Kör lokalt HTTP på `127.0.0.1`, tunnel till en enda origin med giltigt HTTPS-certifikat och korrekta Laravel `APP_URL`, `TrustProxies`, secure cookies/CSRF/sessionkonfiguration. Tailscale app måste finnas på fjärrklienten. Testa iPhone hemskärms-PWA från mobilnät med Tailscale ansluten. Bekräfta tailnet ACL:er och att inte hela datorn exponeras." It adds two hard constraints: "Automatisk tunneluppsättning får aldrig i tysthet aktivera publik exponering" and Cloudflare "**inte standard** i Europe-first-profilen".

§1.2 requires HTTPS for remote installability of the PWA and forbids caching "kundbrev, tokens, credentials eller innehåll med hög känslighet" in the service worker. §8 allows push only "som opt-in, med tidsbegränsade/minimala notifieringspayloads och ingen kundtext i klartext". §11 forbids tokens in push. §3 gives the `TunnelProvider` contract (`inspect()`, `configure()`, `disable()`) that the macOS app and Laravel share.

What breaks if this stays open: workstream C cannot fix the PWA origin, cookie and CSRF behaviour; workstream A cannot build the Remote Access step of the installer (§12.1); the Sprint 4 release gate "Tailscale Serve remote-PWA testad från iPhone utanför LAN; ingen publik exponering i default" (§17) has no definition to test against.

## Decision

### D1. Default Local Only; Remote Access is an explicit, named opt-in

1. Fresh installs run **Local Only**: the Laravel HTTP listener binds `127.0.0.1:<port>` only (never `0.0.0.0`, never a LAN interface). `<port>` is the loopback port fixed by ADR-001/ADR-002; `8787` is used as the placeholder in this document.
2. Remote Access is a separate step in the Mac app, reachable only after core-ready (§12.1 state `Done`), never part of the five-minute path.
3. The confirmation screen must display the **exact origin** that will become reachable, derived from Tailscale itself (`tailscale status --json` → `.Self.DNSName` with the trailing dot removed, e.g. `https://my-mac.example-tailnet.ts.net`), the sentence "Reachable only by devices logged in to your Tailscale network. Not reachable from the public internet.", and a single primary button "Enable private remote access". No pre-ticked checkbox, no auto-enable on install, no enabling from a CLI flag or config file alone.
4. Disabling is one action and removes the Serve configuration the app created (D2.4). Uninstall (§12 / `docs/operations`) runs the same removal.

### D2. The Serve configuration the app applies, verifies and removes (`TunnelProvider`)

The implementation is `App\Support\Install\Tunnel\TailscaleServeTunnelProvider` implementing the §3 `TunnelProvider` contract. It shells out to the Tailscale CLI; it never edits Tailscale's config files, never touches the tailnet policy, and never uses the LocalAPI socket directly in v1.

**CLI binary resolution (in this order, first hit wins):**

| Variant | Detect by | CLI path used |
|---|---|---|
| Standalone (`io.tailscale.ipn.macsys`) — **supported and tested** | `/Applications/Tailscale.app/Contents/Info.plist` `CFBundleIdentifier` = `io.tailscale.ipn.macsys` | `/Applications/Tailscale.app/Contents/MacOS/Tailscale` |
| Mac App Store (`io.tailscale.ipn.macos`) — **supported, untested until the Sprint 4 matrix run** | same plist, `io.tailscale.ipn.macos` | `/Applications/Tailscale.app/Contents/MacOS/Tailscale` |
| Open-source `tailscaled` (Homebrew) — **not supported in v1** | `/opt/homebrew/bin/tailscale` or `/usr/local/bin/tailscale` present *without* `Tailscale.app` | n/a — the app reports "Unsupported Tailscale installation" and links the Standalone download |

The app always uses the absolute bundle path, never `tailscale` from `PATH` (the Standalone launcher `/usr/local/bin/tailscale` is a shell wrapper and may be absent; `PATH` lookups are a hijack surface). Minimum version: 1.52 (the Serve CLI syntax relied on below was introduced in 1.52 per the vendor page); the app refuses older versions with an upgrade hint.

**Pre-flight (`inspect()`), all read-only:**

```text
<cli> version
<cli> status --json
<cli> serve status --json
```

`inspect()` returns a `TunnelStatus` with exactly one of these states:

| State | Condition (from the JSON above) | UI |
|---|---|---|
| `NotInstalled` | no bundle at the paths above | "Install Tailscale (Standalone)" link |
| `NotLoggedIn` | `BackendState` ≠ `Running` (e.g. `NeedsLogin`, `Stopped`) | "Open Tailscale and sign in" — the app never runs `tailscale up`/`login` |
| `HttpsNotEnabled` | `CertDomains` is `null`/empty, or `"https"` missing from `.Self.CapMap` | Explain that an admin must enable **HTTPS certificates** on the tailnet DNS page and that the machine name + tailnet name then appear in public Certificate Transparency logs. The app does **not** enable it on the user's behalf (see Consequences). |
| `Ready` | `Running`, cert domain present, `serve status` = "No serve config" | Confirmation screen (D1.3) |
| `Active` | Serve config contains **exactly** our handler: `Web["<dnsname>:443"].Handlers["/"].Proxy == "http://127.0.0.1:<port>"`, `AllowFunnel` empty, no other `Web`/`TCP`/`Services` entries | "Remote access on — <origin>" |
| `Drifted` | Serve config exists but differs from the line above (other ports, other handlers, foreground sessions) | Show the diff; offer "Replace" (runs `serve reset` then configure) or "Leave as is" |
| `FunnelDetected` | `AllowFunnel` has any `true` value (`ServeConfig.IsFunnelOn()` semantics), or `"funnel"` present in `.Self.CapMap` **and** `AllowFunnel` non-empty | Red banner "Public exposure detected — this was not configured by Laravel Executive AI"; the only offered action is "Remove all Serve configuration" (`serve reset`). Remote Access cannot be enabled while in this state. |

**Configure (`configure(TunnelConfig)`), run only from `Ready` or after user-confirmed `Drifted`→Replace:**

```text
<cli> serve --bg --yes --https=443 http://127.0.0.1:<port>
```

Rationale for each token: `--bg` persists the configuration across CLI exit and reboots (foreground mode dies with the process); `--yes` suppresses interactive prompts because the app runs the CLI without a TTY; `--https=443` makes the origin `https://<machine>.<tailnet>.ts.net` with no port suffix, which is what the iPhone home-screen PWA will be bound to; the target is written as the full loopback URL rather than a bare port so the argv is unambiguous and greppable.

**Verify (part of `configure()` → `TunnelResult`):**

1. `serve status --json` must now parse to the `Active` condition above; anything else is a failure and the app runs `disable()`.
2. The Mac performs a self-request `GET https://<dnsname>/healthz` over its own tailnet address and requires `200` plus the response header `X-Lxai-Origin: <dnsname>` (set by Laravel from the resolved request host, D3). This proves TLS, the proxy and Laravel's origin resolution in one round-trip.
3. The result screen tells the user to install the Tailscale app on the iPhone, sign in to the **same** tailnet, open the origin in Safari, and use Share → Add to Home Screen. The Sprint 4 acceptance test (§17) runs this from a mobile network with Wi-Fi off.

**Disable (`disable()`):**

```text
<cli> serve --https=443 off
```

followed by `serve status --json`, which must report no handler for `<dnsname>:443`. If other handlers the app did not create remain, they are left alone and listed to the user. The app only uses `serve reset` when the user explicitly chooses "Remove all Serve configuration" (`Drifted`/`FunnelDetected`).

**When Tailscale is not installed or not logged in:** nothing is configured, nothing is written to `.env`, Laravel keeps `remote.origin = null`, and the UI shows the state-specific instruction from the table. The app never bundles, installs or signs in to Tailscale.

### D3. Laravel configuration matrix for the tunnelled origin

Laravel always listens on loopback HTTP; the remote origin exists only as the TLS-terminating proxy in `tailscaled`. The app therefore serves **two origins from one process**: the loopback origin (local browsers) and `https://<dnsname>` (tailnet clients). The proxy headers Serve sets were verified in the Tailscale source (`ipn/ipnlocal/serve.go`, function `addProxyForwardedHeaders`): `X-Forwarded-Host` = incoming `Host`, `X-Forwarded-Proto: https` when TLS terminated, `X-Forwarded-For` = the client's tailnet address. Serve also sets `Tailscale-User-Login`, `Tailscale-User-Name`, `Tailscale-User-Profile-Pic` after deleting any client-supplied copies.

**The loopback origin a browser is sent to is `http://localhost:<port>` — one browser host, and not the IPC host** (amended 2026-10-13, issue #109; S2-05 surfaced the consequence that forced the choice).

The two loopback names reach the same socket, and this decision assigns each one job:

| Channel | Host | Why that one |
|---|---|---|
| Browser — the PWA, every URL the product opens | **`localhost`** | A WebAuthn relying-party id cannot be an IP literal, so on `127.0.0.1` a passkey ceremony cannot start at all and §11's passkey requirement is unreachable for everyone who does not use the tailnet origin. `localhost` is a secure context for plain HTTP by the fetch standard, is a legal RP id, and `TrustHosts` already admits it (the row below, S1-04). |
| IPC — `/ipc/*`, and the Supervisor's `/health/*` probes | **`127.0.0.1`**, unchanged | ipc-contract §1.1: the literal IP so no resolver configuration can point the client somewhere else, and so §5.2's `Host` check is one fixed string. This is deliberately *not* the browser host and must not be merged into it; `IpcOriginClientTest` pins that the Swift client never so much as names `localhost`. |

What opens `localhost`: the menu bar's "Open PWA", "Open full brief", diagnostics and the approval deep links (`CoreClient.browserOrigin` over `PwaOrigin`, which canonicalises an `open_url` the core produced on the IP literal), and the installer's Done screen.

**Consequence — a browser request on `127.0.0.1` is redirected to the same path on `localhost`** (`App\Http\Middleware\RedirectToCanonicalHost`, in the `web` group only; `302` for a navigation, `307` otherwise so a POST keeps its body). Session cookies are host-only (`SESSION_DOMAIN = null`, the row below), so without this a bookmark on the IP literal would give a person a second browser identity: a session on one host, a passkey on the other, and no visible reason the passkey is never offered. The redirect excludes `/ipc/*` and `/health/*` — a 3xx there is what the Swift client treats as a hijacked port (§5.2), so redirecting them would turn a working core into "core unreachable". The cookie is not migrated; the person signs in on the canonical host, which is where the product sends them anyway.

What would make this wrong: a browser shipping WebAuthn support for IP-literal relying parties (then `127.0.0.1` could be the single loopback host and the redirect would be dead weight); or a future channel on the `web` group that a non-browser client calls on `127.0.0.1` — that client would be redirected, and the fix is to register it outside the `web` group rather than to loosen the redirect. On an origin that is still an IP literal — a hand-typed address, a bare IP — passkeys stay unavailable and `PasskeyAuthenticator::status()` says so in both languages; the emailed one-time code is unchanged and remains the recovery path (auth.md §7.1).

| Setting | Loopback request (Mac app) | Remote request (via Serve) | How it is decided |
|---|---|---|---|
| Listener bind | `127.0.0.1:<port>` | same process, same bind | fixed; never `0.0.0.0` |
| `APP_URL` | `http://localhost:<port>` | unchanged in `.env` | `APP_URL` is only the fallback for non-HTTP contexts (artisan, queue) — and in those it is a *browser* URL (a queued brief mail's CTA), so it is the canonical host, not the IPC one. Written by the installer (`RuntimeEnvironment.supervisorEnvironment`) |
| `config('remote.origin')` | `null` when disabled | `https://<dnsname>` when `Active` | written by the Mac app on enable/disable (`config/remote.php`, env `REMOTE_ORIGIN`); never derived from a request |
| `TrustProxies` `at:` | `['127.0.0.1', '::1']` | same | `tailscaled` connects to Laravel from loopback, so loopback is the only trusted proxy; `'*'` is forbidden |
| `TrustProxies` `headers:` | `Request::HEADER_X_FORWARDED_FOR \| HEADER_X_FORWARDED_HOST \| HEADER_X_FORWARDED_PROTO` | same | exactly the three headers Serve sets; `HEADER_X_FORWARDED_PORT`, `_AWS_ELB` and `HEADER_FORWARDED` are not trusted |
| `TrustHosts` `at:` | `^127\.0\.0\.1$`, `^localhost$`, `^\[::1\]$` (the IP literals are trusted so `/ipc/*` and `/health/*` answer on them; browser routes on them redirect) | plus `^<dnsname-escaped>$` only when `remote.origin` is set | closure `fn () => config('remote.trusted_hosts')`; `subdomains: false`. Rejects spoofed `X-Forwarded-Host` from other local processes |
| URL generation root | `http://localhost:<port>` (the request's own host, after the redirect) | `https://<dnsname>` | middleware `ResolveRequestOrigin` (after `TrustProxies`/`TrustHosts`) calls `URL::forceRootUrl($request->getSchemeAndHttpHost())` and `URL::forceScheme($request->getScheme())`; queued jobs use `remote.origin ?? APP_URL` |
| `X-Lxai-Origin` response header | the request's own host — `localhost:<port>` for a browser, `127.0.0.1:<port>` for `/ipc/*` and `/health/*` | `<dnsname>` | set by the same middleware; used by D2 verify |
| `SESSION_DRIVER` | `database` (ADR-002 SQLite) | same | one session store, cookies are per-origin anyway |
| `SESSION_SECURE_COOKIE` | `false` (loopback HTTP; the cookie never leaves the machine) | `true` | not a static env value: `ResolveRequestOrigin` sets `config(['session.secure' => $request->isSecure()])` per request. Static `true` would break the loopback WKWebView; static `false` is forbidden on the remote origin |
| `SESSION_SAME_SITE` | `lax` | `lax` | `strict` breaks the notification-tap deep link (D5) which arrives as a top-level navigation |
| `SESSION_DOMAIN` | `null` | `null` | host-only cookies; the two origins never share a cookie — which is why the loopback origin has exactly one browser host and `127.0.0.1` redirects to it |
| `SESSION_HTTP_ONLY` | `true` | `true` | |
| `SESSION_PARTITIONED` | `false` | `false` | single-site app |
| CSRF (`PreventRequestForgery`) | token fallback (`Sec-Fetch-Site` is only sent over HTTPS) | `Sec-Fetch-Site` same-origin check, token fallback | default two-layer mode; `originOnly: true` and `allowSameSite: true` are forbidden |
| `Tailscale-User-*` headers | absent | present | **read only for audit logging** (`audit_events`), never for authentication: the PWA authenticates with the app's own session/passkey (§11). Any request on the loopback origin that carries these headers is logged as anomalous |
| Service worker / manifest | `start_url` `/home`, scope `/` | identical files | cache app-shell only (§1.2); the manifest is origin-relative so the installed PWA is bound to `https://<dnsname>` |
| Outbound SSRF rules (§11) | unchanged | unchanged | the loopback block applies to `Browser/Fetch` tools, not to this listener |

### D4. ACL posture and verification

The tailnet policy is owned by the tailnet admin, not by the app; the app never edits it. The documented posture (`docs/security/`) is:

1. **Tag the Mac** so the rule targets the machine, not the user's whole device set: `tagOwners: {"tag:executive-ai": ["autogroup:admin"]}`, and tag the Mac from the admin console.
2. **Grant only HTTPS to that tag**, deny-by-default handles the rest:

   ```json
   "grants": [
     { "src": ["autogroup:member"], "dst": ["tag:executive-ai"], "ip": ["tcp:443"] }
   ]
   ```

   Replace `autogroup:member` with the specific users or a `group:` on multi-user tailnets. No other grant may name `tag:executive-ai` as `dst`, so SSH (22), the loopback port, Taildrop and every other service on the Mac are unreachable from the tailnet.
3. **Funnel must not be grantable to the Mac:** the `nodeAttrs` entry `{"target": [...], "attr": ["funnel"]}` must not include the Mac (not via `autogroup:member` either when the Mac is tagged — use explicit targets). Vendor doc: "If the funnel node attribute in your tailnet policy file doesn't permit you to use Funnel, you won't be able to."
4. **Verification check** (release gate §17, runbook in `docs/operations/`):
   - On the Mac: `tailscale status --json` → `.Self.Tags` contains `tag:executive-ai`; `.Self.CapMap` has **no** `"funnel"` key.
   - From a second tailnet device (the iPhone with Tailscale, or a Linux node): `curl -sS https://<dnsname>/healthz` → `200`; `nc -vz -w 3 <tailnet-ip> 22` and `nc -vz -w 3 <tailnet-ip> <port>` → timeout/refused.
   - From a device **not** on the tailnet (mobile network, Tailscale off): `https://<dnsname>` does not resolve or does not connect.
   - The app surfaces the first bullet as a read-only self-check on the Remote Access screen; the other two are human steps recorded in the release checklist because the app cannot run them from the Mac.

On a tailnet where the user is the only member and has no tags (the typical personal tailnet, like the one measured below), the default "allow all" policy already limits access to the user's own devices; D4.1–2 is still required for the Sprint 4 gate because "not the whole machine is exposed" cannot otherwise be demonstrated.

### D5. Push model

1. **Opt-in per device**, requested from the installed PWA in response to a tap on "Enable notifications" (Apple requires a user gesture); default off; revocable from `/integrations` and from iOS Settings.
2. **iOS prerequisite shown before the button:** iOS/iPadOS 16.4+, PWA added to the Home Screen (not a Safari tab), served from the HTTPS tailnet origin. Push to the loopback origin is not offered.
3. **Standard Web Push (RFC 8030/8291/8292) with VAPID.** The VAPID key pair is generated once on the Mac and stored in Keychain per ADR-003 under the service identity `se.stafe.executive-ai.webpush`; the public key is served to the PWA; the private key never enters SQLite, logs or the PWA. Per ADR-003, the key pair is written and read only by the PHP core through the `SecretStore` broker; the GUI app never handles it. Subscriptions (`endpoint`, `p256dh`, `auth`, device label, created/last-used) live in a new `push_subscriptions` table (migration, not in the §3.1 list — added by this ADR), encrypted at rest.
4. **Payload: minimal, time-limited, no customer text.** Exactly one JSON shape per type, e.g. `{"t":"brief","id":"01J…","exp":1760100000}` — ≤ 256 bytes; the human-visible title/body is a fixed generic string ("Your executive brief is ready", "1 approval waiting"). No customer names, subjects, amounts, snippets, tokens or URLs with secrets. Headers: `TTL` ≤ 3600 s (approvals: ≤ 900 s), `Urgency: normal`, `Topic` = the type so a newer message replaces an older one. The PWA fetches the actual content over the tailnet origin on tap; if Tailscale is off on the phone, it shows "Connect Tailscale to open".
5. **Transport disclosure.** Sending push means the Mac makes an outbound HTTPS call to the push service of the subscribing browser (`*.push.apple.com` for iOS). This is not model traffic and does not violate Local Only, but it is an external connection and is listed as such in `/integrations` and in the Europe-first disclosure (§10), together with the fact that the payload is end-to-end encrypted (RFC 8291) and contains no customer text even if decrypted.
6. Test: a unit test asserts every outgoing payload validates against the fixed schema and is below 256 bytes; a negative test feeds a brief with customer names and asserts none of them appear in the serialized payload (§14 gate "generated brief no private text in push").

### D6. Hard rule: no code path can invoke Funnel or any public exposure

1. The CLI is only ever invoked through `TailscaleCommand`, a final value object whose constructor accepts a subcommand from the allowlist `{version, status, serve}` and flags from the allowlist `{--json, --bg, --yes, --https=443, off}`; anything else throws before a process is spawned.
2. `tests/Security/TunnelFunnelGuardTest.php`: builds every command the provider can emit (all `TunnelStatus` states × all actions) and asserts (a) no argv element contains the substring `funnel` (case-insensitive), (b) no argv contains `0.0.0.0`, `--http=` or `--tcp=`, and (c) the only mutating commands are the two literal lines in D2. An architecture test additionally asserts the string `funnel` appears in `app/` only in the `FunnelDetected` detection code, which reads JSON and spawns nothing.
3. Funnel detection never shells out to `tailscale funnel`; it reads `AllowFunnel` from `serve status --json` and `"funnel"` from `status --json` `.Self.CapMap`.
4. No `.env` key, config value, CLI flag or feature flag may bypass D1.3 or produce a different Serve/Funnel/Cloudflare configuration. Code review rejects any PR that adds a second tunnel adapter without a new ADR.
5. The listener bind address is a constant, not configuration.

### D7. Cloudflare and other public adapters: deferred

Cloudflare Tunnel (behind Zero Trust) is recorded as a *possible* future `TunnelProvider` implementation, requires its own ADR, must never become the default, and must reuse D1's confirmation pattern with the additional sentence "reachable from the public internet". Nothing in v1 references Cloudflare in code.

## Evidence

**Measured prototype (this Mac, 2026-10-10, read-only; no tailnet configuration was changed):**

- Machine: macOS (Darwin 25.6.0), Tailscale **Standalone** variant — `codesign -dv /Applications/Tailscale.app` → `Identifier=io.tailscale.ipn.macsys`, `TeamIdentifier=W5364U7YZB`; `tailscale version` → `1.98.9`. `/usr/local/bin/tailscale` is the Standalone CLI launcher: a two-line `#!/bin/sh` script executing `/Applications/Tailscale.app/Contents/MacOS/tailscale "$@"` (lower-case name resolves because the volume is case-insensitive; the bundle contains a single `MacOS/Tailscale` binary).
- `tailscale status --json`: `BackendState: Running`, `MagicDNSSuffix: <tailnet>.ts.net`, `.Self.DNSName: <machine>.<tailnet>.ts.net.`, `.Self.Tags: null`, **`CertDomains: null`** (HTTPS certificates not enabled on this tailnet, which is exactly the `HttpsNotEnabled` state of D2), `.Self.CapMap` keys include `https://tailscale.com/cap/is-admin`, `…/is-owner`, `…/ssh`, `…/file-sharing`, `…/tailnet-lock` and **neither `https` nor `funnel`** — confirming that the Funnel attribute is observable from the node and absent here.
- `tailscale serve status` → `No serve config`; `tailscale serve --help` (1.98.9) confirms the syntax relied upon: `USAGE tailscale serve <target>` / `status [--json]` / `reset`; flags `--bg`, `--yes`, `--https value … (default mode)`, `--http`, `--tcp`, `--tls-terminated-tcp`, `--set-path`, `--service`; and the sentence "To share a local server on the internet, use `tailscale funnel`".
- Not yet measured (required before Accepted; owner A + C, Sprint 4): the full enable → iPhone home-screen PWA → mobile-network test on a tailnet with HTTPS enabled; the `Active` JSON shape of `serve status --json`; whether `--yes` would silently enable HTTPS certificates on a tailnet where they are off (D2 avoids the question by refusing to run `serve` in that state, but the behaviour must be confirmed); the D4.4 negative port checks.

**Vendor documentation:**

- Tailscale Serve, https://tailscale.com/docs/features/tailscale-serve (also served as `/kb/1312/serve`), "Last validated Jan 20, 2026": "Tailscale Serve lets you route traffic from other devices on your Tailscale network (known as a tailnet) to a local service running on your device." · "If you'd like to share local services publicly over the internet, use Tailscale Funnel instead." · "Tailscale Serve requires you to enable HTTPS certificates in your tailnet." · "Access control rules apply to Serve just like any other service." · Identity headers `Tailscale-User-Login`, `Tailscale-User-Name`, `Tailscale-User-Profile-Pic` are added and "stripped from incoming requests to prevent spoofing." · "Serving files and directories is limited to the open source variant of the Tailscale client for macOS" (proxying is not limited). · CLI syntax changed "in the 1.52 version of the Tailscale client".
- Tailscale Serve CLI reference, https://tailscale.com/docs/reference/tailscale-cli/serve, "Last validated Jan 26, 2026": usage `tailscale serve [flags] <target>`; `--bg` "run as a background process"; `--yes` "update without prompts"; `--https=<port>` default mode; `status --json`; `reset` clears configuration; disable a single config by appending `off` to the original command; "Tailscale Funnel" exposes services "to the entire internet."
- Tailscale source (headers), https://raw.githubusercontent.com/tailscale/tailscale/main/ipn/ipnlocal/serve.go, `main` as fetched 2026-10-10, `addProxyForwardedHeaders`: `r.Out.Header.Set("X-Forwarded-Host", r.In.Host)`; `if r.In.TLS != nil { r.Out.Header.Set("X-Forwarded-Proto", "https") }`; `r.Out.Header.Set("X-Forwarded-For", c.SrcAddr.Addr().String())`. `addTailscaleIdentityHeaders` deletes `Tailscale-User-*`, `Tailscale-Funnel-Request`, `Tailscale-Headers-Info` from the incoming request and, for Funnel requests, sets only `Tailscale-Funnel-Request: ?1`. Source, not a KB page — hence "verified in source, not in vendor prose" in the matrix.
- Tailscale source (serve config), https://raw.githubusercontent.com/tailscale/tailscale/main/ipn/serve.go, fetched 2026-10-10: `AllowFunnel map[HostPort]bool` "is the set of SNI:port values for which funnel traffic is allowed"; `Web map[HostPort]*WebServerConfig` "maps from "$SNI_NAME:$PORT" to a set of HTTP handlers keyed by mount point"; `HTTPHandler.Proxy string // http://localhost:3000/, localhost:3030, 3030`; `func (sc *ServeConfig) IsFunnelOn() bool` iterates `AllowFunnel` and `Foreground`.
- Tailscale source (capabilities), https://raw.githubusercontent.com/tailscale/tailscale/main/tailcfg/nodecap/nodecap.go, fetched 2026-10-10: `HTTPS Cap = "https"`, `Funnel Cap = "funnel"`, `Admin Cap = "https://tailscale.com/cap/is-admin"`, `SSH Cap = "https://tailscale.com/cap/ssh"` — the keys D2/D4 read from `.Self.CapMap`.
- Tailscale Funnel, https://tailscale.com/kb/1223/funnel, "Last validated Jan 20, 2026": Funnel routes "traffic from the broader internet to a local service"; requires "a funnel node attribute in your tailnet policy file", HTTPS enabled, and listens only on ports 443, 8443 and 10000; "If the funnel node attribute in your tailnet policy file doesn't permit you to use Funnel, you won't be able to."
- Tailscale policy syntax, https://tailscale.com/kb/1337/acl-syntax, "Last validated Apr 8, 2026": `nodeAttrs` example `{"target": ["autogroup:member"], "attr": ["funnel"]}` "lets members of the tailnet use Tailscale Funnel on their nodes"; "Every tag name must start with the prefix `tag:`"; `dst` entries are `<host>:<ports>`.
- Tailscale grants syntax, https://tailscale.com/docs/reference/syntax/grants, "Last validated January 5, 2026": fields `src`, `dst`, `ip`, `app`, `srcPosture`, `via`; `ip` accepts `"tcp:443"`; "access is only permitted if explicitly granted"; "application capabilities only apply if the device has the network level access".
- Tailscale macOS variants, https://tailscale.com/kb/1065/macos-variants, "Last validated Jan 5, 2026": three variants — Standalone ("Always start by downloading and installing our Standalone variant macOS app"; "Security updates are promptly distributed since they don't need to undergo Apple's App Store review process"), Mac App Store ("Cannot support Funnel"; Screen Time web filter conflicts), open-source `tailscaled` ("Does not include a graphical user interface").
- Tailscale CLI on macOS, https://tailscale.com/kb/1080/cli, "Last validated Jul 30, 2026": the GUI app (App Store and Standalone) embeds the CLI at `/Applications/Tailscale.app/Contents/MacOS/Tailscale`; the Standalone variant can install a launcher at `/usr/local/bin/tailscale` from its settings (macOS 13.0+).
- Tailscale MDM for macOS, https://tailscale.com/docs/integrations/mdm/mac, "Last validated Jan 5, 2026": "the bundle identifier for the Mac App Store variant of Tailscale, `io.tailscale.ipn.macos`. If you are configuring the Standalone variant of the app, replace it with the `io.tailscale.ipn.macsys` bundle identifier."
- Tailscale HTTPS certificates, https://tailscale.com/kb/1153/enabling-https, "Last validated Dec 10, 2025": enable via the admin console DNS page, MagicDNS required; "Do not enable the HTTPS feature if any of your machine names contain sensitive information" because machine name + tailnet name are recorded in public Certificate Transparency logs; "Your certificate's private key and your Let's Encrypt (ACME) account's private key are generated and stored locally on your machine and Tailscale never sees them."
- Laravel 13 HTTP Requests, https://laravel.com/docs/13.x/requests, fetched 2026-10-10: `$middleware->trustProxies(at: [...])`; `trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | HEADER_X_FORWARDED_HOST | HEADER_X_FORWARDED_PORT | HEADER_X_FORWARDED_PROTO | HEADER_X_FORWARDED_AWS_ELB)`; `trustProxies(at: '*')` exists (forbidden here); `trustHosts(at: [...], subdomains: false)` and `trustHosts(at: fn () => config(...))`; "the `Host` header's value will be used when generating absolute URLs".
- Laravel 13 CSRF, https://laravel.com/docs/13.x/csrf, fetched 2026-10-10: `PreventRequestForgery` "first checks the browser's `Sec-Fetch-Site` header … If origin verification does not pass … the middleware falls back to traditional CSRF token validation"; "The `Sec-Fetch-Site` header is only sent by browsers over secure (HTTPS) connections"; `preventRequestForgery(originOnly: true)` and `allowSameSite: true` exist (both forbidden here).
- Laravel 13 Session, https://laravel.com/docs/13.x/session, fetched 2026-10-10: `config/session.php`, default driver `database`; drivers `file`, `cookie`, `database`, `redis`, `array`.
- WebKit, "Web Push for Web Apps on iOS and iPadOS", https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/, Feb 16, 2023: "with iOS and iPadOS 16.4, we are adding support for Web Push to Home Screen web apps"; "A web app that has been added to the Home Screen can request permission to receive push notifications as long as that request is in response to direct user interaction"; "the same W3C standards-based Web Push that was added in Safari 16.1 for macOS Ventura"; "uses the same Apple Push Notification service"; "allow URLs from `*.push.apple.com`"; manifest `display` must be `standalone` or `fullscreen`. (Apple's developer.apple.com page on the same topic is JavaScript-rendered and could not be captured verbatim; the WebKit post is Apple's own announcement.)
- RFC 8030 (Web Push protocol), https://datatracker.ietf.org/doc/html/rfc8030: §7.2 "Push services MUST NOT return a 413 status code in responses to an entity body that is 4096 bytes or less in size"; §5.2 "An application server MUST include the TTL (Time-To-Live) header field"; §5.3 `Urgency`; §5.4 `Topic` "MUST be restricted to no more than 32 characters". D5's 256-byte cap is 1/16 of the guaranteed limit.

**Legal / licensing input:** none required. Tailscale is used as a user-installed third-party application through its public CLI; nothing from Tailscale is bundled or redistributed. ADR-007 covers the Web Push library license when it is chosen.

## Alternatives considered

1. **Tailscale Funnel** — public HTTPS URL, no client app needed on the phone. Ruled out by §8 ("inte Funnel") and §17 ("ingen publik exponering i default"): it exposes an authentication surface holding customer data to the whole internet, requires the `funnel` node attribute, and the App Store variant cannot use it at all.
2. **Cloudflare Tunnel + Zero Trust Access** — works without a VPN client and gives SSO at the edge. Ruled out as default by §8 ("inte standard i Europe-first-profilen"): every request transits a US-headquartered edge, a Cloudflare account and a public hostname become prerequisites, and the free Zero Trust tier has seat limits. Kept as D7.
3. **Plain Tailscale without Serve** — expose Laravel directly on the Tailscale interface (`100.x.y.z:<port>`, HTTP). Ruled out because the PWA then has no valid HTTPS certificate: not installable from Safari, no service worker, no Web Push (§1.2), and cookies would be sent in cleartext across the tailnet.
4. **Terminate TLS in Laravel/PHP itself with a `tailscale cert` certificate** — avoids the proxy and the forwarded headers. Ruled out: `tailscale cert` certificates expire after 90 days and "you are responsible for renewing the certificate" (vendor doc), the PHP built-in server is not a TLS server, and it reintroduces a key-management surface that Serve handles inside `tailscaled`.
5. **LAN-only exposure (bind `0.0.0.0` on the home network)** — simplest. Ruled out: no HTTPS, no origin stability, reachable by every device on any Wi-Fi the Mac joins, and it fails the "from mobile network" requirement of §8.
6. **Open-source `tailscaled` via Homebrew as the supported distribution** — scriptable and sudo-less for `serve`. Ruled out for v1: contradicts §12 "no Homebrew", has no GUI for the sign-in flow, and Tailscale's own guidance is to start with Standalone.

## Consequences

Easier:
- One origin per mode, so the PWA, service worker, cookies and push subscriptions have a stable identity; the remote origin is simply the machine's MagicDNS name.
- The Mac app needs only three read-only CLI calls and two mutating ones; the `TunnelProvider` surface is small enough to fixture-test completely.
- `Tailscale-User-Login` gives a free, spoof-resistant audit attribute for remote requests.

Harder:
- Enabling HTTPS certificates publishes the machine name in Certificate Transparency logs. The app refuses to enable HTTPS itself and must explain this to the user in the `HttpsNotEnabled` state; users who care should rename the Mac first. This is one extra admin-console step before Remote Access works.
- Renaming the Mac changes the origin and orphans the installed iPhone PWA and its push subscription; the app warns about this on the confirmation screen and on rename detection (`DNSName` changed since enable).
- The remote client always needs the Tailscale app signed in to the same tailnet; sharing the PWA with a colleague means sharing the node or inviting them to the tailnet — a tailnet-admin task, not an app task.
- Session `secure` becomes per-request computed state; tests must cover both origins.
- The loopback mode has two host names with different jobs (D3): `localhost` for browsers, `127.0.0.1` for IPC and the health probes. Anything new on loopback has to say which it is, and a browser-facing URL built from the IPC origin is a defect even though it loads — the passkey it silently removes is the only symptom.
- `push_subscriptions` is a new table and a new Keychain identity (ADR-003 inventory must list `se.stafe.executive-ai.webpush`).

**Forbidden in code review from now on:**
- Any argv, config or string containing `funnel` outside the detection code and its tests; any invocation of `tailscale up`, `login`, `set`, `cert`, or `funnel`.
- Binding the Laravel listener to anything but `127.0.0.1`/`::1`.
- Handing a browser a loopback URL built on `127.0.0.1` (use `CoreClient.browserOrigin` / `PwaOrigin` in Swift, a relative URL or `url()` in Laravel), or naming `localhost` anywhere in the IPC client — the two are different hosts on purpose (D3), and `IpcOriginClientTest` and `PwaOriginTests` pin both directions.
- Redirecting, rewriting or canonicalising `/ipc/*` or `/health/*`: a 3xx there is what the Swift client treats as a hijacked port (ipc-contract §5.2). A new non-browser endpoint belongs outside the `web` group rather than on the redirect's exclusion list.
- `trustProxies(at: '*')`, trusting `HEADER_FORWARDED`/`X_FORWARDED_PORT`/`_AWS_ELB`, `preventRequestForgery(originOnly: true)` or `allowSameSite: true`.
- Using `Tailscale-User-*` headers for authentication or authorization.
- Static `SESSION_SECURE_COOKIE=false` on the remote origin; `SESSION_SAME_SITE=none`.
- Any push payload field carrying customer-derived text, a token, or a URL with a secret; any `TTL` above 3600 s.
- Service-worker caching of `/briefs/*`, `/crm/*`, `/inbox/*` responses or any `Set-Cookie`-bearing response (§1.2).
- A Cloudflare or other public adapter without a new ADR.

## Residual risks

1. **Local header spoofing on loopback.** Any process on the Mac can connect to `127.0.0.1:<port>` and send `X-Forwarded-*` headers that Laravel trusts (because `tailscaled` is also loopback). Mitigated by `TrustHosts` (only the two known hosts), by not using forwarded headers for auth, and by the macOS sandbox of the app; a malicious local process already has broader access. Accepted by **H**.
2. **Certificate Transparency disclosure** of `<machine>.<tailnet>.ts.net`. Inherent to Let's Encrypt; mitigated by the warning and by the machine name carrying no customer data. Accepted by **H**, communicated to the user by **A**.
3. **Tailnet compromise = app reachability.** A stolen, still-signed-in tailnet device reaches the login page. Mitigated by §11 (passkeys/MFA, device management, re-auth) and by Tailscale's own device approval/key expiry; the app does not trust the tailnet as an authenticator. Accepted by **H**.
4. **Vendor CLI drift.** Serve's CLI changed in 1.52 and may change again; `status --json` shapes are not a documented contract. Mitigated by the minimum-version check, the fixture-tested JSON parser and the reopen condition below. Accepted by **A**.
5. **App Store variant untested** until the Sprint 4 install matrix; a user with that variant may hit a difference the matrix did not foresee. Accepted by **A**.
6. **Push metadata.** Even with an opaque payload, Apple's push service learns *that* and *when* a notification was sent to a device. Disclosed under D5.5; accepted by **H** and the owner (Europe-first profile, §10).

## Blueprint references

§0 (decision 8), §1.2, §3, §8, §10, §11, §12.1, §14, §15.8, §17, §18.

## Reopen conditions

- Tailscale changes `tailscale serve` syntax, removes `--bg`/`--yes`/`off`, or changes the `serve status --json` / `status --json` shapes relied upon in D2 (`Web`, `AllowFunnel`, `CertDomains`, `CapMap`).
- Tailscale ships a documented, supported way for a third-party app to configure Serve without the CLI (stable LocalAPI or an app-capability grant) — revisit D2.
- Apple changes the Home-Screen requirement or VAPID support for Web Push on iOS, or introduces payload limits below 256 bytes.
- The Sprint 4 iPhone/mobile-network measurement fails on the Standalone variant, or the App Store variant turns out not to support Serve proxying.
- A customer segment requires access from devices that cannot run Tailscale (managed phones, kiosks) — then D7 (Cloudflare behind Zero Trust) gets its own ADR; it still cannot become the default.
- `--yes` is shown to enable HTTPS certificates on a tailnet where they were off — then D2 must add an additional guard or drop `--yes`.
