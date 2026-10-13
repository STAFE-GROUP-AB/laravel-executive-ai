# The local CRM — the four screens, and where the tenant boundary is

**Status:** Living document · **Owner:** Workstream E (CRM) with C (TALL/PWA)
**Issues:** #91 (S2-09, this page) · #84 (S2-01, the tables and the search corpus) · #88 (S2-06,
authorization) · #86 (S2-03, retention) · #92 (S2-08, the approvals engine) · #94 (S2-10, the
activity timeline)
**Source of truth:** `app/CRM/`, `app/Livewire/Crm/`, `routes/crm.php`, `resources/views/crm/`
**Spec:** blueprint §1.2 (the four routes), §3 (module contracts), §3.1 (common columns), §3.2 (what
the records are), §4.2 ("AI unavailable" never blocks), §11 (CRM deletion is approval-driven), §13.1
("Local CRM CRUD") · ADR-002 D1/D3/D4/D10 · [authorization.md](authorization.md) ·
[pwa.md](pwa.md) · [retention.md](retention.md) · [module-contracts.md](module-contracts.md) §3

## 1. What there is

| Route | Name | Component | Renders |
|---|---|---|---|
| `GET /crm/customers` | `crm.customers` | `App\Livewire\Crm\Customers` | Customers with their legal identity, owner and status |
| `GET /crm/contacts` | `crm.contacts` | `App\Livewire\Crm\Contacts` | People, each with **several** email addresses and the customers they belong to |
| `GET /crm/projects` | `crm.projects` | `App\Livewire\Crm\Projects` | Projects per customer, with status and calendar deadlines |
| `GET /crm/tasks` | `crm.tasks` | `App\Livewire\Crm\Tasks` | Todos, including the follow-ups §3.2 asks the assistant to remember |

Four routes, and §1.2 fixes exactly those four. **There is no `{record}` route, no create route and
no destroy route.** The list, the detail panel and the one form that both creates and edits live on
the same page: a create and an edit are the same fields over the same record type, and a second URL
for them would be a second place for the ability check and the empty state to drift out of step. The
consequence worth stating is that **no record ULID ever appears in a URL here**, so the whole IDOR
surface of the CRM is the Livewire action, which §3 scopes and the CRM policies decide.

No Filament (§0). This is the S1-04 app shell with hand-written Livewire 3 and the S0-24 tokens:
`@livewireScripts` under S1-02's CSP nonce, no CDN asset, no inline script, style or handler
(`CrmScreenAccessibilityTest`). `/crm` was already on the service worker's deny list
([pwa.md §4](pwa.md)), so every page here is network-only like `/home` and `/briefs`.

### 1.1 The data layer

| Class | What it is |
|---|---|
| `App\CRM\Eloquent\WorkspaceBoundary` | the one place a `WorkspaceScope` becomes permission to read an organization |
| `App\CRM\Eloquent\WorkspaceQueries` | **the only way a CRM query is built** (§3) and the only place a search term reaches the corpus |
| `App\CRM\Eloquent\CrmEntity` | the four record types, and everything that differs between them stated once |
| `App\CRM\Eloquent\EloquentWorkspaceRepository` | the frozen §3 `WorkspaceRepository`: the read-only API the agent, the MCP tools and the brief generator share |
| `App\CRM\Eloquent\CrmRepository` | the screens' reads and writes — an offset page a person can navigate, select options, create, update, restore |
| `App\CRM\Eloquent\RetentionStamp` | the §3.1 retention columns, derived from the row's own rule (§5) |
| `App\CRM\Eloquent\ContactAddresses` | the module's **only** call site for matching a contact by email address (§6) |
| `App\CRM\Eloquent\ContactEmailSync` | writes the list of addresses a contact is known by, against two unique indexes that do not know about `deleted_at` |
| `App\CRM\Eloquent\CurrentWorkspace` | the signed-in session's scope, or **null** — which is an empty state, not a 403 (§4) |
| `App\CRM\Deletions\CrmDeletions` | the §11 approval-driven deletion: propose, approve, soft-delete (§7) |
| `App\Livewire\Crm\CrmScreen` | what the four screens share: list, search, filters, form panel, deletion |

Two repositories rather than one, because they answer to different contracts. The frozen one may
never grow a write (module-contracts.md §3: *"CRM writes — mutations go through action proposals and
approvals; the repository is read-only"*), and the screens' one may never leak an Eloquent model into
a prompt. What they share is the part that matters: both obtain every query from the same place.

## 2. The read contract, and what it promises

`EloquentWorkspaceRepository` implements `App\CRM\WorkspaceRepository` (S0-16) over the S2-01 schema.
Four promises, each pinned by `tests/Integration/BackendContract/WorkspaceRepositoryContractTest.php`
in ADR-002 D10's **`repository`** group, so the `server-profile` lane runs them against MySQL too:

- **read models, not Eloquent** — `CustomerSummary`, `ContactSummary`, `ProjectSummary`,
  `TaskSummary`, `ActivityEntry`, so a provider-neutral shape crosses the module boundary;
- **`id` ascending, bounded by the page** — identical ids on both profiles for the frozen S0-18
  fixture. `timeline()` is the contract's own exception: newest first, then by id;
- **one read transaction per call** (ADR-002 D3), so a page is a consistent snapshot even while a
  sync job is writing;
- **a refusal is a refusal** — a scope whose actor has no membership raises
  `WorkspaceAccessDeniedException` rather than returning an empty page, so the §11 cross-account
  tests can tell "nothing there" from "refused".

The test lists the fixture's ULIDs **literally**. A test that derived them from the query under test
would pass whatever the ordering turned out to be; one that lists them fails when the order changes,
which is the only reason the group exists.

Pagination in the contract is **keyset** (`QueryPage{limit, after}`) because the consumers are the
brief generator and `crm.search`, which must never be able to pull a whole table into a prompt. The
screens use an offset page instead, because a person navigates pages by number and a keyset cursor
cannot answer "page 3 of 7". Both run through `WorkspaceQueries`.

## 3. Where the tenant boundary is enforced

> `WorkspaceQueries::of()` asks whether the scope may read the organization **and** applies the
> `organization_id` predicate, in the same call. A caller cannot obtain an unscoped CRM query.

That sentence is the design. S2-06 made the per-row decision structural
([authorization.md §1](authorization.md)); this is the query-shaped half of the same rule, and the
difference from a `where` the caller remembers to add is that there is nothing to remember. There is
**not one** `where organization_id` in `CrmRepository` or in `EloquentWorkspaceRepository` — grep
them. The predicate exists in exactly one method.

`WorkspaceBoundary` puts the question to the `accessOrganization` gate, which is
`Tenancy::isMember()` — the same check every policy delegates to. There is no second tenant rule
here to keep in step with the policies, and no `before()` hook to bypass it. Three properties:

1. **A missing actor is a refusal that still leaves a record.** A scope whose `actorUserId` no longer
   exists never reaches the Gate, so the `after` hook cannot audit it;
   `AuthorizationAuditor::refuse()` records it with `reason: actor_missing`, exactly as
   `AuthorizesQueuedWork` does for a job whose dispatcher was deleted (T-46).
2. **A foreign id and an unknown id are the same answer.** `CrmRepository::find()` returns null for
   both and the screen answers 404, so the CRM cannot be used as an existence oracle for another
   workspace's ULIDs — the property `TenantRowGuard` has for the tables without a model
   ([authorization.md §4](authorization.md)).
3. **The organization comes from the scope, never from the form.** `create()` takes it from the
   authorized scope, so a hidden field naming another workspace is not a thing the code can express
   (ipc-contract §11.4 applied to the PWA). A customer id typed into the contact form that belongs
   elsewhere is dropped by a scoped lookup rather than written and refused later.

What the contract test proves, beyond the per-row sweep `IdorTest` already runs over every table:
a repository handed **another organization's id** returns nothing, with no caller-side filtering
anywhere; a repository handed a scope **without a membership** refuses; and the builder carries the
predicate before any caller sees it.

### 3.1 Every Livewire action names an ability

[authorization.md §6](authorization.md) lists the Livewire action as one of the five T-73 surfaces,
and `PolicySurfaceArchTest` fails the build for an action that runs no named check.

- The page is `Abilities::VIEW_CRM` — **one** ability for all four screens, because they ask the same
  narrower question the other page abilities ask ("is this a signed-in user of this install") and the
  tenant decision stays with the row. Four abilities would be four copies of one question.
- Every record action puts a **row** to the policy: `view`, `create`, `update`, `delete`, `restore`.
  So the decision is `CustomerPolicy`'s and friends', made on the row's own `organization_id`, with an
  audit row for every refusal and every mutation.
- Approving a deletion authorizes **two** things: `delete` on the record, and `create` on the
  `action_approvals` row about to be written.

S2-09 also widened the arch test's reader. `PolicySurface::livewireActions()` used to look only at
methods a component declared itself, which would have made a shared abstract base — `CrmScreen`, with
ten actions on it — the one place an unguarded action is invisible to the test. It now filters by the
**file** a method was written in: anything under `app/` is ours and must authorize. The file rather
than the namespace, because PHP attributes a trait's methods to the class using the trait, so
`Livewire\WithPagination::nextPage()` reports a CRM screen as its declaring class and no namespace
test could tell it apart from one of ours.

## 4. No membership is an empty state, not a 403

`docs/architecture/pwa.md` §2: *"There is no admin bypass; a user without a membership sees the empty
states."* `CurrentWorkspace::scope()` therefore returns **null** rather than aborting, and the screen
renders `crm.no_workspace` — a 200 with nothing in it. An *action* still refuses, because an action is
a deliberate mutation of a record in a workspace and there is no workspace to mutate.

Empty states are part of the work and there are three kinds, because "nothing here" has three
different meanings and only one of them is a dead end:

| State | What it says | Offers |
|---|---|---|
| no workspace | the account belongs to no workspace, so there is nothing to read | nothing — this is not the person's to fix |
| no records yet | the workspace is empty | the "New …" button, and for projects a note that a project needs a customer |
| nothing matched | the filter or the search excluded everything | how the search works (every word must match; `*` for a prefix) and "Clear filters" |

The error state is separate again: a refusal or a lapsed approval is rendered as `role="alert"` in
words — never a stack trace, and never a message quoting record content.

## 5. Retention is stamped from the rule, not from a literal

Every row these screens write carries `retention_policy` and `retain_until`, and
`RetentionStamp::columnsFor()` reads both from `RetentionRules::forTable()`, which **throws** for a
table no rule governs. S2-03's argument is that a `retention_policy` holding a value nothing resolves
is worse than holding nothing — the purge job skips such a row forever, which is data kept by
accident — so the name is never a literal in a form or a migration default.

`retain_until` is derived from the rule's **own clock**, which is why it is written on every save and
not once on create:

| Rule | Tables | Clock | Effect here |
|---|---|---|---|
| `crm_records` | customers, projects, tasks, `customer_contact`, `task_assignees` | `deleted_at` | `keep_until_erased`, no period: `retain_until` stays null and the ninety-day undo window starts at the deletion |
| `contact_personal_data` | contacts, `contact_emails` | `updated_at` | three years, anonymised rather than purged — so **every edit moves the date forward**, which is what "three years without activity" means |

A soft delete therefore respects the rule by construction: `delete()` fills the clock column and the
stamp that follows reads the value the delete just wrote.

## 6. Search reaches the existing corpus, never a `LIKE`

ADR-002 D4 indexes three entities — `customers`, `contacts`, `communications` — and only
`string`/`text` columns on them, so FTS5 on the `local` profile and InnoDB FULLTEXT on `server` cover
exactly the same text and the `search` contract group can demand the same hit set.
`WorkspaceQueries::search()` is the only place a term typed on a CRM screen reaches it.

**The columns this module's search depends on** (all plaintext):

- `customers.display_name`, `customers.legal_name`, `customers.notes`
- `contacts.display_name`, `contacts.given_name`, `contacts.family_name`, `contacts.title`,
  `contacts.notes`

Nothing else. In particular **not** `contact_emails.address`: the `contacts` corpus does not index
addresses on either profile, and `CrmSearchContractTest` pins that ("contacts · addresses are not
indexed"). Sorting is by `display_name`. So S2-04's encryption of that column
([§6.1](#61-the-one-column-the-screens-look-up-by-hash)) changes the CRM search not at all — which is
the claim that mattered while the two items were open at the same time.

Two consequences that are deliberate rather than incidental:

- **`projects` and `tasks` have no index of their own**, so a term typed on those screens is resolved
  through the **`customers`** corpus and matched against `customer_id` — the one piece of indexed
  text that relates to them. The copy says so ("Projects are searched through their customer"). The
  alternative, `LIKE '%…%'` on `name`, is neither the same query on both engines (collation,
  diacritics) nor indexed at all.
- **A term with no match narrows to nothing**, through `whereIn` on an empty list. Leaving the
  builder untouched would answer a failed search with the whole table, which is the worst available
  answer because it looks like a result.

A term contributes at most `QueryPage::MAX_LIMIT` hit ids. The corpus returns ids ascending, so that
is the first 500 matches in id order; past it, the person narrows by typing a second word, since
every term must match.

### 6.1 The one column the screens look up by hash

`contact_emails.address` is encrypted at rest (S2-04, #87). Laravel's encrypter is randomised, so the
same address yields different bytes every time and the column cannot be compared, prefixed or `LIKE`d
in SQL at all; the identity of an address is indexed through `address_hash`, a keyed HMAC, and
`ContactEmail::forAddress()` is the scope that hashes and hits it — under each previous application
key as well, so a lookup still finds a row an interrupted key rotation has not rewritten yet
(ADR-003 D8).

`App\CRM\Eloquent\ContactAddresses` is this module's **only** call site for matching a contact by
address, and both of its reads go through that scope. Keeping them in one class is what made the
encryption a two-line change here rather than a hunt through forms and repositories;
`ContactsCrudTest` ("finds a contact by any of its addresses", "refuses an address another contact
already uses") is what proves the lookup still works through the index it actually has. Normalisation
is `ContactEmail::normalize()`, the same function the hash is taken over, so a lookup cannot normalise
differently from the index.

Two reads, deliberately different: `contactIdsFor()` sees live rows only, because the read contract
says a soft-deleted row is never returned; `isTaken()` counts soft-deleted rows too, because a unique
index does not know about `deleted_at` and an address held by a deleted contact is still held as far
as the database is concerned. Answering otherwise would accept the form and fail at the insert.

## 7. Deleting a record is two recorded decisions

§11: *"Mail send, CRM deletion, GitHub write and external forms are always approval-driven."* So
there is no path from a button to a `delete()`.

1. **Propose.** A frozen `action_proposals` row of kind `crm_deletion` carrying what is about to be
   deleted, its RFC 8785 content hash, a single-use nonce, an idempotency key and an expiry
   (24 hours — long enough to sleep on, short enough that a forgotten proposal cannot be approved a
   month later). The record is untouched; the screen shows it as awaiting approval. From this moment
   the content is immutable: `ActionProposal` refuses to save a change to a frozen column.
2. **Approve.** An `action_approvals` row naming the approver and the hash they saw, and only then the
   soft delete, inside one transaction with the proposal moving to `executed`.
   `action_approvals_proposal_unique` and `action_approvals_nonce_unique` make a replayed approval
   *unrecordable* rather than merely detectable — the ADR-009 D1.2 argument.

"Is there already a pending deletion for this row" is an indexed lookup on `action_proposals.source_id`
(`<entity>:<ulid>`, the §3.1 lineage column pointing at the system of record) rather than a JSON query
two database engines spell differently. A double-clicked Delete button therefore proposes once.

Three things this is deliberately not:

- **Not a hard delete.** `forceDelete` is refused to everybody on every table because it destroys the
  retention evidence the row carries ([authorization.md §3](authorization.md)); erasure is the
  retention job's, which answers to `data_retention_rules` and not to a user. Restoring needs no
  approval, because it destroys nothing.
- **Not a replacement for the policy.** The screen authorizes `delete` on the row *before* calling in,
  so the tenant decision is still the policy's and still leaves its own audit row. Both must pass.
- **Not the S2-08 engine.** S2-08 (#92) owns `app/Approvals/` — the generic freeze/approve/execute
  machinery and the approvals screen. `CrmDeletions` writes the two §3.1 rows for one kind, so the CRM
  screens are approval-driven from their first commit instead of after S2-08 lands. When it does, the
  writes here move behind its API; the rows are already the shape it defines. The pending count in the
  shell header still comes from `PendingApprovals`, which S2-08 owns and which answers zero today.

## 8. AI is never required

§4.2: *"AI unavailable"* must never block the CRM. The local CRM is the system of record; the
assistant is a reader of it. A screen that refused to open, or that told the owner to configure a
provider before they could add a customer, would make the product's most basic function depend on the
part most likely to be missing on day one.

`CrmWorksWithoutAiTest` holds it in two ways, and the second is the one that lasts:

- **behavioural** — every §1.2 CRM route answers 200, and create, update, delete and search all
  succeed, in every `ai_state` that is not `ready` and with no provider configured at all; no copy on
  the page says AI is required, in either language;
- **structural** — the CRM screens and their data layer name nothing in the assistant's namespaces at
  all. A dependency cannot be "optional" if it is there, so the test reads the sources. That is what
  stops a later commit from reading the readiness report inside a CRM screen and only failing when
  somebody re-reads the behavioural cases.

The AI state **badge** is in the shell chrome on these pages, like everywhere else; the **banner** is
not. The badge is a status indicator; the banner is a call to action, and on a screen that works
without a provider it would read as a precondition. `AiReadyGateTest` sweeps all four CRM pages in
every state and both languages, so "AI ready" / "AI redo" still exists on exactly one code path
([ai-state.md §3](ai-state.md)).

## 9. Responsive and accessible

Tested at 375, 768 and 1280 px with no horizontal body scroll. The mechanism, which is what
`CrmScreenAccessibilityTest` can hold rather than a screenshot: the only wide elements are the list
table and the four-way tab row, and both scroll inside their own `overflow-x-auto` container; long
addresses and ids use `break-all`.

- Below 1024 px the four screens share **one** tab in the bottom bar (three tabs: Home, Briefs, CRM),
  because four more there would make every tab too narrow to hit; the CRM pages carry their own tab
  row for moving between the four. The sidebar from 1024 px lists all four under a group label.
- Every field is a `<x-crm.field>`: label tied to the input by id, `aria-describedby` to the hint and
  to the error, `aria-invalid` while the field is wrong, 44 px minimum height. The address list is a
  `<fieldset>` with a `<legend>`, and each remove button names the address it removes, because
  "Remove" alone is ambiguous once there are three of them.
- The keyboard path through a form works: tab order follows the visual order, the submit button is a
  real `submit` in a real `<form wire:submit>`, and nothing depends on a hover or a pointer.
- Every action is `.btn-primary`, `.btn-secondary` or carries `min-h-touch`; the focus ring is the
  token ring on `:focus-visible` from `app.css` and is never removed per element.
- State is never colour alone — every badge carries a word (the status, "Awaiting approval",
  "Deleted"), asserted for each badge on the page.
- `node packaging/fixtures/design/contrast-check.mjs` stays at 70/70: the screens use existing tokens
  and add no colour.

## 10. The test matrix

| Test | Proves | Profile |
|---|---|---|
| `tests/Feature/CRM/CustomersCrudTest.php` | create, read, update, the proposal/approval deletion, restore, search, pagination, the 404-for-both-cases property | `local` |
| `tests/Feature/CRM/ContactsCrudTest.php` | several addresses with one primary, collisions as field errors, a removed address restored onto its own row, multi-customer links | `local` |
| `tests/Feature/CRM/ProjectsCrudTest.php` | calendar dates survive as dates, the customer is required and scoped, filters | `local` |
| `tests/Feature/CRM/TasksCrudTest.php` | §4.2's "no invented deadline", the shared `Priority` vocabulary, optional links | `local` |
| `tests/Feature/CRM/CrmWorksWithoutAiTest.php` | §4.2, behaviourally in every AI state and structurally by reading the sources | `local` |
| `tests/Feature/CRM/CrmScreenAccessibilityTest.php` | pwa.md §6 and §1: landmarks, labels, 44 px, scroll containers, the CSP nonce, no CDN, no inline script | `local` |
| `tests/Integration/BackendContract/WorkspaceRepositoryContractTest.php` | ADR-002 D10's `repository` group: identical ids, the keyset page, the refusals, the boundary | **both** |
| `tests/Integration/BackendContract/CrmSearchContractTest.php` (S2-01) | the corpus this module searches through, on both profiles | **both** |
| `tests/Feature/Pwa/AiReadyGateTest.php` | "AI ready" on exactly one code path, now including the four CRM pages | `local` |
| `tests/Security/PolicySurfaceArchTest.php` (S2-06) | the four routes are in `PWA_ROUTE_TABLE` and every CRM action names an ability | `local` |
| `tests/Security/IdorTest.php`, `CrossAccountContractTest.php` (S2-06) | the per-row tenant refusals, schema-driven, unchanged | `local` / **both** |

`tests/Support/CrmRecords.php` builds the rows the feature tests use, with the full §3.1 lineage, so a
test row is shaped like a real one and a retention assertion means something. The frozen S0-18 pack
stays the oracle for the contract test, because that is where "identical ids on both profiles" has to
be measured.

## 11. What later items add

- **S2-10 (#94)** — the activity timeline screen. `WorkspaceRepository::timeline()` is already
  implemented here over `communications` (ADR-002 D9), newest first, anchored, with a project
  narrowing to its customer's timeline through a scoped subquery; the screen is S2-10's.
- **S2-08 (#92)** — the approvals engine and screen. `CrmDeletions` moves behind its API; the rows do
  not change. The shell's pending-approval badge starts counting when `PendingApprovals` does.
- **S2-04 (#87)** — encryption at rest. One call site to route through, named in §6.1.
- **S2-19** — admin surfaces. A workspace switcher for a session with several memberships belongs
  there; `CurrentWorkspace` takes the first membership by organization id today, which is
  deterministic on both profiles and correct for the one workspace v1 installs.
- **Import/export** — §3.2 asks for CSV/JSON. The JSON half exists as `App\CRM\Import\CrmPackMapper`
  (S2-01) and has no screen yet; the empty states already point at it ("import a workspace from a
  JSON pack").
- **Task assignees and contact phone numbers** are columns the schema has and these forms do not edit
  yet. Both are preserved on save rather than cleared.
