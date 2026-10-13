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

**Before writing `PurgeExpiredData`, read [authorization.md §10](../../../docs/architecture/authorization.md).** The purge deletes rows across every tenant on a schedule with nobody asking, so it has no user principal and cannot be a `ShouldQueue` job running as its dispatcher (S2-06, threat model T-73). It is the second member of the `TenantWideMaintenance` category named by #127: implement the marker, add the class to `TENANT_WIDE_MAINTENANCE` in `tests/Security/MaintenanceCategoryArchTest.php` with the reason it has no user, write every audit row with `MaintenanceActor::for($this)`, and drive it from the scheduler — never from a route, a Livewire action or a job a user dispatches. §10.4 is binding; §10.5 is the checklist. Writing it as a queued job with a service account would be the privileged job identity under a new name.
