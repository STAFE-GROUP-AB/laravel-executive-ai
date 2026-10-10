# Install-timer results

Raw, committed JSON records from measured installs — one file per run, written by
`install-timer.mjs record`. This is the evidence directory for the §12.2 gate and the S1-14 Sprint 1
measurement. Numbers are committed as found; a record is never edited after it is written.

## Layout

| Path | Contents |
|---|---|
| `example/` | **Synthetic** illustration of the record shape (`"synthetic": true`). Excluded from `aggregate` by default and never counted by `gate`. Validated in CI by `packaging/fixtures/validate-schema.mjs` against `../record.schema.json`. |
| `<sprint-or-date>/` | Real runs, grouped per measurement campaign, e.g. `2026-sprint1/`. Created by S1-14. |

File names are `<yyyy-mm-dd>-<profile>-<cached|uncached>-<run_id>.json`, e.g.
`2026-11-03-mac-a-cached-01K7ABCDEFGHJKMNPQRSTVWXYZ.json`.

## Rules

- Only records produced by `install-timer.mjs record` are committed. Hand-written or edited records
  are rejected in review; the `run_id` and `timestamp` must come from the tool.
- `clean_machine: true` is an assertion by the operator that the protocol in
  `docs/operations/install-measurement.md` §2 was followed. Do not set it otherwise.
- A failed or aborted install is **not** a record (the tool cannot build one without `READY`). Open
  an issue with the installer log instead; the campaign summary counts attempts vs. records.
- `summary.json` and `gate.json` are generated outputs. Commit the campaign's `summary.json` next to
  its records so the aggregate is reviewable; never commit them to `example/`.

## Reading a record

```text
core_ready_ms            WELCOME → READY, human prompts included      ← the §12.2 number
  human_interaction_ms   time a prompt was open (own phase, §12.2)
  automated_ms           core_ready_ms − human_interaction_ms
  phases.*               per-state wall clock + human portion
dmg_open_to_welcome_ms   DMG open + Gatekeeper, informational, null if not timed
async_post_core.steps    model download, OAuth, permissions, Tailscale … — NEVER in core_ready_ms
```

See `../README.md` for the commands and `docs/operations/install-measurement.md` for the protocol.
