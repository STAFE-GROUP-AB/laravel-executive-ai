# Authorization — one rule, every surface, and the test that keeps it that way

**Status:** Living document · **Owner:** Workstream H (security/privacy) with B (Laravel platform)
**Issues:** #88 (S2-06, this page) · #84/#85/#86 (the tables it guards) · #90 (S2-07, the `PolicyGate` for
tool calls) · #92 (S2-08, approvals)
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

## 9. What this does not cover

- **The `PolicyGate` for tool *arguments*** — schema, destination, data class, budget (blueprint §4)
  is **S2-07** (#90). S2-06 answers "may this user touch this row"; S2-07 answers "is this a
  sensible call at all". Both must pass.
- **The approvals engine** — freezing, approving and executing an outbound action is **S2-08**
  (#92). The policy layer refuses an `update` to a frozen proposal; it does not implement approval.
- **Re-authentication** — `App\Security\Authorization\Reauthentication` (S2-05) is a separate
  requirement, not a stronger policy: a proof is bound to one action and one single-use nonce, and a
  merely fresh session is not a proof. A critical action needs the policy **and** the proof.
- **The provider half of T-73** — mail actually fetched or sent through the wrong account at a live
  provider is **E3-1**; there is no live connector in Sprint 2.
- **Admin surfaces** (changing a membership, renaming an organization) are **S2-19**. The policies
  refuse those writes today because nothing grants them.
