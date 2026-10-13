# The shared egress and SSRF guard

> One guard decides every outbound connection this product makes. This document is what it does,
> why each rule exists, how to call it, and what CI proves versus what only a packet capture can.
> **Created:** 2026-10-13 (S2-14, #96) · **Decisions:** ADR-005 **D4.11** (the checklist),
> ADR-004 **D4** (the Local Only layers) · **Closes:** OPEN-QUESTIONS **A15** ·
> **Threats:** T-23 – T-29, with T-27's, T-28's and T-29's **GAP**s closed

---

## 1. Why this is one object and not a habit

ADR-005 D4.2(b) already said the right thing for MCP connections — resolve once, reject the private
ranges, pin the resolved IP — and said the guard was *shared with `Browser/Fetch`*. ADR-004 D4.2 said
the same thing again for model endpoints. Neither said how, and S2-13 shipped the Local Only rule as
a literal allowlist of three loopback spellings with a comment admitting it was a placeholder.

A placeholder is the honest word for it, because the cheap version is wrong in both directions:

- `127.1`, `0177.0.0.1`, `0x7f.1`, `2130706433` and `::ffff:127.0.0.1` are all `127.0.0.1` to a
  resolver and to curl, and none of them matches a string check for `127.`;
- `localhost.attacker.test` and `127.0.0.1.nip.io` match the string check's *intent* but are names,
  and must be judged on what they resolve to;
- and a URL checked by name and then handed to an HTTP client is resolved **twice**, so the second
  answer is the one that gets connected to.

So the rules live in `app/Security/Egress/`, once, and the architecture test
`tests/Security/NoSocketTransportArchTest.php` fails when a new outbound call site appears that does
not go through them.

## 2. The parts

| Class | What it is |
|---|---|
| `EgressGuard` | the decision: `authorize()` (permit and pin, or record and throw), `permits()` / `refusalFor()` (ask without recording), `authorizeRedirect()` (one hop) |
| `AddressNormalizer` | every spelling of an address and a host reduced to one canonical form; pure text and bytes, no network |
| `HostResolver` | the resolution seam. `SystemHostResolver` in production (memoised, one answer per host per process); `LoopbackHostResolver` as the fail-closed default and under `php artisan test` |
| `EgressPurpose` | what the request is *for*, which is what separates a local model runtime on `127.0.0.1:11434` from T-23 |
| `EgressRule` | the rule that refused, one per §3.4.1 row plus `Unclassifiable` |
| `PinnedTarget` | the permitted target and the address the socket must use; `requestOptions()` carries the pin |
| `EgressDenialRecorder` | checklist row 16. `AuditEgressDenialRecorder` writes `audit_events`; `NullEgressDenialRecorder` is the table tests' |
| `EgressServiceProvider` | the bindings, and ADR-004 D4 layer 3: the global `Http` middleware veto |

## 3. The four properties, and what each one would cost if it were absent

**Normalise, then decide.** `AddressNormalizer` reduces decimal, octal, hexadecimal, zero-padded and
short-form IPv4, IPv4-mapped IPv6, bracketed IPv6, trailing dots, case, and Unicode or punycode
hosts to one form, and `EgressGuard` decides on the **binary** address only. Without it the deny
list is matched against a string the resolver reads differently — T-28, and the reason three
checklist rows were marked GAP.

**Resolve once and pin.** The guard resolves a name once per decision, evaluates **every** A and
AAAA record, refuses a host whose records disagree, and returns a `PinnedTarget` whose
`requestOptions()` set `CURLOPT_RESOLVE` to `host:port:address`. curl then connects to the judged
address while the `Host` header and the TLS SNI keep the name the certificate is issued for. Without
the pin the check is advisory: the name is resolved a second time to dial, and T-25's rebinding
window is exactly the gap between the two.

**Every private range, not only loopback.** `10/8`, `172.16/12`, `192.168/16`, `100.64/10`,
`169.254/16`, `127/8`, `0/8`, `::1`, `fc00::/7`, `fe80::/10`, the five named metadata endpoints, and
the reserved, documentation, benchmarking, multicast and broadcast space that is none of the above.
Without the last group the guard would classify it as "public", which is the fail-open shape.

**Fail closed.** An address no rule recognises is refused as `Unclassifiable`. An all-numeric host
that does not parse as an address is refused as `ObfuscatedAddress`, not reported as "does not
resolve" — it is not a name either. A name the guard cannot pin is refused as `Unpinnable`.

## 4. The purposes

The rules are not one list. A local runtime on loopback is the point of Local Only; the same address
reached from a URL that arrived in an email is T-23. The caller says which it is; the guard never
guesses from the URL.

A local runtime is local *by definition*, so `LocalRuntime` refuses a public address in every mode,
not only in Local Only. Without that it would be the loosest row in the table as soon as the mode
was not `LOCAL_ONLY` — loopback permitted and no public port rule — which is the opposite of what
its name promises.

| Purpose | Schemes | Loopback | Public ports | Under `LOCAL_ONLY` |
|---|---|---|---|---|
| `ModelProvider` — prompts, embeddings, screenshots, tool transcripts | `https`, `http` (loopback only) | yes, not our core port | 443, 8443 | loopback only (+ LAN with the opt-in) |
| `LocalRuntime` — §12.3.5 detection and local inference | `https`, `http` | yes, not our core port | **no public target at all**, in any mode | loopback only (+ LAN with the opt-in) |
| `WebFetch` — §11 `Browser/Fetch`, a URL from content | `https` | **no** | 443 | **off entirely** |
| `McpServer` — ADR-005 D4.2 | `https`, `http` (loopback only) | yes, including our core port | 443 | our own loopback server only |
| `IntegrationSync` — mail and code-host sync | `https` | no | 443 | **not restricted** (§4's separate labelled category) |

An unlabelled request is `ModelProvider`: the category `LOCAL_ONLY` restricts hardest, and the pinned
SDK builds its own requests without knowing the guard exists.

## 5. How to call it

Declare the purpose on the request and the layer-3 veto does the rest:

```php
$this->http
    ->withOptions([EgressServiceProvider::PURPOSE_OPTION => EgressPurpose::LocalRuntime])
    ->get($url);
```

Or decide explicitly when you need the pinned address yourself:

```php
$policy = $privacyModes->active();
$target = $guard->authorize($url, EgressPurpose::WebFetch, $policy->mode, $policy->allowLan);

$response = $this->http->withOptions($target->requestOptions())->get($url);
```

Then add the file to `EGRESS_CALL_SITES` in `tests/Security/NoSocketTransportArchTest.php` with a
sentence saying how it is guarded. The test fails until you do, which is the mechanism, not a
formality: the next outbound surface in this product is `Browser/Fetch` (S2-16), and it must not be
able to ship unguarded by accident.

## 6. Where layer 3 sits, and why that is load-bearing

Laravel pushes global HTTP middleware onto the handler stack **after** `HandlerStack::create()` has
pushed `allow_redirects`, and Guzzle resolves the stack in reverse. Two consequences, both relied on:

- the redirect middleware is **outside** ours, so every hop of a followed redirect re-enters the
  veto and is re-checked and re-pinned from scratch (row 13, T-24);
- the stub handler `Http::fake()` installs is **inside** ours, so the veto runs before a fake
  answers. That is what lets `tests/Security/LocalOnlyEgressTest.php` assert zero recorded requests
  without a socket — and it means a test cannot accidentally prove the guard works by faking past it.

ADR-004 D4's own residual risk stands: layer 3 depends on the SDK using Laravel's `Http` facade.
Contract matrix row 10 is the alarm for that, and `NoSocketTransportArchTest` asserts the middleware
is registered at all.

## 7. What CI proves, and the lab step it does not

`tests/Security/SsrfGuardTest.php` is a table over every §3.4.1 row, and
`tests/Security/LocalOnlyEgressTest.php` holds one test per ADR-004 D4 layer. **No test opens a
socket**: `Http::preventStrayRequests()` is armed, names resolve through a fake, and under
`php artisan test` the container binds `LoopbackHostResolver`, so even a DNS lookup cannot happen by
accident. A suite that resolved a real name would be asserting against whatever the developer's
resolver answered — an ISP that hijacks NXDOMAIN included.

What CI proves is that **the code refuses**. It cannot prove the machine stayed quiet. That is a
release-blocking **lab** step, owned by workstream I, and it is never claimed as a CI result
(ADR-004 D4 Evidence, §14; ADR-007 D5 permits the locality claim only while `LocalOnlyEgressTest` is
green):

```sh
# 1. Local Only, a real local runtime, and a cloud provider deliberately configured.
ollama serve &

# 2. Capture everything that is not loopback, filtered to the PHP worker PIDs.
#    `lsof -i -p <pid>` identifies the workers; `-w` keeps full packets for the report.
sudo tcpdump -i any -n -w local-only-$(date +%Y%m%d).pcap \
  'not (host 127.0.0.1 or host ::1)'

# 3. Run the deterministic eval set under LOCAL_ONLY, then stop the capture.
# 4. The capture must be empty. Any packet is a release blocker, not a finding to triage.
tcpdump -r local-only-*.pcap | head
```

Attach the pcap to the release checklist with the eval-set revision and the runtime versions.
Repeat it with `LOCAL_ONLY_ALLOW_LAN` on, where the expected capture is traffic to the configured
RFC 1918 address and nothing else.

## 8. What this guard is not

It does not decide whether a provider carries the ADR-004 D5 evidence that `EU_RESTRICTED` needs.
`RegionEvidence::missingFor()` is the single implementation of that rule, and
`App\Security\Privacy\ProviderEligibility` composes the two so the router, the registry gate and the
settings screen ask one object. **A hostname never implies a region**, here or anywhere (D5;
`tests/Security/NoSdkTypeLeakArchTest.php` fails on a regex over provider hosts in `app/AI`).

It also decides targets, not bodies. Response size caps and content-type handling (checklist row 14)
belong to the surface that reads a body, which is S2-16's `Browser/Fetch` under ADR-005 D4.8's
64 KiB truncation.

## 9. Known residuals

| | |
|---|---|
| **The pin needs curl.** | Without the curl extension the Laravel client falls back to a stream handler that re-resolves, so the guard refuses every *name* with `Unpinnable`. A literal has nothing to re-resolve and is permitted. |
| **Layer 3 depends on the SDK using `Http`.** | ADR-004 D4's own residual risk. Contract matrix row 10 is the alarm; a vendor change that bypassed the facade would need layers 1, 2, 4 and 5 to hold alone, which is why each has its own test. |
| **A followed redirect is re-checked but pinned per hop, not chained.** | Each hop gets its own decision and its own pin. Redirects are off by default for model traffic and MCP (ADR-005 D4.2(c)); a surface that enables them accepts one fresh resolution per hop. |
| **`100.64.0.0/10` is refused even with the LAN opt-in.** | It is the tailnet range as well as CGNAT, and ADR-004 D4.2's warning says RFC 1918 and link-local. A toggle must widen exactly what its warning says it widens. A tailnet-reachable GPU box is therefore not usable as a Local Only runtime; reopen with ADR-006 if that becomes a real request. |
