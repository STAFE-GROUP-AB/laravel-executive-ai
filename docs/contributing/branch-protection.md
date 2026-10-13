# Branch protection, required checks and review rules for `main`

> **Backlog:** S0-27 · **Blueprint:** §13 (DRI + reviewer from another team), §13.2 (protected
> `main`, required CI, two reviewers for security-critical modules, CODEOWNERS, no giant PRs,
> conventional commits) · **Related:** `CONTRIBUTING.md`, `CODEOWNERS`,
> `docs/operations/release-policy.md` §1.5 and §3, `.github/workflows/ci.yml`,
> `docs/OPEN-QUESTIONS.md` A14.
> **Status:** proposed 2026-10-10, **§1 applied 2026-10-13 as ruleset id 24986875** (§0) with two
> deviations recorded in the §1.3 table. Applying it is a repository-admin action (§2). The
> configuration files are canonical: `docs/contributing/rulesets/main-now.json` (applied) and
> `docs/contributing/rulesets/main-target.json` (target state, once §5's decision is taken).

## 0. Current state, honestly

**Applied 2026-10-13.** The `main` ruleset of §1 is live as **id 24986875**, created by the owner
from `rulesets/main-now.json` (the one step no agent may perform — a settings API). What changed
against the 2026-10-10 reading below:

- Direct pushes to `main` are over. Every change goes through a pull request, squash-merged, linear
  history, no force-push and no deletion — the coordinator's own `docs:` and `ci:` commits included.
- Required checks are `php`, `swift`, `secrets-scan`, `fixtures`, `commitlint` and **`parity`**.
  `parity` is the §16 step 9 gate that had to be in place before any Sprint 3 workstream issue
  opens (`docs/operations/vertical-slice.md` §5, OPEN-QUESTIONS A14). `build-macos` is deliberately
  **not** required: it is path-filtered, so requiring it would block every pull request that does
  not touch `apps/macos/**`. `sprint-2-exit` joins the list when S2-20 lands.
- One parameter differs from the §1 proposal: `strict_required_status_checks_policy` is **false**.
  With four to six agents merging in parallel, "branch must be up to date" would force every open
  pull request to rebase and re-run a fifteen-minute macOS lane after each merge. The checks still
  have to pass on the pull request head, and squash-only linear history keeps `main` bisectable.
- GitHub added `require_extra_approval_for_unattributed_changes: true` by default. It does **not**
  bite here: agent commits are authored as `Claude <noreply@anthropic.com>`, which GitHub resolves
  to the user `claude`, so they are attributed. If that ever changes, the symptom is a merge blocked
  for an approval nobody can give, and the fix is one `PUT` with that parameter set to `false`.
- The enterprise ruleset "Standard" (id 5666733) still carries no rules and was left alone.

The 2026-10-10 reading that the plan below was written against:

- `main` had **no branch protection and no repository ruleset**. The only ruleset that reached
  the repository was the enterprise-level ruleset "Standard" (id 5666733), which has **no rules**
  and therefore no effect.
- The repository has **one collaborator**, `@andreaskviby` (admin). The organisation
  `STAFE-GROUP-AB` has no `security-owners` team.
- Every commit and every PR in this repository — agent-authored or not — is pushed under
  Andreas's own `gh` identity, because the coordinating agent (Claude) operates under Andreas's
  written blanket authorization for this repository. Consequences:
  - GitHub refuses a formal *Approve* review ("can not approve your own pull request"), so agent
    PRs are reviewed as **review comments** (PR #52, #53, #54: one `COMMENTED` review each) and
    then merged by the same identity.
  - The coordinator also pushed small `docs:` / `ci:` commits **directly to `main`** (for
    example `20707c8 ci: allowlist ULID identifiers in the secrets scan`). The ruleset ended that
    on 2026-10-13; the last such commit was `0de85ae`.
- Merged PRs were squash-merged by hand; nothing enforced it. The ruleset now does
  (`allowed_merge_methods: ["squash"]`).

Turning on *"require pull request reviews"* in this situation would block **every** merge, because
there is no second GitHub account that could approve. That trade-off drives the two-phase plan
below: a ruleset that can be applied today without blocking the work (§1), and the target ruleset
that needs one decision from Andreas first (§5).

## 1. The ruleset for `main` — apply now

Use a **repository ruleset**, not classic branch protection: rulesets export and import as JSON,
show up in the audit log and in *Rule insights*, layer with the enterprise ruleset, and are the
only place where "allowed merge methods" can be enforced.

### 1.1 One-command apply (Andreas, repository admin)

```sh
gh api --method POST repos/STAFE-GROUP-AB/laravel-executive-ai/rulesets \
  --input docs/contributing/rulesets/main-now.json
```

Re-applying later (after editing the JSON or switching to the target file) is a `PUT` on the
ruleset id that the `POST` returned:

```sh
gh api repos/STAFE-GROUP-AB/laravel-executive-ai/rulesets --jq '.[] | select(.name=="main") | .id'
gh api --method PUT repos/STAFE-GROUP-AB/laravel-executive-ai/rulesets/<id> \
  --input docs/contributing/rulesets/main-target.json
```

### 1.2 UI click-path (equivalent)

*Settings → Rules → Rulesets → New ruleset ▾ → Import a ruleset* → choose
`docs/contributing/rulesets/main-now.json` → review → *Create*. GitHub's import accepts exactly
the file in this repository. To do it by hand instead: *New branch ruleset*, name `main`,
enforcement *Active*, target *Default branch*, then tick the settings in the table below.

### 1.3 What the ruleset contains and why

| Setting | Value (now) | Why |
|---|---|---|
| Target | the default branch (`~DEFAULT_BRANCH`) | follows a rename; release branches `release/X.Y.x` get the same ruleset later by adding `refs/heads/release/**` (release policy §3.1) |
| Restrict deletions | on | `main` is never deleted |
| Block force pushes (`non_fast_forward`) | on | history on `main` is append-only; tags are never moved (release policy §3.4) |
| Require linear history | on | squash merges only (§3); no merge commits on `main`, so every commit on `main` is a PR title that passed `commitlint` |
| Require a pull request before merging | on | ends direct pushes to `main` — **including the coordinator's docs/CI commits**, which become small PRs from now on |
| — required approving reviews | **0** (now) → 1 (target) | 0 is the only value that does not block all merges while one GitHub account does all the work; see §5 |
| — dismiss stale approvals on push | on | an approval covers the commit that was reviewed, not later pushes |
| — require Code Owner review | **off** (now) → on (target) | needs a second account to be able to approve; see §5 |
| — require approval of the most recent push | off | with two accounts and one human it deadlocks when Andreas pushes a fix to a coordinator PR; revisit when a third reviewer exists |
| — require conversation resolution | on | review comments are the review today (§6); they must be resolved, not merged over |
| — allowed merge methods | `squash` only | the PR title is the commit header (§3) |
| Require status checks to pass | `php`, `swift`, `secrets-scan`, `fixtures`, `commitlint`, **`parity`** — source GitHub Actions (`integration_id` 15368) | the `ci.yml` job names are declared stable in `ci.yml`'s header comment; `commitlint` is `.github/workflows/commitlint.yml` (§4). Names must match the `jobs.<id>.name` values exactly. `parity` was added when it was applied (2026-10-13): it is the §16 step 9 gate that must hold before any Sprint 3 workstream issue opens. `build-macos` is deliberately **not** required — it is path-filtered, so requiring it would block every PR that does not touch `apps/macos/**`. `sprint-2-exit` joins the list when S2-20 lands |
| — require branches to be up to date (`strict`) | **off** (changed on apply, 2026-10-13) | proposed as `on`, applied as `off`. The reason is the cost line this row already predicted: with four to six agents merging in parallel, every open PR would need *Update branch* and a fifteen-minute macOS re-run after each merge, so the queue would spend more time re-running than reviewing. The checks still have to pass on the PR head, and squash-only linear history keeps `main` bisectable. A **merge queue** is the upgrade path if a PR ever merges green against a `main` that broke it |
| — enforce on branch creation | yes (`do_not_enforce_on_create: false`) | no loophole through re-creating the branch |
| Require signed commits | **off** | deliberately: agent commits are unsigned and there is no key custody for commit signing. The integrity guarantee the release needs comes from the **signed tag and signed manifests**, which the release policy (§3.3, §3.5) already requires and which CI verifies. Revisit when every committer has a hardware-backed signing key |
| Require deployments / workflows / code scanning | off | nothing to require yet; `sca.yml` is scheduled, not a PR gate |
| Bypass list | repository role *Admin*, mode **pull request only** | §2.2 |

Not in the ruleset on purpose:

- **`commit_message_pattern` on `main`.** GitHub's squash commit message is *title + body*, and
  the default body lists the squashed commits, so a pattern that is strict enough to be useful
  rejects legitimate squashes. The header rule is enforced by the `commitlint` check instead (§4),
  which is the only thing that can land on `main` under squash-only.
- **`sca.yml`.** It runs weekly and on dispatch (supply-chain audit, SBOM); a scheduled workflow
  cannot be a required PR check.

## 2. Who holds admin rights, and who may change these settings

- **Andreas Kviby (`@andreaskviby`)** is the owner of the GitHub organisation `STAFE-GROUP-AB`
  and the only collaborator (admin) on this repository. He is the program owner / Staff Architect
  of blueprint §13 and the DRI of last resort for every workstream (OPEN-QUESTIONS A14).
- **Only Andreas may create, edit, disable or delete the `main` ruleset**, change the bypass list,
  or edit `CODEOWNERS`. The coordinating agent is explicitly **not** authorised to call any
  settings-changing GitHub API (rulesets, branch protection, collaborators, teams, secrets), even
  though it operates under Andreas's token. Reading those settings is fine.
- Any change to the ruleset is announced in a `docs(release)` PR that updates this document and
  the JSON files **before** the setting is changed, so the repository always describes the
  protection it actually has. The GitHub audit log (`repo.create_ruleset`, `repo.update_ruleset`)
  is the evidence that the two match.

### 2.1 Enterprise ruleset

The enterprise ruleset "Standard" is managed at the enterprise level
(`github.com/enterprises/stafe-group-ab/settings/policies/repositories/5666733`), applies to
every repository, and currently contains no rules. It cannot be changed from this repository and
is not part of this policy. If rules are ever added there, this document must note which of them
duplicate or exceed the repository ruleset.

### 2.2 Bypass rules

The bypass list contains the repository role *Admin* only, in mode *pull request only*. That
means: nobody can push to `main` directly, and an admin can merge a PR that does not meet the
rules only through the explicit *bypass* control on the PR page, which GitHub records.

Bypass is reserved for **unbreaking the gates themselves** — a `ci.yml` or `commitlint.yml`
change that cannot pass because the broken check is the thing being fixed, or a red `main`
(release policy §3.1: a red `main` is an incident). Rules:

1. **Never** for a PR that touches a security-critical path (§5) — not even by Andreas.
2. Never to skip a failing `secrets-scan`.
3. The PR description states `Bypass: <reason>` so the audit trail is readable without the
   GitHub audit log.
4. A bypass to merge a change into a required check is followed by a normal PR that proves the
   check is green again.

## 3. Merge method policy

- **Squash merge only.** Rebase-merge and merge commits are disabled by the ruleset
  (`allowed_merge_methods: ["squash"]`) and should also be unticked under *Settings → General →
  Pull Requests* so the UI offers only one button.
- **The PR title is the commit header** that lands on `main`. It must match release policy §1.5
  (type, optional scope from the twelve project areas, `!` for breaking changes, lowercase
  subject ≤ 60 characters, whole header ≤ 72, no trailing period). `commitlint` (§4) fails the PR
  otherwise. Backlog ids such as `(S0-27)` are allowed in the subject; GitHub issue numbers go in
  the body (`Closes #27`).
- **The PR body's first paragraph is the commit body**; the issue footer (`Closes #<n>`), the ADR
  footer and `Security-Impact:` come from the PR template. When squashing in the UI, replace
  GitHub's auto-generated bullet list of commits with the PR body.
- **Delete the branch on merge** (*Settings → General → Pull Requests → Automatically delete head
  branches*). Work branches are short-lived (release policy §3.1).
- **No giant PRs** (§13.2). A PR that touches more than one workstream, or more than one
  security-critical module, is split before review. Reviewers may close a PR for size alone.
- **Hotfix flow** (release policy §3.1): fixed forward on `main` through a normal PR, then
  `git cherry-pick -x` to `release/X.Y.x` through a second PR. Release branches get the same
  ruleset (add `refs/heads/release/**` to the ruleset's include list when the first one exists).

## 4. The `commitlint` required check

`.github/workflows/commitlint.yml`, job and check name **`commitlint`**, runs on
`pull_request` (`opened`, `edited`, `synchronize`, `reopened`, `ready_for_review`) — `edited`
matters because fixing a title is a PR edit, not a push.

- **Fails** when the PR title does not match the release policy §1.5 header regex, reproduced
  here verbatim (the workflow carries a copy; the policy is the source):

  ```regex
  ^(feat|fix|docs|test|refactor|perf|build|ci|chore|security)(\((mac|laravel|pwa|ai|crm|integrations|mcp|security|compliance|release|adr|docs)\))?(!)?: (?=[^\r\n]{1,60}$)[a-z0-9][^\r\n]*[^.\s]$
  ```

  plus the ≤ 72 character total header length that the regex alone cannot express.
- **Warns** (does not fail) on commit headers on the branch that violate the rule, on merge
  commits and on `fixup!`/`squash!` commits. Squash-only merging discards those headers; making
  them blocking would stall agent branches on work-in-progress commits without improving what
  lands on `main`. Release policy §1.5 is updated to say so.
- Implemented as a plain `bash` step with `grep -P` (GNU grep on `ubuntu-latest`); no
  third-party action, no token, `permissions: contents: read`. The title is passed through an
  environment variable, never interpolated into the script.
- Dependabot titles conform by construction (`build: bump …`, `ci: bump …` via
  `.github/dependabot.yml`), as do release PRs (`chore(release): prepare vX.Y.Z`).

Because the PR title is linted, the squashed commit on `main` is linted. That is what makes
"require linear history + squash only" sufficient to keep `main`'s history conventional-commit
clean without a `commit_message_pattern` rule.

## 5. Two reviewers for security-critical paths (CODEOWNERS)

### 5.1 The paths

`CODEOWNERS` keeps the default `* @andreaskviby` and routes the security-critical paths to the
security owner explicitly, so that the intent survives when the default owner changes:

| Path | Why it is security-critical |
|---|---|
| `/app/Security/` | authorization, policy engine, secret store client (CONTRIBUTING.md) |
| `/app/Approvals/` | approval flow for mutating tools (default-deny, §14 gates) |
| `/app/MCP/` | MCP manager, trusted runtime, Peekaboo (ADR-005) |
| `/app/Integrations/` | OAuth, mailbox access, multi-account isolation |
| `/apps/macos/` | installer, updater, IPC, SMAppService (ADR-001, ADR-003) |
| `/packaging/signing/` | release-signing public keys and `allowed_signers` (release policy §3.5) |
| `/packaging/manifest/` | runtime manifest with pinned versions and checksums (§12.1, §16.6) |
| `/.github/workflows/` | the gates themselves |
| `/.gitleaks.toml` | the secrets-scan allowlist — loosening it is a security change |
| `/docs/adrs/` | architecture decisions bind everything above |
| `/CODEOWNERS` | this routing |

The owner on every line is `@andreaskviby` because he is the only human. The intended owner is a
GitHub team **`@STAFE-GROUP-AB/security-owners`** (blueprint §13 workstream H). When that team
exists — it needs at least one member besides Andreas to be meaningful — the security-critical
lines switch to the team and the default line stays personal. The comment block in `CODEOWNERS`
says the same.

### 5.2 The single-account problem, and the two options

Blueprint §13.2 wants two reviewers on these paths and §13 wants the second reviewer to come from
another team. GitHub can enforce that only with *required approving reviews ≥ 1* plus *require
Code Owner review*, and both need an approver that is **not the PR's author identity**. Today
there is one identity, so neither can be switched on without stopping all merges.

**Option (i) — a second GitHub identity for the coordinator (recommended).** Create a machine
user (for example `stafe-coordinator`), add it to the organisation with *Write* on this
repository, and give the coordinating agent **that** token instead of Andreas's. Then:

- agent PRs are authored by `stafe-coordinator`; Andreas's *Approve* is a real approval and, as
  Code Owner, the mandatory one on security-critical paths;
- the coordinator's second-team review (A14) becomes a real *Approve* from a different account;
- `main-target.json` can be applied: required reviews 1 + Code Owner review required. On a
  security-critical path that yields exactly the two reviewers §13.2 asks for (coordinator +
  Andreas as Code Owner); on every other path it yields Andreas's approval, because `*` routes
  to him. **Consequence Andreas must accept:** every PR then waits for his click. If that is too
  slow for the agent workflow, the alternative is to drop *require Code Owner review* and keep
  *required reviews 1*, which lets the coordinator account approve non-security PRs while the
  security-critical paths still get Andreas's review by convention and `CODEOWNERS` auto-request.
  That is the one decision §7 asks for.
- A GitHub App is **not** a substitute: an App can submit approving reviews, but Apps cannot be
  listed in `CODEOWNERS`, so it cannot satisfy *require Code Owner review* and the two-reviewer
  rule would again be procedural. A second user account is simpler and auditable.
- Cost: one more account under Andreas's control, with its own token in the coordinator's
  environment and its own 2FA. The account is a *member*, never an admin; it is not on the bypass
  list.

**Option (ii) — enforce two reviewers procedurally until (i) exists.** Apply `main-now.json`
(required reviews 0) and follow §6: the coordinator posts a second-team review *comment* on every
PR; a PR that touches a security-critical path is not merged until Andreas has posted **`LGTM`**
as a review comment. `CODEOWNERS` still auto-requests his review, and *require conversation
resolution* means his comments cannot be merged over. This is what the project does today; it is
honest but it is not enforced by GitHub, and it is what §13.2 asks us to replace.

**Recommendation:** apply `main-now.json` today (it already stops direct pushes, force pushes,
unchecked merges and non-squash merges), create the second account this sprint, switch the
coordinator to it, then apply `main-target.json`. Until then option (ii) is the rule.

## 6. How agent-authored PRs are reviewed today

Consistent with OPEN-QUESTIONS A14 (proposed, pending Andreas's confirmation):

1. The authoring agent opens a small PR against `main` from a `<type>/<issue>-<topic>` branch,
   using the PR template (issue, ADR, tests, security impact).
2. The **coordinator reviews as the "second team"** (§13): reads the full diff, checks it against
   the blueprint and the ADRs, and posts a **review comment** (GitHub will not accept an
   *Approve* from the same identity). Findings are posted as review threads; the author fixes
   them; threads are resolved by the reviewer, not the author.
3. **Andreas is the DRI.** For a PR that touches a security-critical path (§5.1) he reviews and
   posts **`LGTM`** as a review comment; without it the PR is not merged. For other PRs his
   review is requested through `CODEOWNERS` but the coordinator may merge after its own review.
4. The coordinator squash-merges with the PR title as header and the PR body as body, and the
   branch is deleted.
5. Direct commits to `main` stop once `main-now.json` is applied; until then they are limited to
   `docs:`/`ci:` fixes that CI itself cannot gate, and they are listed in the next PR's body.

Once option (i) exists, steps 2 and 3 become real *Approve* reviews and the ruleset enforces them.

## 7. Decisions needed from Andreas

| # | Decision | Default if silent |
|---|---|---|
| 1 | Apply `main-now.json` (§1.1) | — (nothing is protected until he does) |
| 2 | Create the second account (`stafe-coordinator` or similar) for the coordinator — option (i) | option (ii) stays in force |
| 3 | After (2): apply `main-target.json` with *require Code Owner review* **on** (his approval on every PR) or **off** (his approval on security-critical paths by `CODEOWNERS` request + convention) | on |
| 4 | Create the `@STAFE-GROUP-AB/security-owners` team when a second human maintainer exists | `CODEOWNERS` stays personal |
| 5 | Confirm A14: coordinator = second-team reviewer, Andreas = DRI with `LGTM` on security-critical PRs | treated as confirmed by applying (1) |

## 8. Acceptance criteria (S0-27)

- [x] Required status checks are named exactly as the `ci.yml` jobs: `php`, `swift`,
      `secrets-scan`, `fixtures` (plus `commitlint` from this change).
- [x] `CODEOWNERS` routes `app/Security/`, `app/Approvals/`, `app/MCP/`, `app/Integrations/`
      and `packaging/signing/` (and the other paths in §5.1) to the security owner.
- [x] Linear history + squash-only is stated and consistent with the conventional-commit rule
      (§3, §4).
- [x] Admin rights and who may change the settings are named (§2).
- [ ] Ruleset applied by Andreas (§1.1) — tracked in `docs/OPEN-QUESTIONS.md` A14.
