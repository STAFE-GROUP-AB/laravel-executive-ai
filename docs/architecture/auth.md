# Authentication — owner account, one-time-code login, sessions (S1-02)

**Status:** Sprint 1 · **Workstream:** B (Laravel platform) with H (security) · **Issue:** #30
**Binding references:** blueprint §11 (secure sessions, revocation, re-auth), §12.1 (`CREATE_OWNER`,
no Terminal), §12.3 screen 4; ADR-006 D3 (two origins, cookie posture); threat model T-70, T-71,
T-72 and open question A16; health contract §2 `auth_login`.
**Code:** `app/Security/Authorization/`, `app/Security/Audit/AuditLog.php`,
`app/Livewire/Auth/Login.php`, `app/Livewire/Install/CreateOwnerForm.php`,
`app/Http/Middleware/{SecurityHeaders,ResolveRequestOrigin,EnsureNoOwnerExists}.php`,
`routes/auth.php`, `resources/views/{layouts/auth,livewire/auth,livewire/install,mail/auth}`.
**Tests:** `tests/Feature/Auth/`, `tests/Feature/Health/LoginSelfTestTest.php`,
`tests/Security/OtpCredentialNeverLoggedTest.php`.

## 1. Principles

- **There is no password.** Every sign-in is a six-digit one-time code mailed to the account's
  address. `users.password` stays nullable and is never read or written; no password route exists
  (`AuthRoutesTest`). Passkeys are the Sprint 2 primary factor (§7).
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

Two front doors, same action:

| Door | Who uses it | Behaviour |
|---|---|---|
| `php artisan lea:owner:create --name= --email= --organization= [--timezone=UTC] [--language=en] [--json]` | The Mac installer (S1-05) through the Supervisor — the user never sees a Terminal | Exit `0` created · `2` invalid input · **`3` an owner already exists**. `--json` prints one line: `{"status":"created","user_id","organization_id","membership_id","email","install_state"}` or `{"status":"owner_exists"\|"invalid","message","errors"}`. Nothing printed is ever a credential. |
| `GET /install/owner` (Livewire `CreateOwnerForm`) | A browser on the loopback origin before an owner exists | §12.3 screen 4: name, email, organization, time zone, brief language, one CTA. The person at this page *is* the installer, so success signs them in directly and lands on `/home`. **404 once an owner exists** (`EnsureNoOwnerExists`). |

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
- **Revocation**: `App\Security\Authorization\SessionRevoker::revokeAll(User, actor, ?exceptSessionId)`
  deletes the user's rows from `sessions` and rotates `remember_token`, so every browser is signed out
  on its next request (`SessionRevocationTest` drives real requests with the cookie across a simulated
  new PHP process). `php artisan lea:sessions:revoke {ulid|email} [--json]` is the operator and
  Supervisor entry point. E2-3 builds device management on this primitive rather than replacing it.
- **Re-authentication for critical actions** (T-71) is E2-3; the §11 approval paths that need it do
  not exist yet in Sprint 1.

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

## 7. Passkeys — stub, not silence

`App\Security\Authorization\Passkeys\PasskeyAuthenticator` is the interface (registration and
authentication ceremonies with WebAuthn JSON shapes). Sprint 1 binds `NotYetAvailable`, which reports
`PasskeyStatus{available: false, message: "Passkeys arrive in Sprint 2 (E2-3)", trackingUrl}` and
throws `PasskeysUnavailableException` from every ceremony. The login page shows the status row with a
link to **issue #69 (E2-3: passkey/WebAuthn login)**. `PasskeyStubTest` pins the binding; E2-3
deletes the stub, the test and the row in the same PR.

## 8. The credential never leaves the mail

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
- Re-authentication for critical actions, device management, passkeys: **E2-3 (#69)**.
- `TrustProxies`/`TrustHosts` for the tailnet origin, HSTS: **E4-1**.
