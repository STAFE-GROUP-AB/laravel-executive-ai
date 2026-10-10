Frozen fixture pack: briefs, CRM, security corpora, e2e drivers; changes only via deliberate snapshot updates (§13.1, §14).

## Layout

| Path | Contents | Contract |
|---|---|---|
| `brief.schema.json` | Executive Brief schema 1.0.0 (S0-13) | `docs/architecture/brief-schema.md` |
| `briefs/` | Brief fixtures. `brief-canonical.json` is the one canonical example (Swedish brief with Swedish, English, Italian and German source excerpts). S0-18 adds the awkward cases and the freeze policy. | validated against `brief.schema.json` |
| `health.schema.json` | Health contract 1.0.0 (S0-14): `/health/live` and `/health/ready` bodies | `docs/architecture/health-contract.md` |
| `health/` | Example health responses: live ok, core-ready without AI, AI ready, stale brief offline, failing scheduler | validated against `health.schema.json` |
| `validate-schema.mjs` | Validator run in CI | — |

## Validate

```bash
npm ci --prefix packaging/fixtures          # pinned ajv + ajv-formats; lockfile is committed
node packaging/fixtures/validate-schema.mjs
```

Validates both schema files as JSON Schema draft 2020-12 (ajv strict mode), every `briefs/*.json`
(schema, `content_hash`, referential integrity, single `sent` delivery per channel and recipient)
and every `health/*.json`. Exit code 1 on any failure.

The fixture **freeze policy** (byte-stable fixtures, changes only via a PR that updates the affected
snapshots deliberately) is defined by S0-18.
