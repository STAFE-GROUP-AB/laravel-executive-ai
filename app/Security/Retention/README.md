Data retention rules, purge jobs and GDPR erasure (§10).

`RetentionRules` is the only way a `retention_policy` value becomes a rule: it reads
`data_retention_rules` for the workspace, falls back to the shipped defaults, and throws
`UnknownRetentionRuleException` rather than returning null — a dangling policy name would otherwise
be a row the purge job skips forever. `RetentionRule` holds the whole answer (GDPR basis, clock
column, action, period, floor, tables) and enforces the invariants, chiefly that a rule has no clock
**only** when it declares `keep_until_erased`.

The rule set, the reasoning behind each period and what is still owned by other items (S2-04
encryption at rest, S2-14 the privacy-mode rule, `PurgeExpiredData` itself) are in
`docs/architecture/retention.md`.
