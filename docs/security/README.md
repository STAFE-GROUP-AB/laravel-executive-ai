# Security documentation

Threat model, security test documentation and risk acceptances for the §11 security gates.
Vulnerability reporting is in [`SECURITY.md`](../../SECURITY.md); the decisions these documents rest
on are in [`docs/adrs/`](../adrs/README.md).

| Document | Issue | What it covers |
|---|---|---|
| [threat-model.md](threat-model.md) — baseline threat model 1.0.0: ten trust boundaries with data-flow tables, 75 threats mapped to every §11 gate with its mitigation and the test that proves it, the SSRF control checklist, what FileVault does and does not protect while logged in, 25 accepted residual risks with accepting roles | S0-23 | §11 gates, §3.1 SQLite residual risk, §6 Mac helper privilege boundary, §13 workstream H "signed risk acceptance" |
| [egress-guard.md](egress-guard.md) — the one shared SSRF and egress guard: why it is an object and not a habit, its parts, the four properties (normalise then decide; resolve once and pin at the socket; every private range; fail closed) and what each would cost if absent, the five `EgressPurpose` rule sets as a table, how to call it and how the arch test makes that mandatory, where ADR-004 D4 layer 3 sits in Guzzle's handler stack and why that is load-bearing, what CI proves versus the `tcpdump` lab protocol it does not, and four named residuals | S2-14 | ADR-005 **D4.11** (the §3.4.1 checklist), ADR-004 **D4**, OPEN-QUESTIONS **A15**, threats T-23 – T-29 |

Planned by the backlog, not yet written:

| Document | Issue | What it will cover |
|---|---|---|
| `tests/Security/README.md` | S0-19 | how the adversarial fixture pack (prompt injection, hostile MCP) is used and extended |
| Tailnet ACL posture runbook | E4-1 | the ADR-006 D4 posture and its verification steps as an operator runbook |
| Penetration test scope and findings | E4-5 (A13) | third-party test scope, ASVS coverage, findings and their disposition |

Every security-critical change needs two reviewers (§13.2) and a security-impact statement in the PR
(`.github/PULL_REQUEST_TEMPLATE.md`). A new boundary, outbound call, secret, tool or IPC surface needs
a row in the threat model's register in the same PR.
