# Authentication — owner account, one-time-code login, passkeys, sessions, re-auth (S1-02, S2-05)

**Status:** Sprint 1 (S1-02) extended by Sprint 2 (S2-05) · **Workstream:** B (Laravel platform)
with H (security) · **Issues:** #30, #69
**Binding references:** blueprint §11 (passkeys/MFA, secure sessions, revocation, device
management, re-auth), §12.1 (`CREATE_OWNER`, no Terminal), §12.3 screen 4; ADR-006 D3 (two origins,
cookie posture, `rp.id` per origin); ADR-007 (dependency licences); threat model T-70, T-71, T-72
and open question A16; health contract §2 `auth_login`; ipc-contract §7.3 (the Supervisor's
elevation, the IPC-side counterpart of §7.4).
**Code:** `app/Security/Authorization/` (`Otp/`, `Passkeys/`, `Devices/`, `Reauthentication/`,
`SessionRevoker`), `app/Security/Audit/AuditLog.php`, `app/Livewire/Auth/`
(`Login`, `Passkeys`, `Devices`, `Reauthenticate`), `app/Livewire/Install/CreateOwnerForm.php`,
`app/Http/Middleware/{SecurityHeaders,ResolveRequestOrigin,EnsureNoOwnerExists}.php`,
`app/Http/Controllers/Pwa/SecuritySettingsController.php`, `routes/auth.php`, `routes/pwa.php`,
`resources/js/passkeys.js`, `resources/views/{layouts/auth,livewire/auth,livewire/install,mail/auth,settings}`.
**Tests:** `tests/Feature/Auth/`, `tests/Feature/Health/LoginSelfTestTest.php`,
`tests/Security/{OtpCredentialNeverLoggedTest,PasskeyPayloadNeverLoggedTest,ReauthRequiredTest}.php`.

## 1. Principles

- **There is no password.** Every sign-in is either the six-digit one-time code mailed to the
  account's address or a registered passkey. `users.password` stays nullable and is never read or
  written; no password route exists (`AuthRoutesTest`). A passkey is an **addition**: the emailed
  code stays the fallback and the recovery path on every origin and every device (§7).
- **One owner.** The installer creates exactly one owner account; a repeated install cannot create
  a second one, silently or otherwise (§2, T-72).
- **Sessions are server-side state.** The database `sessions` table is the source of truth, so a
  session can be ended from the server whatever cookie a browser still holds (§5, T-70).
- **The credential exists in one place.** The code is in the single outgoing mail and nowhere else:
  not in the log, the audit trail, the database, the cache, a command's output or a rendered page
  (§8).
- **Local convenience never leaks into production.** The one-click autofill is gated on
  `APP_ENV=local` in code, not only in config (§4).

## 2. Owner creation — `CREATE_OWNER`

`App\Security\Authorization\CreateOwner::create(OwnerDetails, actor)` creates the organization, the
user and the owner membership in **one transaction behind a process-wide cache lock**
(`lea:create-owner`), so two installer runs cannot race past the owner-exists check. It moves the
install marker from `not_installed` to `installing` and leaves it there — `HEALTH_CHECK`, not this
step, decides `core_ready` — and writes `auth.owner_created`.

If an owner membership already exists it writes `auth.owner_create_refused` and throws
`OwnerAlreadyExistsException`. Nothing is created, nothing is overwritten.

Three front doors, same action:

| Door | Who uses it | Behaviour |
|---|---|---|
| **`POST /ipc/v1/owner`** ([ipc-contract.md](ipc-contract.md) §6.9, #121) | **The Mac installer's screen 4** (`IpcOwnerCreator`), over the `menubar` session paired one state earlier at `BOOT_SERVICES` — the user never sees a Terminal | Body `{name, email, organization?, timezone?, language?}`; `organization` omitted names the workspace after its owner. `200 {"created": true}` and nothing else — no token, no id, not the submitted address. **`409 owner_exists`** when one exists, which the installer reports as "already existed" and *advances past* `CREATE_OWNER` on a data-preserving reinstall. This is the one IPC operation with no owner-bound gate (§2.1 there): a valid `menubar` token **plus** no owner is the authorization, enforced by `IpcRequireNoOwner`. Actor in the audit trail: `job:IpcCreateOwner`, with `via: ipc`. |
| `php artisan lea:owner:create --name= --email= --organization= [--timezone=UTC] [--language=en] [--json]` | Support and developers; the fallback when there is no GUI | Exit `0` created · `2` invalid input · **`3` an owner already exists**. `--json` prints one line: `{"status":"created","user_id","organization_id","membership_id","email","install_state"}` or `{"status":"owner_exists"\|"invalid","message","errors"}`. Nothing printed is ever a credential. |
| `GET /install/owner` (Livewire `CreateOwnerForm`) | A browser on the loopback origin before an owner exists | §12.3 screen 4: name, email, organization, time zone, brief language, one CTA. The person at this page *is* the installer, so success signs them in directly and lands on `/home`. **404 once an owner exists** (`EnsureNoOwnerExists`). |

All three refuse identically once an owner exists, and all three write the same two audit actions —
`auth.owner_created` and `auth.owner_create_refused` — differing only in the actor, which names the
door (`job:IpcCreateOwner`, `job:OwnerCreateCommand`, `job:CreateOwnerForm`). `CreateOwner::create`
takes an optional context array so a door with request context (the IPC operation's `via`, `client`,
`session_id` and `request_id`) records it without a second audit writer.

`OwnerDetails` validates once for both doors (`email:rfc`, `timezone:all`, BCP-47 language) and
stores the address lower-cased. `email_verified_at` is left null until the first code is verified.

## 3. One-time-code login

Routes (all in `routes/auth.php`, inside the `web` group, served with `Cache-Control: no-store`):
`GET /login` (Livewire `Login`, `guest`), `POST /logout` (`auth`), and the signed-in skeleton
`GET /home`, `GET /briefs` (`auth`; S1-04 replaces the placeholder closures, the paths and middleware
are the contract). Unauthenticated requests redirect to `/login`; `/login` without an owner redirects
to `/install/owner`.

### 3.1 State machine

```text
             request code                              submit code
 [email] ──────────────▶ OtpIssuer::issue() ──mail──▶ [code] ─────────────▶ OtpVerifier::verify()
    ▲                      │                                                     │
    │  unknown address:    │ cooldown (60 s) & live & not locked:                │ latest unconsumed row
    │  same reply, no      │   REUSED  (no mail, auth.otp_reused)                │   none ............ NoActiveCode
    │  mail, no row        │ else MINT: expire active rows, store HMAC,          │   attempts ≥ 5 .... LockedOut   (auth.otp_locked_out)
    │                      │   send mail synchronously (auth.otp_issued)         │   expires_at ≤ now  Expired     (auth.otp_failed)
    │                      ▼                                                     │   mismatch ........ attempts++ → Invalid (auth.otp_failed)
    └───── "use a different address" ◀──────────────────────────────────────────┤                     5th mismatch → LockedOut
                                                                                 └── match ........... consumed_at=now, last_login_at,
                                                                                                      login_count++, email_verified_at ??= now
                                                                                                      (auth.otp_verified) → Auth::login,
                                                                                                      session()->regenerate() (auth.logged_in) → /home
```

### 3.2 Rules (the owner's login rule, made testable)

| Rule | Where | Test |
|---|---|---|
| 6 digits from `random_int`, 10-minute expiry | `OtpIssuer::mint()`, `config('lea.auth.otp')` | `OtpLoginTest` |
| Hashed at rest: HMAC-SHA256 keyed with `APP_KEY`, constant-time `hash_equals` | `OtpHasher` | `OtpCredentialNeverLoggedTest` (plaintext never in `otp_codes`) |
| Single use | `consumed_at`; the verifier only looks at unconsumed rows | "accepts a code once only" |
| 5 attempts then lockout; a locked code is never reused inside the cooldown | `attempts`, `OtpCode::isLockedOut()`, `OtpIssuer::reusable()` | "locks the code after five wrong attempts" |
| **Double-send protection**: the button is disabled client-side while the request runs (`wire:loading.attr="disabled"`); server-side a live code issued < 60 s ago is reused behind `Cache::lock('lea:otp:issue:{user}')`, so at most one mail per cooldown | `OtpIssuer::issue()`, `login.blade.php` | "sends exactly one mail for three rapid requests" |
| Unknown address answered identically, nothing sent, nothing stored | `Login::sendCode()` | "answers an unknown address exactly like a known one" |
| Request rate limit: 5 code requests per address+IP per 15 minutes, in the page, **not** in the issuer (the health self-test issues codes every minute) | `RateLimiter` in `Login::sendCode()` | "rate-limits code requests" |
| Mail sent synchronously, never queued; subject carries no code; HTML + plaintext parts from `MailTokens` | `OtpMail` | `JobsCarryNoSecretValueTest` family stays green |
| Audit events for issued / reused / verified / failed / locked-out / logged-in / logged-out / revoked, never containing the code | `AuditLog` (redacts `context` through `Redactor`) | `OtpCredentialNeverLoggedTest` |

Why HMAC rather than bcrypt: a six-digit code has ~20 bits of entropy, so a stolen table of bcrypt
digests is brute-forced in a million guesses; a keyed MAC makes the stored value useless without the
application key, which the packaged app keeps outside the database (ADR-003). The real protection
is the attempt limit and the expiry; the hash only ensures a database read never yields a usable code.

## 4. Local one-click login (`OTP_AUTOFILL`)

`App\Security\Authorization\Otp\OtpAutofill::isActive()` is true only when **both**
`app()->environment('local')` and `config('lea.auth.otp.autofill')` (`OTP_AUTOFILL=true`) hold.
Then `/login` pre-fills the owner's address and, after issuing the code, verifies it in the same
request — one click, no mail to open. The environment check is on the application, so a stray
`OTP_AUTOFILL=true` in a production `.env` does nothing; `OtpAutofillTest` proves it is inert in
`testing` and `production`, and off in `local` without the flag.

## 5. Sessions, cookies and revocation

- **Cookie posture** (ADR-006 D3): `SESSION_DRIVER=database`, `SESSION_DOMAIN=null` (host-only),
  `HttpOnly`, `SameSite=lax`, and `secure` computed **per request** by
  `App\Http\Middleware\ResolveRequestOrigin` (`config(['session.secure' => $request->isSecure()])`),
  which also forces the URL root to the request's scheme and host and answers `X-Lxai-Origin: <host>`
  for the ADR-006 D2 self-check. It runs first in the `web` group. The `TrustProxies`/`TrustHosts`
  half of D3 lands with the remote origin (E4-1).
- **Login** regenerates the session id; **logout** (`POST /logout`) invalidates the session and
  rotates the CSRF token.
- **Revocation**: `App\Security\Authorization\SessionRevoker` is the **only** code that deletes
  session rows. `revokeAll(User, actor, ?exceptSessionId)` clears the user's rows from `sessions` and
  rotates `remember_token`; `revokeSession(User, sessionId, actor)` (S2-05) ends exactly one. Either
  way the browser is signed out on its next request (`SessionRevocationTest` and
  `DeviceManagementTest` drive real requests with the cookie across a simulated new PHP process).
  `php artisan lea:sessions:revoke {ulid|email} [--json]` is the operator and Supervisor entry point.

### 5.1 Device management (S2-05)

A **device** is a sign-in: `auth_devices` records the session it produced, how the person
authenticated (`otp`, `passkey`, `owner_creation` — there is deliberately no `password` case), a
short label derived from the User-Agent, the IP and the times. `App\Livewire\Auth\Devices`, on
`/settings/security`, lists and ends them; `App\Security\Authorization\Devices\DeviceRegistry` is the
service behind it.

| Behaviour | Where | Note |
|---|---|---|
| Record the sign-in | `DeviceRegistry::record(User, Request, LoginMethod, ?PasskeyCredential)`, called by `Login` and `CreateOwnerForm` after `session()->regenerate()` | The session id comes from the store, not the request, so it is the id the browser will present |
| List | `DeviceRegistry::active(User, ?currentSessionId)` | Joins `auth_devices` against the live `sessions` rows: a device whose session is gone is **not** listed, because it is already signed out. The current one is flagged, never revocable from this page ("Log out" does that) |
| Revoke one | `DeviceRegistry::revoke(User, deviceId, actor)` → `SessionRevoker::revokeSession()` | Another user's device id and an unknown id both return `false` and change nothing |
| Revoke every other | `DeviceRegistry::revokeOthers(User, currentSessionId, actor)` → `SessionRevoker::revokeAll(except:)` | Marks each row revoked and writes `auth.sessions_revoked` |
| Labels | `Devices\DeviceLabel::fromUserAgent()` | "Chrome · Mac", "Safari · iPhone", "Mac app · Mac", else "Unrecognised browser". The full User-Agent stays on the row; the view model carries only the label, so a screenshot of the page is not a fingerprint |

Audit events: `auth.device_revoked`, plus the `SessionRevoker`'s own `auth.session_revoked` /
`auth.sessions_revoked`. `AuthDevice` hides `session_id` from serialisation, so a dump of the model
cannot carry a live session id into a log line.

## 6. Health: the login self-test

`LoginSelfTest` (run by `health:selftest` every minute, read by the `auth_login` check) now has three
parts, each recorded in the heartbeat so the check names what failed:

1. **owner** — an owner membership exists and the web guard's provider resolves the user;
2. **session** — a `SessionGuard` over an isolated in-memory `Store` and a synthetic loopback request
   logs the owner in, reports them authenticated and logs out; the real guard and session store are
   untouched;
3. **otp** — the real `OtpIssuer::mint()` (no mail) and the real `OtpVerifier::verify()` round-trip
   inside a transaction that is **always rolled back**: the owner's live code, its attempt count and
   the audit trail are exactly as they were (`LoginSelfTestTest`).

`AuthLoginCheck` reports `owner account exists; login self-test failed (otp round-trip) <time>` when
a part fails, and the ready document drops.

## 7. Passkeys (S2-05)

`App\Security\Authorization\Passkeys\PasskeyAuthenticator` is the interface; the binding is
`WebAuthnAuthenticator` over **`web-auth/webauthn-lib` 5.3.9** (MIT, so ADR-007's licence rule is
satisfied). The Sprint 1 `NotYetAvailable` stub, its binding, `PasskeyStubTest` and the login-page
status row were deleted in the same PR that added this section.

Why a library rather than our own: a relying party has to parse CBOR attestation objects, load COSE
keys, verify ES256/RS256 signatures and apply the full W3C step list. That is exactly the kind of
code where a subtle mistake is silent, and the library is the maintained, audited implementation the
PHP ecosystem standardises on. The verification steps are the library's; the policy around them is
ours.

### 7.1 One relying party per origin (ADR-006 D3)

`rp.id` is **never configured**: `RelyingParty::fromRequest()` takes it from the host the request
arrived on, so the loopback origin and the Tailscale Serve origin are different relying parties. A
credential registered on one is useless on the other — the browser will not offer it, and an
assertion carrying the wrong `rpIdHash` is refused by `CheckRelyingPartyIdIdHash`
(`PasskeyRegistrationTest`, "gives each ADR-006 D3 origin its own relying party"). The credential row
stores both the `rp_id` and the full origin, and every query is scoped by `rp_id`.

WebAuthn needs a domain name in a secure context, and browsers refuse an IP literal as an RP id, so
no ceremony can run on `http://127.0.0.1:<port>` at all. **ADR-006 D3 therefore makes
`http://localhost:<port>` the loopback origin the product opens** (issue #109, amended after this
section first landed): the menu bar's "Open PWA", "Open full brief", diagnostics and approval deep
links are built on `CoreClient.browserOrigin`, the installer's Done screen opens the same host, and
`APP_URL` — the fallback `url()` uses in a queued job, so the host in a brief mail's CTA — is that
host too. `localhost` is a secure context for plain HTTP, is a legal RP id, and is already in
`TrustLoopbackHosts` (S1-04). So the owner who reaches the PWA the normal way gets working passkeys;
there is no origin the product opens where the §11 requirement is unreachable.

Two consequences are load-bearing:

- **A browser request on `127.0.0.1` is redirected to the same path on `localhost`**
  (`App\Http\Middleware\RedirectToCanonicalHost`, `web` group only; `CanonicalHostTest`). Session
  cookies are host-only (§5), so a bookmark on the IP literal would otherwise leave a person with a
  session on one host and a passkey on the other, and nothing on screen explaining it. One browser
  host means one place a credential can live.
- **The IPC origin is deliberately different and unchanged.** `/ipc/*` is the literal `127.0.0.1`
  by contract (ipc-contract §1.1, so no resolver can redirect the Mac app) and `/health/*` is what
  the Supervisor probes; the redirect excludes both, because the Swift client treats a 3xx as a
  hijacked port and would report "core unreachable" (§5.2). Trusting a host and opening a browser on
  it are two different questions: `TrustedHostsTest` records that the IP literals stay trusted.

An origin that *is* an IP literal is still possible — a hand-typed address, a bare IP — and there
`status()` says so in plain words, the ceremonies throw `PasskeysUnavailableException` rather than
failing inside the browser, and the emailed code is the only way in, which is the S1-02 behaviour
unchanged. The tailnet name gives passkeys the same way `localhost` does.

### 7.2 The ceremonies

| Step | Server | Browser |
|---|---|---|
| Register | `registrationOptions(User)` — 32-byte challenge, `userVerification: required`, `residentKey: required`, `attestation: none`, already-registered credentials in `excludeCredentials` | `resources/js/passkeys.js` on `passkey:create`, through Vite with `Vite::cspNonce()`; no inline script, no CDN |
| | `completeRegistration(User, credential, ?name)` — the library's creation ceremony, then one `passkey_credentials` row | — |
| Authenticate | `authenticationOptions(email)` — the address is a **hint only**: an unknown one yields the same shape with an empty `allowCredentials`, so the page never reveals whether an account exists | `passkey:get` → `completePasskey` |
| | `completeAuthentication(credential)` — returns the `User` or `null`; updates the sign counter, the backup flags and `last_used_at` | — |

The challenge lives in the **session** for the length of one ceremony (`PendingCeremonies`,
`ceremony_ttl_seconds`, default 120 s) and nowhere else. It is pulled out on first use — verified or
not — so a replayed assertion has no challenge to match, and it is refused if the origin completing
the ceremony is not the origin that issued it. A failed ceremony answers one of a fixed set of
reason codes (`PasskeyCeremonyFailed`), never a message built from the payload.

`attestation: none` is deliberate: which authenticator make the owner uses is not a trust decision
for a single-owner, local-first product, and requesting attestation would only add an identifying
certificate we would then have to store. `userVerification: required` is what makes a passkey a
proof of presence *and* of a local unlock, which §7.4 relies on.

### 7.3 Where it appears

- `/login` offers "Sign in with a passkey" only when the origin supports passkeys, the browser
  reports support, and a credential exists **for that origin**. The emailed code is always above it.
- `/settings/security` (`App\Livewire\Auth\Passkeys`) lists, adds and removes passkeys. Removing the
  last one is allowed — the code is the recovery path, so a passkey is never load-bearing.
- EN/SV copy lives in `lang/{en,sv}/security.php`.

### 7.4 Re-authentication for critical actions — the API other items call

`App\Security\Authorization\Reauthentication\Reauthenticator` is the **PWA-side** primitive for
§11's "re-auth för kritiska åtgärder" (threat model T-71). The menu bar has no PWA session, so its
critical IPC operation uses the Supervisor's elevation instead (ipc-contract §7.3) — a different
mechanism for a different surface; approvals are never grantable over IPC at all.

A proof is bound to **three** things: the action, a single-use nonce, and a time window. **A merely
fresh session never satisfies it** — that is the property `ReauthRequiredTest` proves for each of
T-71's three paths, named once in `CriticalAction`:

```php
use App\Security\Authorization\Reauthentication\{CriticalAction, Reauthenticator, ReauthenticationRequired};

// 1. The consumer opens a challenge and keeps the nonce with whatever it is about to do.
$challenge = app(Reauthenticator::class)->challenge(CriticalAction::ApproveOutboundAction);

// 2. It renders the card, which proves presence with a passkey or the emailed code and
//    dispatches `reauth-satisfied` when it is done:
//    <livewire:auth.reauthenticate :action="$challenge->action->value" :nonce="$challenge->nonce" />

// 3. Immediately before the irreversible step — never earlier:
try {
    $proof = app(Reauthenticator::class)->assert(CriticalAction::ApproveOutboundAction, $nonce);
} catch (ReauthenticationRequired $e) {
    // $e->reason is missing | action_mismatch | not_verified | expired | consumed
    return $this->askAgain($e);
}

$this->approve($proposal, $proof);   // $proof: action, nonce, method, verifiedAt, expiresAt
```

| Member | Meaning |
|---|---|
| `challenge(CriticalAction): ReauthChallenge` | Issues `{action, nonce, expiresAt}`; the nonce is stored in the session against the action. Writes `auth.reauth_challenged` |
| `passes(CriticalAction, nonce): bool` | Whether `assert()` would succeed right now, **without** spending the proof. For enabling a button |
| `assert(CriticalAction, nonce): ReauthProof` | Spends the proof and returns it, or throws `ReauthenticationRequired`. Writes `auth.reauth_consumed` / `auth.reauth_refused` |
| `sendCode(): IssuedOtp` | The fallback factor: issues or reuses a code for the signed-in owner, under S1-02's double-send rule |
| `satisfyWithOtp(nonce, code): OtpVerification` · `satisfyWithPasskey(nonce, credential): bool` · `passkeyOptions(): array` | What the card calls; a passkey belonging to anyone but the signed-in owner is refused |
| `windowSeconds()` (`lea.auth.reauth.window_seconds`, 120 s) · `challengeTtlSeconds()` (300 s) | How long a proof lasts, and how long the owner has to answer |

Rules a consumer can rely on: a proof for one action **never** satisfies another
(`action_mismatch`); a proof is spent by the first accepting `assert()` (`consumed`); an unanswered
challenge cannot be answered after `challengeTtlSeconds`; and nothing is ever written to the audit
trail but the action, the method and the outcome — not the code, not the nonce, not the assertion.

Known consumers: **S2-08** (approvals), **S2-19** (admin panes), **S2-14** (privacy downgrade).
Adding a fourth critical path means a new `CriticalAction` case, a `lang` entry and a case in
`ReauthRequiredTest`, which pins the enum's exact membership so the register and the code cannot
drift apart.

## 8. The credential never leaves the mail — and the WebAuthn payload never lands anywhere

`tests/Security/OtpCredentialNeverLoggedTest.php` runs the whole round — `lea:owner:create --json`,
request a code, one wrong attempt, the right one, logout, the health self-test — with the log
redirected to a scratch file, then sweeps the log, every `audit_events` row, `otp_codes`, `users`,
the self-test heartbeat, the command output and the rendered Livewire pages for the code (bounded by
non-alphanumerics so a ULID cannot false-positive). `Mail::assertSent(OtpMail::class, 1)` is the only
carrier. Structural guards behind the test: `OtpMail` is not queued; `MintedOtp`/`IssuedOtp` hide the
code from `var_dump`; `AuditLog` redacts context through the `Redactor`, whose key list already
covers `otp`, `otp_code`, `one_time_code`, `verification_code`; the request body keys `code` and
`token` are in `dontFlash`.

A development note: `MAIL_MAILER=log` writes the complete outgoing mail — code included — to the log
file by design of that transport. That is why `OTP_AUTOFILL` exists for `local`, and why the packaged
app must never ship with the log mailer (S1-06 bundles the configuration).

**The WebAuthn half** (S2-05, same family): `tests/Security/PasskeyPayloadNeverLoggedTest.php` runs a
complete round — register, sign in, one refused replay, re-authenticate for a critical action — with
the log redirected to a scratch file, then sweeps the log, every `audit_events` row, `auth_devices`
and `failed_jobs` for the registration challenge, the authentication challenge, the re-auth nonce,
the client data, the authenticator data and the signature. Structural guards behind it: the
library's validators keep their null logger and null event dispatcher, so the "checking the
response" `info` line with the full payload is never emitted; audit context carries our row id, the
`rp_id` and a reason code only; `PasskeyCredential` hides `credential_id`, `credential_id_hash`,
`public_key`, `user_handle` and `trust_path` from serialisation; and the `Redactor`'s key list gained the ceremony
keys (`challenge`, `signature`, `client_data_json`, `authenticator_data`, `attestation_object`,
`credential_id`, `public_key`, `raw_id`, `user_handle`) as the second layer. What *is* stored on
purpose is the credential's **public** key, id and counter — a relying party cannot verify later
assertions without them.

**How a credential is found (#117).** The unique constraint is on `credential_id_hash`, not on
`credential_id`: a credential id is up to 1364 base64url characters and no InnoDB key can hold one,
which made the `server` profile unmigratable until this moved. So both ceremonies look a credential
up with `PasskeyCredential::forCredentialId()`, which queries the hash;
`PasskeyCredential::hashFor()` is the single definition and a `saving` hook keeps the column in step
with `credential_id`, so an index key can never disagree with the credential it belongs to. The
registration duplicate check goes through the same path, and the unique index is what actually
decides if it races with itself. See [data-model.md](data-model.md) `passkey_credentials` for the
schema and the two migrations.

## 9. Browser-hardening headers (open question A16)

`App\Http\Middleware\SecurityHeaders` is appended to the `web` group, so S1-04's app shell and every
later page ship inside it. The set is a **floor**: a header the response already carries is kept,
so a route may answer with a stricter or deliberately different policy — the S1-03 mail preview
sends `default-src 'none'; … frame-ancestors 'self'` and `X-Frame-Options: SAMEORIGIN` because the
PWA embeds it in a same-origin frame. `PwaSecurityHeadersTest` pins the defaults and the floor rule:

| Header | Value | Why |
|---|---|---|
| `Content-Security-Policy` | `default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; frame-src 'none'; form-action 'self'; script-src 'self' 'nonce-…' 'unsafe-eval'; style-src 'self' 'nonce-…'; style-src-attr 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; manifest-src 'self'; worker-src 'self'; media-src 'self'` | T-22 second layer behind Blade escaping; `frame-ancestors 'none'` is the clickjacking control (T-21). One nonce per request via `Vite::useCspNonce()`, which Livewire reads too, so every emitted `<script>` carries it (the test asserts no script tag lacks one). `'unsafe-eval'` is Alpine's expression evaluator inside Livewire 3 — it does **not** re-enable inline scripts. `style-src-attr 'unsafe-inline'` keeps S1-03's mail preview, which is inline styles by design, rendering; `<style>` elements still need the nonce. |
| `X-Frame-Options` | `DENY` | legacy clickjacking control for browsers without `frame-ancestors` |
| `X-Content-Type-Options` | `nosniff` | |
| `Referrer-Policy` | `same-origin` | the origin never leaks in a referrer; same-origin navigation keeps working |
| `Permissions-Policy` | camera, microphone, geolocation, payment, usb, midi, sensors, display-capture, interest-cohort all `()` | the PWA needs none of them |
| `Cross-Origin-Opener-Policy` | `same-origin` | |
| `Cross-Origin-Resource-Policy` | `same-origin` | |
| `Cache-Control` (auth pages) | `no-store, private` | via `cache.headers:no_store` on `/login`, `/install/owner`, `/logout` |

Not set: `Cross-Origin-Embedder-Policy` (breaks nothing we need and would block future same-site
embeds), `upgrade-insecure-requests` (would break the loopback HTTP origin). `Strict-Transport-Security`
belongs to the Tailscale Serve origin and is decided in E4-1.

## 10. Open points carried forward

- **Mail transport at install time.** The first sign-in after `CREATE_OWNER` needs an outgoing mail
  transport, and a fresh Mac has none (§5.5 forbids sending before an account is confirmed). The
  in-app `/install/owner` screen sidesteps this by signing the installer in directly; the Supervisor
  path (`lea:owner:create`) does not. S1-05 and S1-06 must decide whether the installer opens
  `/install/owner` in the PWA instead of calling the command, or whether the Supervisor hands the
  browser a first session some other way. Recorded for the Sprint 1 review.
- Re-authentication for critical actions, device management and passkeys **landed in S2-05 (#69)**:
  §5.1, §7. The origin question S2-05 left open was decided in **#109**: `localhost` is the loopback
  browser origin, recorded in ADR-006 D3 and described in §7.1 — that is no longer an open point.
  What is still open there: a passkey is registered per origin, so the owner adds one on the
  loopback origin and another over the tailnet name; whether the installer should offer the first
  passkey during `CREATE_OWNER` is an S2/S4 question, not decided here.
- `TrustProxies`/`TrustHosts` for the tailnet origin, HSTS: **E4-1**. Until then the tailnet
  relying-party id is exercised in tests through `remote.trusted_hosts`, the same config E4-1 writes.
- The three §7.4 consumers do not exist yet: **S2-08** approvals, **S2-19** admin, **S2-14** privacy
  downgrade. The primitive and its tests are deliberately ahead of them.
