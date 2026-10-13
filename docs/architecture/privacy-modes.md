# The three privacy modes, and what each one permits

> `LOCAL_ONLY`, `EU_RESTRICTED` and `CUSTOM` as implemented: where the setting lives, exactly what
> each mode allows out of the Mac, what `LOCAL_ONLY_ALLOW_LAN` changes, and what it takes to move
> between them.
> **Created:** 2026-10-13 (S2-14, #96) · **Decisions:** ADR-004 **D4**, **D5**, **D9** ·
> ADR-005 **D4.11** · **Closes:** OPEN-QUESTIONS **A18** · **Threat:** T-68

---

## 1. Where the setting lives

In `privacy_policies` (S2-03), one append-only row per change, at most one live row per workspace.
Not in `config/lea.php` and not in an environment variable: ADR-001 D4 removed the `.env`, and a
stored row is the only place a change can carry the user who made it, the proof of presence they
gave, and the `audit_events` row that recorded it.

`config('lea.privacy_mode')` keeps two jobs and no others — the value the install-time default row is
written from, and the fallback for a boot before migrations. **The stored row always wins.** Anything
unparseable resolves to `LOCAL_ONLY`, the strictest of the three, because a privacy setting nobody
can read must not widen what leaves the Mac.

`App\Security\Privacy\PrivacyModeStore` is the reader and the one writer. Every surface that displays
the mode — `/health/ready`, the menu bar that polls it, the PWA shell the brief and chat pages render
inside — reads it from there. That is the half of **T-68** that is about honesty rather than egress:
enforcing the stored mode while displaying the configured one would tell the owner the opposite of
the truth at exactly the moment it mattered.

## 2. What each mode permits

The modes answer different questions. `LOCAL_ONLY` is an **address** question and goes to the egress
guard; `EU_RESTRICTED` is an **evidence** question and goes to `RegionEvidence::missingFor()`.
`App\Security\Privacy\ProviderEligibility` composes them, and they compose as a **conjunction** when
both apply (ADR-004 D5).

### `LOCAL_ONLY` — strict, and strict means no silent exceptions

| | |
|---|---|
| **Model traffic** | loopback only: `127.0.0.0/8`, `::1`, and `localhost` (which resolves from a constant, never from DNS — RFC 6761 §6.3) |
| **Which providers can be built at all** | only the `ollama` and `openai-compatible` drivers, and only on a loopback endpoint. A cloud provider is never instantiated — shown greyed out as "disabled by Local Only", with the rule that refused it |
| **Prompts, embeddings, screenshots, tool transcripts** | the same path and the same rule. There is no content type that takes a different route (ADR-004 D4.5) |
| **`Browser/Fetch` and MCP registry discovery** | **off**, not narrowed. There is nothing to allowlist (ADR-004 D4.1, ADR-005 D4.9) |
| **MCP** | our own loopback server only; T2 remote servers are disabled entirely |
| **Mail and code-host sync** | **unaffected.** §4 makes integration sync a separate, transparently labelled egress category: a Local Only install still fetches the owner's own mail |
| **Failover** | none. `EgressBlockedException` is deliberately not a `FailoverableException`, so a blocked cloud call is refused rather than retried somewhere else (ADR-004 D4.4, T-66) |

### `EU_RESTRICTED` — evidence, never a domain name

A provider is eligible only when its `ai_providers` row carries all eight ADR-004 D5 evidence
fields, unexpired: `processing_region`, `region_mechanism`, `region_evidence_url` +
`region_verified_at`, `dpa_reference` + `dpa_signed_at`, `subprocessors_url`,
`transfer_impact_ref`, `zero_data_retention`, and `verified_by` + `expires_at`. A refusal names the
**missing field names**, because "not eligible" is not a sentence an owner can act on and "these
four fields are missing" is.

Local runtimes satisfy it for inference by construction (`region_mechanism = local_machine`); the
model's own licence is a separate question (ADR-004 D8, ADR-007).

**Domain-name inference is forbidden.** No code derives a region from `.eu`, `eu.` or any hostname
pattern, and `tests/Security/NoSdkTypeLeakArchTest.php` fails on a regex over provider hosts
anywhere in `app/AI`. A `.eu` check would look like diligence and be the opposite of it.

The SSRF rules still apply in this mode: the private ranges, the metadata endpoints, the forbidden
schemes and the port rules are not a Local Only feature. `EU_RESTRICTED` narrows *which provider*,
not *which address families*.

### `CUSTOM` — the owner accepted the provider's own terms

No additional routing restriction on which provider may be used. The SSRF guard is unchanged:
loopback, RFC 1918, shared address space, link-local, unique-local, the metadata endpoints, the
non-TCP transports and the port rules are all still refused, for every purpose. `CUSTOM` means "no
*policy* restriction on the provider", never "no guard".

## 3. What `LOCAL_ONLY_ALLOW_LAN` changes

One toggle, one effect: in `LOCAL_ONLY`, it extends the permitted model-traffic addresses from
loopback to **RFC 1918 and link-local** — `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`,
`169.254.0.0/16` and `fe80::/10` — so a home-lab GPU box on the owner's own network is usable as a
local runtime (RR-18).

It changes nothing else, and the list of what it does **not** widen is the point:

- not a public IP, in this mode, ever;
- not `100.64.0.0/10` — that is shared address space and the tailnet range, and the toggle's warning
  says RFC 1918 and link-local;
- not `fc00::/7`, for the same reason;
- not the metadata addresses, which sit inside `169.254.0.0/16` and stay refused by address;
- not our own core's fixed loopback port;
- not `Browser/Fetch`, which is off in `LOCAL_ONLY` whether the toggle is on or not;
- not the other two modes. It is storable only in `LOCAL_ONLY`
  (`PrivacyMode::supportsLanToggle()`), because it has nothing to widen elsewhere — which is what
  lets the guard treat the opt-in as one branch instead of a special case per mode.

It is **off by default** and carries the warning "model traffic will leave this Mac over your local
network". Turning it on is a widening change and goes through §4's gate.

## 4. Changing the mode

| | |
|---|---|
| **Who** | the workspace owner, through a signed-in PWA session. `OwnerAbilities::isOwner()` is the same check the IPC abilities use, so the menu bar cannot change the mode and no artisan command does either. S2-19's `/admin` security-rules pane is the one surface that calls the store |
| **Widening** | a mode of greater breadth (`LOCAL_ONLY` → `EU_RESTRICTED` → `CUSTOM`), or `LOCAL_ONLY_ALLOW_LAN` turning on |
| **A widening change needs** | a fresh `CriticalAction::ChangePrivacyMode` re-authentication proof (S2-05: a passkey or an emailed code, bound to the action, the session and a single-use nonce) **and** an explicit UI re-confirmation. Submitting the form is not confirming it |
| **Tightening needs** | neither. A control that makes it laborious to become stricter is a control that gets left loose |
| **Every change writes** | one `audit_events` row, action `privacy.mode_changed`, with the old and new mode, the old and new LAN flag, whether it widened, the re-authentication method and the owner's reason. The event and the policy row are written in one transaction and the row stores the event id, so a mode in force without an audit trail is not representable |
| **A refused change writes** | nothing: no event, no row, and the mode in force is unchanged |

The install-time row is `source = install_default`: `LOCAL_ONLY`, no LAN, no user and no proof — the
absence of attribution recorded as a fact rather than left as a gap.

## 5. Where this is enforced and proven

| Layer | Code | Test |
|---|---|---|
| The registry gate: a non-local provider is never built | `ProviderRegistry`, `ProviderEligibility` | `tests/Security/LocalOnlyEgressTest.php` (layer 1) |
| The address rule, resolved once and pinned | `App\Security\Egress\EgressGuard` | `tests/Security/SsrfGuardTest.php`, `LocalOnlyEgressTest.php` (layer 2) |
| The HTTP-layer veto, per redirect hop | `EgressServiceProvider` | `LocalOnlyEgressTest.php` (layer 3) |
| No failover | `EgressBlockedException` | `LocalOnlyEgressTest.php` (layer 4) |
| One path for every content type | the adapter and the middleware | `LocalOnlyEgressTest.php` (layer 5) |
| The storage, the gate and the audit event | `PrivacyModeStore` | `tests/Security/PrivacyModeDowngradeTest.php` |
| No new outbound call bypasses the guard | — | `tests/Security/NoSocketTransportArchTest.php` |

The guard's own rules are documented in [`docs/security/egress-guard.md`](../security/egress-guard.md),
including the `tcpdump` lab protocol that is the other half of ADR-004 D4's evidence and is never
claimed as a CI result.
