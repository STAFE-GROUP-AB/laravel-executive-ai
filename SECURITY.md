# Security Policy

Laravel Executive AI handles email, customer data and credentials on the user's own Mac. Security issues are taken seriously and handled privately.

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Report privately via [GitHub private vulnerability reporting](../../security/advisories/new) for this repository. Include:

- affected component (Mac app, Laravel core, PWA, an integration adapter, MCP broker, installer)
- version or commit
- reproduction steps or a proof of concept
- impact as you understand it

You will get an acknowledgement within 72 hours and a status update at least every 7 days until resolution. We will credit you in the release notes unless you prefer otherwise.

## Scope

In scope: anything in this repository, the signed Mac artifacts we publish, and the default configuration. Particularly welcome: cross-account data access, prompt-injection paths that escalate tool permissions or trigger outbound actions, Local Only mode leaking data off-device, secret exposure in logs/prompts/screenshots, SSRF in `Browser/Fetch`, MCP tool poisoning, and installer/updater integrity.

Out of scope: vulnerabilities in third-party services you connect (Google, Microsoft, HEY, GitHub, Tailscale, model providers) — report those to the vendor. Social engineering of maintainers.

## Supported versions

Pre-1.0: only the latest tagged release and `main` receive fixes.

## Security design commitments

These are gates in the blueprint, enforced by tests, not promises:

- default-deny for outbound mutating tools; every send/delete/write is approval-driven with an immutable proposal, approver identity, expiry and idempotency
- Laravel policies on every HTTP action, Livewire action, queued job, MCP tool, AI tool and integration call; automated multi-tenant and cross-account negative tests
- credentials in the macOS Keychain, never in database tables, localStorage, logs, prompts, push payloads or this repo
- Local Only mode verified by packet capture: no LLM traffic leaves the device
- signed and notarized Mac artifacts, pinned dependencies, SBOM, dependency scanning
- third-party penetration test before v1.0
