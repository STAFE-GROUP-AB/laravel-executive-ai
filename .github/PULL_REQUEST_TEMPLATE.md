<!-- Blueprint §13.2: every PR references an issue, an ADR where architectural,
     includes tests, states security impact, and has a screenshot/recording for UI. -->

## Issue

Closes #

## What and why

<!-- 2–6 sentences. What changed, why, and what it deliberately does not do. -->

## ADR

<!-- Link the ADR in docs/adrs/ if this is architectural, otherwise "n/a". -->

## Tests

<!-- What was added or changed. Authorization tests for every new policy surface;
     negative tests for cross-account access; fixtures over live services. -->

- [ ] Unit / feature tests added or updated
- [ ] Authorization / negative tests where a policy surface changed
- [ ] Ran the full suite locally

## Security impact

<!-- Required, even if "none". Think: new tool, new outbound call, new scope,
     new secret, new input parsed from an external source, new IPC surface. -->

## UI

<!-- Screenshot or recording for any visible change (Mac app, PWA, email template). -->

## Checklist

- [ ] Conventional commit messages
- [ ] No secrets, tokens, `.env` or model files committed
- [ ] Does not reintroduce anything out of scope (Basecamp, GoZero Core, Filament, OpenClaw, mandatory Playwright, Linux/Windows)
- [ ] Security-critical module touched → two reviewers requested
