Frozen fixture pack: briefs, CRM, security corpora, e2e drivers; changes only via deliberate snapshot updates (§13.1, §14).

## Layout

| Path | Contents | Contract |
|---|---|---|
| `brief.schema.json` | Executive Brief schema 1.0.0 (S0-13) | `docs/architecture/brief-schema.md` |
| `briefs/` | Brief fixtures. `brief-canonical.json` is the one canonical example (Swedish brief with Swedish, English, Italian and German source excerpts). S0-18 adds the awkward cases and the freeze policy. | validated against `brief.schema.json` |
| `health.schema.json` | Health contract 1.0.0 (S0-14): `/health/live` and `/health/ready` bodies | `docs/architecture/health-contract.md` |
| `health/` | Example health responses: live ok, core-ready without AI, AI ready, stale brief offline, failing scheduler | validated against `health.schema.json` |
| `ipc.schema.json` | Swift ↔ Laravel IPC contract 1.0.0 (S0-15): one recorded request/response exchange per document; `$ref`s the brief and health schemas | `docs/architecture/ipc-contract.md` |
| `ipc/` | One exchange per v1 operation plus the error cases (version mismatch, bad signature, rate limit, wrong scope). All signed with the documented all-zero **test key**; Swift and PHP conformance tests replay them | validated against `ipc.schema.json`; every token MAC, request and response signature is recomputed |
| `ipc-signing.mjs` | Reference implementation of the IPC token derivation and signing (§3.3, §4.2, §4.5, §7.3), imported by the validator | — |
| `sign-ipc-fixtures.mjs` | Re-signs `ipc/*.json` after an edit (`node packaging/fixtures/sign-ipc-fixtures.mjs`); not a CI step | — |
| `validate-schema.mjs` | Validator run in CI | — |

## Validate

```bash
npm ci --prefix packaging/fixtures          # pinned ajv + ajv-formats; lockfile is committed
node packaging/fixtures/validate-schema.mjs
```

Validates the schema files as JSON Schema draft 2020-12 (ajv strict mode), every `briefs/*.json`
(schema, `content_hash`, referential integrity, single `sent` delivery per channel and recipient),
every `health/*.json`, every `ipc/*.json` (schema, token MACs, request and response signatures
under the test key, elevation binding, ETag rule, and the brief checks on the embedded brief), and
the S0-20 install-timer record schema (`packaging/manifest/install-timer/record.schema.json`)
against its synthetic example. Exit code 1 on any failure.

The fixture **freeze policy** (byte-stable fixtures, changes only via a PR that updates the affected
snapshots deliberately) is defined by S0-18.
