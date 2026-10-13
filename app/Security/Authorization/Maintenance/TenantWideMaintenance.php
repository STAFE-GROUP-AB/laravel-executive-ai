<?php

declare(strict_types=1);

namespace App\Security\Authorization\Maintenance;

/**
 * **Tenant-wide maintenance: authorised by not being a user action** (issue #127,
 * [authorization.md §10](../../../../docs/architecture/authorization.md)).
 *
 * S2-06 made one rule structural: every surface that reaches tenant data runs as a
 * **user**, with that user's memberships, and there is no privileged job identity to
 * escalate to (`AuthorizesQueuedWork`, threat model T-73). Three operations in this
 * product cannot satisfy that rule, and not for want of plumbing:
 *
 *  - **the app-key re-encryption pass** (ADR-003 D8, `AppKeyRotation`): the key is
 *    install-wide, so no dispatcher's memberships describe its scope, and four of the
 *    eight tables it must rewrite — `ai_runs`, `tool_runs`, `source_records`,
 *    `memory_sources` — are append-only by policy, so `update` is false for them to
 *    every user *by design*;
 *  - **the retention purge** (S2-03, `app/Security/Retention/`): deletes rows across
 *    every tenant on a schedule, answering to `data_retention_rules` rather than to a
 *    policy;
 *  - **the backup job** (ADR-002 D5): reads every table, likewise.
 *
 * Each of them is authorised by **not being a user action**. That is a real category,
 * and this interface is its name. Before #127 it was an unnamed exception, which is how
 * the next author justifies the privileged job identity S2-06 exists to prevent.
 *
 * ## What carrying this marker means
 *
 * 1. **The class is on a list.** `tests/Security/MaintenanceCategoryArchTest.php`
 *    enumerates every class under `app/` that implements this interface and fails until
 *    it appears in `TENANT_WIDE_MAINTENANCE` with a sentence saying why it has no user
 *    principal. Growing the category costs a reviewed decision, not an import.
 * 2. **Nothing user-facing may reach it.** No route, no Livewire action, no tool, no
 *    `ShouldQueue` class may name a member — and a member may not itself be a
 *    `ShouldQueue` job, a `GatedTool`, or read `auth()`, `request()` or a `User`. The
 *    same test asserts all of it.
 * 3. **The trail says the system did it.** The audit actor is
 *    `MaintenanceActor::for($this)` — `maintenance:<Operation>`, a shape no user action
 *    can produce — so "nobody asked for this" and "a user asked for this" are two
 *    readable facts rather than one.
 *
 * ## What this category may **not** do
 *
 * **It is not a way to reach tenant data on behalf of a user.** That is the failure mode
 * it would otherwise become, and the whole point of bounding it:
 *
 *  - A member may not be invoked from a user-facing surface, directly or through a job a
 *    user dispatches. A member runs from the scheduler or an artisan command. A PWA
 *    admin action may *request* maintenance (write the envelope, set a flag, record the
 *    request); it may not call the pass inline, because then a user has reached every
 *    organization's rows through a class that answers to no policy.
 *  - A member may not accept, look up or act for a user. No `auth()`, no `request()`, no
 *    `Gate::forUser()`, no `User` parameter. A member that needs to know who asked is a
 *    user action and belongs behind a policy.
 *  - A member may not narrow itself to one organization. "Tenant-wide" is the
 *    justification: an operation that touches **one** tenant's rows has a tenant, and
 *    therefore has members, and therefore has a policy.
 *  - A member may not return tenant data to a caller. It reports counts, table and
 *    column names and outcomes — what `AppKeyRotation::handle()` returns and what its
 *    audit row carries.
 *  - The marker authorises **no** ability. It is not a bypass of
 *    `BusinessObjectPolicy`: it is the statement that no policy applies, because there
 *    is no principal for one to be about. Nothing may call `Gate::allows()` on behalf of
 *    a member.
 *
 * ## For the authors of the purge and the backup
 *
 * Implement this interface, add the class to `TENANT_WIDE_MAINTENANCE` with the reason,
 * write the audit row with `MaintenanceActor::for($this)`, and drive the operation from
 * the scheduler or a command. If the operation needs a user, it is not a member.
 */
interface TenantWideMaintenance
{
    /**
     * The operation's name in the audit trail — the class basename, which the arch test
     * holds it to, so the trail cannot drift from the code by a typo.
     *
     * `MaintenanceActor::for()` is what turns it into an actor; it refuses a name that
     * is not a bare identifier, so a member cannot smuggle a `user:<ulid>` into the one
     * column that distinguishes the system from a person.
     */
    public function maintenanceOperation(): string;
}
