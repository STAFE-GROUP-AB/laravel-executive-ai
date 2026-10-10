# ADR-NNN: <decision in one line>

| | |
|---|---|
| **Status** | Proposed · Accepted · Superseded by ADR-NNN · Rejected |
| **Date** | YYYY-MM-DD |
| **Deciders** | Workstream letter + role, reviewer from another workstream, Owner where the blueprint requires it |
| **Closes** | Blueprint §15 item(s) and/or OPEN-QUESTIONS ambiguity id(s) |

## Context

What forces this decision now. Quote the blueprint section(s) that constrain it. Name what breaks downstream if it stays open.

## Decision

The decision, stated so an implementing agent cannot misread it. Exact versions, paths, names, licenses where relevant.

## Evidence

**An ADR without this section filled cannot move to Accepted** (blueprint §15: "Unresolved is not permission to guess. Each needs an ADR, measured prototype or vendor-doc citation").

Pick every kind that applies:

- **Measured prototype** — what was built, on what machine profile, the raw numbers, where the artefact lives in the repo.
- **Vendor documentation** — URL, the exact version/date of the page, and the sentence relied upon.
- **Legal / licensing input** — who reviewed, what was reviewed, the conclusion. Engineers do not accept legal risk.

## Alternatives considered

At least two, each with the concrete trade-off that ruled it out — not "rejected for complexity".

## Consequences

What becomes easier, what becomes harder, what is now **forbidden** in code review because of this decision.

## Residual risks

Risks that remain after the decision, in plain language, each with the role that accepts it (§3.1 "dokumentera kvarstående risker"; §13 workstream H requires signed risk acceptance).

## Blueprint references

Section numbers only, e.g. §2, §12.1, §15.2.

## Reopen conditions

The specific, observable condition under which this ADR must be revisited (e.g. "WAL write throughput below N msgs/s in the S0-07 benchmark", "vendor removes API X").
