# Authorization — one rule, every surface, and the test that keeps it that way

**Status:** Living document · **Owner:** Workstream H (security/privacy) with B (Laravel platform)
**Issues:** #88 (S2-06, this page) · #84/#85/#86 (the tables it guards) · #90 (S2-07, the `PolicyGate` for
tool calls) · #92 (S2-08, approvals) · #127 (the one bounded exception, §10)
**Source of truth:** `app/Policies/`, `app/Security/Authorization/`
**Spec:** blueprint §3.1, §4, §5.6 criterion 4, §11 · [ipc-contract §11](ipc-contract.md) ·
[pwa.md §2](pwa.md#2-routes) · ADR-002 D1/D10 · ADR-003 D7 · ADR-005 D1.6 ·
threat model **T-35**, **T-46**, **T-55**, **T-71**, **T-73**

## 1. The rule

Every §3.1 business object carries `organization_id`
([data-model §1.1](data-model.md#11-31-common-columns)). The whole of authorization in this
application is one question asked about that column:

> **A row is reachable by the members of the organization it belongs to, and by nobody else.**

`App\Security\Authorization\Tenancy` is that question, in one place. Everything else on this page
exists so the question is asked on every path, and so the answer is on the record.

### 1.1 There is no admin bypass

A role widens **which organizations are visible** — by being a membership — never **which rules
apply**. `docs/architecture/pwa.md` §2 states it for the brief pages ("There is no admin bypass; a
user without a membership sees the empty states"), and S2-06 makes it structural:

- no policy declares a `before()` method;
- the Gate has **no** `before` callback at all;
- `PolicySurfaceArchTest` fails if either appears.

Those two hooks are the only mechanisms in Laravel that can answer `true` for a row outside the
user's memberships. Removing them is what makes "no admin bypass" a property rather than a promise:
`IdorTest` runs its whole sweep a second time as an **owner** of the other organization, and gets
the same refusals as a stranger.

### 1.2 Identity comes from the session, never from the request

A ULID in a path identifies a *row*. It never identifies a tenant, a user or a role. The policy
reads `organization_id` off the row it was handed and the memberships off the authenticated user;
no policy takes a `Request` or calls `request()`, and `PolicySurfaceArchTest` asserts that too. This
is [ipc-contract §11.4](ipc-contract.md) ("Policies never trust request content for identity")
applied to the PWA, and `IdorTest` spells out the negative case: `?organization_id=…`,
`?user_id=…`, `?role=owner` on a forbidden brief are all still 403.

## 2. The parts

| Part | What it is |
|---|---|
| `App\Security\Authorization\Tenancy` | the membership check, and the only place that reads `memberships` for an authorization decision |
| `App\Policies\BusinessObjectPolicy` | the seven CRUD abilities for a §3.1 row, plus the two narrowings in §3 below |
| `App\Policies\…` (one per object) | names the table the shared rule applies to, so Laravel's discovery finds a policy and the arch test can see it |
| `App\Security\Authorization\Gates\Abilities` | every named ability, and which of them are read-only (that list is what decides whether an *allow* is audited) |
| `App\Security\Authorization\Gates\SurfaceGates` | the abilities that are not about one row: the PWA pages, the Livewire actions, the tenant gate |
| `App\Security\Authorization\OwnerAbilities` | the IPC contract's §6 operations (S1-08), unchanged by S2-06 |
| `App\Security\Authorization\Gates\TenantRowGuard` | the one read path into a tenant table that has no model yet (§4) |
| `App\Security\Authorization\Gates\AuthorizesQueuedWork` | a queued job's check, run as the user that dispatched it |
| `App\Security\Authorization\Gates\GatedTool` + `ToolAuthorizer` | a tool declares the ability and the row it wants; the gate decides before it runs |
| `App\Security\Authorization\Gates\AuthorizationAuditor` | the Gate `after` hook that writes the audit row (§5) |
| `App\Security\Authorization\Gates\Surface` + `SurfaceContext` | which of the five T-73 paths a decision was made on |
| `App\Security\Authorization\Maintenance\TenantWideMaintenance` | the marker for the one category with **no user principal** (§10) |
| `App\Security\Authorization\Maintenance\MaintenanceActor` | that category's audit actor shape, `maintenance:<Operation>` (§10.3) |

Policies are **not** registered in a provider. `App\Models\CRM\Customer` resolves to
`App\Policies\CRM\CustomerPolicy` by Laravel's own convention, and a convention cannot go stale the
way a registration array does — forgetting a line in a provider is exactly the gap S2-06 closes.
What `AuthorizationServiceProvider` does register is the surface gates and the audit hook.

## 3. The two narrowings

Both are properties of the table, so they are constants on the policy rather than overridden methods.

**`APPEND_ONLY`** — the row is evidence. `ai_runs`, `tool_runs`, `audit_events`,
`action_approvals`, `brief_deliveries`, `source_records` and `memory_sources` are readable by their
organization and are never updated or deleted through a policy. A delivery record that could be
edited would make a double send undetectable (§1.3); a `tool_runs` row whose `policy_decision` could
be rewritten would be worthless as evidence that the gate ran (§4).

**`IMMUTABLE_AFTER_FREEZE`** — `action_proposals`. The model already refuses a write to a frozen
column (`FrozenProposalException`, ADR-005 D1.6); the policy refuses the user-facing `update` as
well, so the refusal is a 403 with an audit row instead of an exception inside a save. Approving is
not an `update`: it is a separate, re-authenticated action in the PWA and never over the IPC
([ipc-contract §11.2](ipc-contract.md)).

And one rule that holds everywhere: **`forceDelete` is refused to everybody, on every table.** A
hard delete destroys the `retention_policy` / `retain_until` evidence the row carries
([retention.md](retention.md)); erasure is the retention job's, and the retention job answers to
`data_retention_rules` rather than to a policy.

## 4. A tenant table with no model yet

S2-03 landed twelve governance tables — `email_connections`, `external_accounts`, `sync_cursors`,
`mcp_servers`, `mcp_tools`, `mcp_grants` and the rest — with an `organization_id` and no Eloquent
model, so there is no policy for Laravel to discover and nothing for `Gate::authorize('view', $row)`
to resolve. A repository written against one of those before its model exists still has to run a
check, and `TenantRowGuard` is that check: it reads the row's `organization_id` **by id** and puts
that to the `accessOrganization` gate.

Two properties make it safe to keep rather than a stopgap:

1. the organization is read from the row, never taken from the caller, so an id in a request body
   can neither name a tenant nor widen one;
2. a missing row and a foreign row are the **same** refusal, so the refusal cannot be used to probe
   which ids exist (`IdorTest` asserts this per table).

It does not become the comfortable way to skip writing a policy: `PolicySurfaceArchTest` requires a
policy the moment one of those tables grows a model.

## 5. The audit row is the evidence the check ran

`AuthorizationAuditor` is registered as the Gate's `after` hook, so every decision in the
application passes through it exactly once — a policy method, a named gate, the IPC's
`OwnerAbilities`, a job's check, a tool's check. It writes:

- **every refusal**, as `authz.<ability>` with `outcome: refused`;
- **every allow of a mutating ability**, as `outcome: ok`;
- **nothing** for an allowed read, which would be a row per page render and would bury the two cases
  above.

Threat model **T-46** is why the breadth matters more here than integrity would: `audit_events` is a
table in the same SQLite file the core writes, so it is **not tamper-evident** in v1 (RR-05). What
the design offers instead is a row for every refusal and every mutation, on every surface.

The row names the actor (`user:<ulid>` in the §3.1 provenance grammar, or `guest`), the ability, the
subject type and id, the organization the decision was about, and the surface. `context` carries
**references only** — an ability, a surface, a result, a reason — and passes through the `Redactor`
on the way in (ADR-003 D7 row 8). `AuditCompletenessTest` asserts the context keys are exactly that
set and that no value quotes brief content or looks like a file path.

Two cases are worth knowing about:

- **"nothing answered" is a refusal.** A gate that is not defined, a policy that does not match, or
  a guest at a gate that takes a `User` all make `Gate::raw()` return `null`, which
  `Gate::authorize()` turns into a 403. That is recorded as refused with `reason: unanswered` — the
  one 403 that would otherwise have no row behind it, and a missing gate that is visible as a defect
  rather than as a quiet deny-all.
- **"the check could not be run" is a refusal.** A queued job whose actor has been deleted since
  dispatch never reaches the Gate, so `AuthorizationAuditor::refuse()` records it with
  `reason: actor_missing`.

The audit write is also not allowed to fail on a long string: `AuditLog` clamps `actor`, `action`,
`subject_type` and `subject_id` to their column widths. MySQL in strict mode rejects an over-long
value where SQLite accepts it, so without that clamp a refusal could be a 403 with a record on
`local` and a 500 with none on `server` — found by the `cross-account` group's MySQL leg, and now a
regression case in `AuditCompletenessTest`.

## 6. The five surfaces

Threat model **T-73** names five paths cross-account access can be attempted on. Each runs the same
gate; what differs is where the principal comes from.

| Surface | Principal | Where the check is |
|---|---|---|
| **API / HTTP** (PWA) | the session | `Gate::authorize()` in the controller; the route's ability is in `PWA_ROUTE_TABLE` |
| **Livewire action** | the session | `Gate::authorize()` at the top of each action method |
| **IPC** | the verified token's owner binding ([ipc-contract §11](ipc-contract.md)) | `IpcPrincipal::authorize()` with an `OwnerAbilities` ability; `IpcPolicyArchTest` owns that surface |
| **Queued job** | the `users.id` the job was dispatched with | `AuthorizesQueuedWork::authorizeJob()` |
| **AI / MCP tool** | the user the run belongs to | `ToolAuthorizer::authorize()` against the tool's `GatedTool::ability()` and `subject()` |

A queued job runs **as the person who asked for it**, with their memberships. There is no privileged
job identity to escalate to, which is the point: a job dispatched by a member of organization A
refuses organization B's rows exactly as the PWA would.

A tool is the surface where the *arguments are the model's*. So a tool may not carry identity: it
declares the ability it needs and the row it intends to touch, and `ToolAuthorizer` decides with the
dispatching user's memberships before anything executes (blueprint §4, "the LLM proposes, the
server-side `PolicyGate` decides"). This composes with the S2-13 refusals rather than duplicating
them — `ModelRouter` answers "may this model be used at all", a policy answers "may this user touch
this row", and both must pass.

## 7. What keeps it true

### 7.1 `PolicySurfaceArchTest` — how it decides what needs a policy

Three questions, all answered by reading the repository rather than a registry:

1. **Which tables are tenant data?** Every table in the schema with an `organization_id` column.
   A migration adding one is in scope the moment it runs.
2. **Which of those have a policy?** The model for a table is found by instantiating every model
   class under `app/` and asking it for its table; the policy is whatever Laravel's own discovery
   resolves for that model. A table with a model and no policy fails. A table with **no model** is
   exempt — and the test also asserts it really has no model, so the exemption liquidates itself the
   day somebody writes one.
3. **Which surfaces reach tenant data?** A route handler, Livewire component, queued job or tool
   class whose source names one of those models or calls `DB::table()`. Such a surface must name an
   ability.

Plus three rules that need no derivation: every executable class under `app/AI/Tools/` and
`app/MCP/` must implement `GatedTool`; every Livewire action that is not on a guest-guarded route
must authorize; and every route must appear in `PWA_ROUTE_TABLE`, so a **new route is a deliberate
decision** rather than a silent addition.

The detectors are in `Tests\Security\Support\PolicySurface` and are themselves tested, against the
deliberately unguarded fixtures in `tests/Security/Support/Surfaces/` — a detector that has never
seen an unguarded surface is not known to recognise one.

### 7.2 The test matrix

| Test | Proves | Profile |
|---|---|---|
| `tests/Security/PolicySurfaceArchTest.php` | §7.1, and that no `before()` hook exists anywhere | `local` |
| `tests/Security/IdorTest.php` | a member (and an **owner**) of organization A is refused every row of B, on read, write and delete, for **every** table in the schema; by version id and document id over HTTP; with a spoofed id in the query string; after a membership is revoked | `local` |
| `tests/Integration/BackendContract/CrossAccountContractTest.php` | the §5.6 criterion-4 shape on all five paths, the mail-account isolation, and exact ULID comparison whatever the engine collates | **both** (ADR-002 D10) |
| `tests/Security/AuditCompletenessTest.php` | an `audit_events` row per surface, naming actor, ability and outcome, with a refusal recorded as a refusal | `local` + `cross-account` legs |
| `tests/Security/TenantRowFactorySelfTest.php` | the sweep's engine can build a real row for every tenant table, so no table drops out of the sweep quietly | `local` |
| `tests/Security/IpcPolicyArchTest.php` | the `/ipc/` surface (S1-08), unchanged | `local` |
| `tests/Security/MaintenanceCategoryArchTest.php` | §10: the members of the no-principal category are exactly the enumerated list, none is a user-facing surface, nothing user-facing reaches one, none takes an identity, and each writes a `maintenance:` actor | `local` |

The `cross-account` group runs on both ADR-002 D1 profiles because the tenant boundary is a string
comparison and a join: MySQL's default collation is case-insensitive where SQLite's `BINARY` is not,
and a membership lookup that lost either property would let a near-miss id through. A policy that is
right on SQLite and wrong on MySQL is a policy that is wrong in production.

## 8. Adding something

- **A new business table** → add the policy (`App\Policies\…\<Model>Policy extends
  BusinessObjectPolicy`). `IdorTest` and `CrossAccountContractTest` pick it up from the schema with
  no edit; `PolicySurfaceArchTest` fails until the policy exists.
- **A new route** → add it to `PWA_ROUTE_TABLE` with the ability its handler authorises, or `null`
  if it reaches no tenant data (the test then checks it really does not).
- **A new Livewire action** → `Gate::authorize(Abilities::…)` at the top.
- **A new queued job that touches a row** → `use AuthorizesQueuedWork` and `authorizeJob()` before
  the write. A job that touches no tenant data needs nothing, and the test can tell the difference.
- **A new tool** → implement `GatedTool` and call `ToolAuthorizer::authorize()`.
- **A new ability** → add it to `Abilities`, define it in `SurfaceGates` if it is not a policy
  method, and decide whether it is read-only. Anything not listed as read-only is audited on allow,
  which is the safe default.
- **An operation that has no user at all** → read §10 first. It is a short list, it is closed, and
  joining it is a reviewed decision rather than an import.

## 9. What this does not cover

- **The `PolicyGate` for tool *arguments*** — schema, destination, data class, budget (blueprint §4)
  landed with **S2-07** (#89): [policy-gate.md](policy-gate.md). S2-06 answers "may this user touch
  this row"; S2-07 answers "may this action be performed at all, and does it need a human". Both
  must pass, they are independent, and the gate's decisions are audited through the same `AuditLog`
  writer (`policy.<tool>` rows alongside the `authz.<ability>` ones, asserted in the same
  `AuditCompletenessTest`).
- **The approvals engine** — freezing, approving and executing an outbound action is **S2-08**
  (#92). The policy layer refuses an `update` to a frozen proposal; it does not implement approval.
- **Re-authentication** — `App\Security\Authorization\Reauthentication` (S2-05) is a separate
  requirement, not a stronger policy: a proof is bound to one action and one single-use nonce, and a
  merely fresh session is not a proof. A critical action needs the policy **and** the proof.
- **The provider half of T-73** — mail actually fetched or sent through the wrong account at a live
  provider is **E3-1**; there is no live connector in Sprint 2.
- **Admin surfaces** (changing a membership, renaming an organization) are **S2-19**. The policies
  refuse those writes today because nothing grants them.
- **Operations with no user principal at all** are the one exception to §1, and they are bounded
  rather than uncovered: §10.

## 10. Tenant-wide maintenance — authorised by not being a user action (#127)

§6 says a queued job runs as the person who asked for it and that there is no privileged job
identity to escalate to. Three operations in this product cannot satisfy that, and none of them is
a defect:

| Operation | Why no user can own it | Status |
|---|---|---|
| **App-key re-encryption** — `App\Security\Secrets\AppKeyRotation`, ADR-003 D8 | `core.app-key` is install-wide, so no dispatcher's memberships describe what the rotation covers; and four of the eight tables it must rewrite (`ai_runs`, `tool_runs`, `source_records`, `memory_sources`) are `APPEND_ONLY` by §3, so `update` is false for them to **every** user by design | **Shipped** (S2-04), the category's first member |
| **The retention purge** — `app/Security/Retention/` (S2-03) | deletes rows across every tenant on a schedule, with nobody asking; it answers to `data_retention_rules` rather than to a policy, which §3 already says out loud | Not written |
| **The backup job** — ADR-002 D5 | reads every table, likewise | Not written |

A rotation that honoured the policy would leave the install half under each key. One that overrode
it would be the privileged job identity §6 exists to prevent. The honest reading is the third one:
**re-encryption is not a user action at all**, and neither are the other two. They belong with the
migration that first wrote those columns, which is not a policy surface either.

That is a real category. Until #127 it was an *unnamed* exception — and an unnamed exception is how
the next author justifies the privileged job identity. So it has a name, a closed membership and a
test.

### 10.1 The marker

`App\Security\Authorization\Maintenance\TenantWideMaintenance`, an **interface** with one method:

```php
interface TenantWideMaintenance
{
    public function maintenanceOperation(): string;   // the class basename; the trail's name for it
}
```

**Why an interface rather than an attribute.** An attribute labels; this marker has to *do* three
things, and only one of them is labelling.

1. It must make the audit actor a consequence of membership rather than a second convention.
   `MaintenanceActor::for($this)` takes a `TenantWideMaintenance`, so a member cannot carry the
   marker and still write `job:…` or `user:…` by hand — the arch test reads the call, and the
   constructor of the actor string reads the method. With an attribute, the marker and the actor
   would be two unrelated edits, and the second one would be the one people forget.
2. It must be visible to the tools that already read this repository structurally.
   `Tests\Security\Support\PolicySurface::classesImplementing()` — the S2-06 detector — enumerates
   interfaces today, so the category reuses the reader that already enumerates `GatedTool` and
   `ShouldQueue` rather than adding a second mechanism. PHPStan sees an interface too; an attribute
   is invisible to it.
3. It must be a type, because the rules are about *co-occurrence*: a member may not also be a
   `ShouldQueue` or a `GatedTool`, and "this class is two contradictory things" is a statement about
   types.

The cost of an interface is that a member must be a class with an instance. That cost is the
feature: a member is resolvable from the container, nameable in the trail, and testable.

### 10.2 Membership is enumerated

`tests/Security/MaintenanceCategoryArchTest.php` holds `TENANT_WIDE_MAINTENANCE` — class → the
reason that class has no user principal — and fails the build when a class under `app/` carries the
marker and is not on it, or when a listed class no longer carries it. The reason is checked for
being a sentence rather than a restatement of the class name.

This is the shape `tests/Security/` already uses twice: `IpcPolicyArchTest` pins the IPC pre-owner
set to exactly one route, and S2-14's `NoSocketTransportArchTest` pins the egress call sites to a
table of file → reason. The second is the one this follows, because the *reason* column is what a
reviewer needs when the next entry is proposed: a list of class names says what is exempt, a list of
reasons says whether the next one belongs.

### 10.3 The audit actor is a distinct shape

A member's `audit_events.actor` is `maintenance:<Operation>` (`MaintenanceActor`), not `job:<Name>`.

Reusing `job:` was the obvious move and is not enough, because **`job:` does not mean "nobody
asked"**: this repository writes it for paths a person is standing in front of before they have a
`users.id` — `job:CreateOwnerForm` is a Livewire submit, `job:IpcCreateOwner` a menu-bar click,
`job:PasskeyLogin` somebody signing in. A reader filtering the trail on `job:` would get a mixture
of "the system did this on its own" and "a user did this before they had an id", which is exactly
the distinction T-46 wants kept. `maintenance:` is a shape no user-facing surface produces, so "did
anything reach every tenant's rows with nobody asking, and when" is one `LIKE 'maintenance:%'`.

The §3.1 `provenance` grammar pinned by `packaging/fixtures/crm/crm.schema.json` is deliberately
**not** widened to admit `maintenance:`: a maintenance operation never *authors* a business row, so
admitting it to the authorship grammar would only create the possibility §10.4 forbids. `AuditLog`
maps a maintenance actor onto `job:<Operation>` with origin `sync` for the provenance object and
keeps the distinct shape in `actor` — the column that is about who *acted*.

`AuditCompletenessTest` is where this is asserted, alongside the five user surfaces, rather than in
a second audit test: there is one audit path, and the point of the category is that it is legible in
the same trail.

### 10.4 What the category may **not** do

**It is not a way to reach tenant data on behalf of a user.** That is the failure mode it would
otherwise become, and every rule below exists to stop it:

- **A member is unreachable from any user-facing surface.** No route, no Livewire action, no tool,
  no `ShouldQueue` class may name one; a member may not itself be a `ShouldQueue` job, a
  `GatedTool`, a Livewire component, or carry `AuthorizesQueuedWork`. A member runs from the
  scheduler or an artisan command. A PWA admin action may *request* maintenance — write the
  envelope, set a flag, record the request — but it may not call the pass inline, because then a
  user has reached every organization's rows through a class no policy is about. (This is why
  ADR-003 D8's admin rotation action starts a rotation rather than performing one.)
- **A member takes no identity.** No `auth()`, no `request()`, no `Gate::forUser()`, no `User` or
  `Request` parameter. A member that needs to know who asked is a user action and belongs behind a
  policy. The category is authorised by there being *no* principal, not by outranking one.
- **A member does not narrow itself to one organization.** "Tenant-wide" is the justification: an
  operation that touches one tenant's rows has a tenant, therefore has members, therefore has a
  policy.
- **A member returns no tenant data to a caller.** Counts, table and column names, outcomes — what
  `AppKeyRotation::handle()` returns and what its audit row carries.
- **The marker authorises no ability.** It is not a bypass of `BusinessObjectPolicy` and not a
  `before()` hook by another route (§1.1): it is the statement that no policy applies because there
  is no principal for one to be about. Nothing calls the Gate on a member's behalf.

Every bullet above is a test in `MaintenanceCategoryArchTest`, and the rules are proved against
`tests/Security/Support/Surfaces/UnboundedMaintenancePass.php` — a fixture that carries the marker
and breaks all of them — on the same principle as §7.1's unguarded fixtures: a rule that has never
seen a violation is not known to recognise one.

### 10.5 For the authors of the purge and the backup

The purge (S2-03) and the backup (ADR-002 D5) are **not** retrofitted here; they are not written.
When they are:

1. implement `TenantWideMaintenance` and return the class basename;
2. add the class to `TENANT_WIDE_MAINTENANCE` with the reason it has no user principal — not what it
   does;
3. write every audit row with `MaintenanceActor::for($this)`;
4. drive it from the scheduler or an artisan command, and from nothing else;
5. read §10.4. If the operation needs to know who asked, it is not a member — it is a user action,
   and the answer is a policy.

The purge has one wrinkle worth stating in advance: §3 already says `forceDelete` is refused to
everybody on every table, *and* that erasure is the retention job's. Those are consistent precisely
because the purge is a §10 member — it does not force-delete as a user with a very strong role, it
deletes as an operation no user is behind. Writing it as a `ShouldQueue` job with a service account
would be the privileged identity, under a new name.
