Append-only audit events for actions, approvals, refusals and access (§10, §11).

`AuditLog` is the single writer of `audit_events` (S1-02): every context array passes through the `Redactor` before insert, and the actor follows the §3.1 provenance grammar (`user:<ulid>`, `job:<Name>`). Authentication events (`auth.*`) are listed in docs/architecture/auth.md §3.
