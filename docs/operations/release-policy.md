# Release, versioning and changelog policy

> **Blueprint refs:** §9 (updates and rollback), §12.1 (versioned immutable runtime), §12.2
> (install gates), §13.2 (conventional commits, SemVer, changelog, signed tag, protected `main`),
> §14 (release checklist), §17 (Definition of Done).
> **Owner:** J (Docs/community) · **Enforced by:** I (QA/release) through CI (S0-05, S0-27).
> **Status:** Binding from Sprint 0. Sprint 1's signed debug builds (S1-12) use this scheme; they
> do not invent their own.
> **Backlog item:** S0-28 (#28).

This document fixes four things so that nobody has to decide them under release pressure: how a
commit is written, how a version number is formed and what it pins, how a release is branched,
tagged and published, and how the changelog is kept. It ends with the §14 release checklist as an
actionable list and the update-channel rules from §9.

---

## 1. Commit convention

We use [Conventional Commits 1.0](https://www.conventionalcommits.org/en/v1.0.0/). Commit messages
are in English (`CONTRIBUTING.md`). The header is the contract; CI lints it.

### 1.1 Header

```text
<type>(<scope>)!: <subject>
```

- **`type`** — exactly one of:

  | Type | Use for | Changelog section |
  |---|---|---|
  | `feat` | user-visible capability added | Added |
  | `fix` | user-visible defect corrected | Fixed |
  | `security` | vulnerability fix, hardening, policy tightening, key rotation | Security |
  | `perf` | measurable performance change with no behaviour change | Changed |
  | `refactor` | code change that is neither a feature nor a fix | not listed unless `!` |
  | `docs` | documentation only (including ADRs) | not listed |
  | `test` | tests and fixtures only | not listed |
  | `build` | build system, packaging, signing, notarization, dependencies | not listed unless user-visible |
  | `ci` | `.github/workflows`, CI configuration | not listed |
  | `chore` | anything else that touches no product code (release prep, repo hygiene) | not listed |

  `revert` is **not** an allowed type. A revert reuses the type of the commit it undoes, starts the
  subject with `revert`, and carries a `Reverts: <sha>` footer. Reverting commits is a last resort
  (see §3.4).

- **`scope`** — optional, exactly one of the twelve project areas. They mirror the twelve GitHub
  labels (scope `mac` ↔ label `macos`; the rest are identical):

  `mac` `laravel` `pwa` `ai` `crm` `integrations` `mcp` `security` `compliance` `release` `adr` `docs`

  A change that genuinely spans several areas either omits the scope or is split into several
  commits. Do not invent scopes; add one here first (that is a `docs(release)` PR).

- **`!`** — present if and only if the commit contains a breaking change (§1.3).

- **`subject`** — imperative mood, lowercase first character, no trailing period, no issue number
  (issues go in the footer or the PR). Header ≤ 72 characters in total.

### 1.2 Body and footers

- The body is optional, separated from the header by one blank line, wrapped at 72 characters,
  and explains **why**, not what.
- Footers follow the body after one blank line and use `Token: value` (git trailer format). Tokens
  we use:

  | Footer | Meaning |
  |---|---|
  | `BREAKING CHANGE: <description>` | mandatory when `!` is present; describes the break and the migration |
  | `Closes #<n>` / `Refs #<n>` | links the issue; every PR closes or references one (`CONTRIBUTING.md`) |
  | `ADR: ADR-<nnn>` | the ADR that authorises an architectural change |
  | `Security-Impact: none \| <text>` | optional in commits; **required** in the PR description |
  | `Reverts: <sha>` | on revert commits |
  | `Co-Authored-By: Name <email>` | pair work, AI assistance |

### 1.3 Breaking changes

A change is breaking when a user, an installed system or a documented consumer can observe the
break without reading the source. For this product that means any of:

- the user-data directory layout or a database migration that cannot be run against the previous
  release's data without a manual step;
- the Swift↔Laravel IPC contract version (S0-15), the health contract (S0-14) or the Executive Brief
  JSON schema (S0-13) changing incompatibly;
- the MCP tools this product exposes (`crm.search`, `tasks.list`, `brief.read`, …) changing name,
  input schema or semantics;
- removing or renaming a setting, integration, provider adapter or CLI/`artisan` entry point;
- raising `min_macos` or dropping a hardware profile from the support matrix (S0-22).

Breaking commits carry **both** the `!` in the header **and** a `BREAKING CHANGE:` footer. One
without the other fails lint. The footer text is copied into the changelog verbatim.

### 1.4 Examples

```text
feat(crm): add explainable customer matching for inbound mail

Domains are never assumed to map to one customer (§5.6). The matcher
returns candidates with a reason string and the UI requires a human
confirmation before linking.

Closes #142
```

```text
fix(mac): keep MenuBarExtra open when the brief cache is empty

Closes #231
Security-Impact: none
```

```text
security(integrations): bind OAuth state to the session and account id

Prevents a cross-account token swap when two Google accounts are
connected in the same browser session.

Closes #298
```

```text
feat(laravel)!: store brief items as a JSON column with schema_version

BREAKING CHANGE: briefs written before 0.3.0 are migrated in place; the
migration is irreversible and the updater takes a database backup first
(docs/operations/release-policy.md §6).
ADR: ADR-002
Closes #310
```

```text
docs(adr): accept ADR-003 with Keychain measurement evidence

Refs #8
```

```text
chore(release): prepare v0.2.0
```

### 1.5 What CI enforces

The `commitlint` job (`.github/workflows/commitlint.yml`; required check per S0-27) checks **the
pull request title** and **every commit header in the pull request**. The PR title must be a
valid header because `main` is squash-merged only (S0-27, `docs/contributing/branch-protection.md`
§3), which turns the title into the commit that lands on `main`; a bad title **fails** the job.
Commit headers on the branch are reported as **warnings** and name the offending commit — squash
discards them, so they do not gate the merge.

Header regex (PCRE, one line, applied to the first line of the message):

```regex
^(feat|fix|docs|test|refactor|perf|build|ci|chore|security)(\((mac|laravel|pwa|ai|crm|integrations|mcp|security|compliance|release|adr|docs)\))?(!)?: (?=[^\r\n]{1,60}$)[a-z0-9][^\r\n]*[^.\s]$
```

Additional checks that a regex cannot express cleanly:

| Rule | Check |
|---|---|
| Header length | first line ≤ 72 characters (the regex's `{1,60}` bounds the subject; the type and scope take the rest) |
| Blank line after header | line 2, if present, is empty |
| Body wrap | body lines ≤ 100 characters (URLs and `Co-Authored-By` exempt) |
| Breaking consistency | `!` present ⇔ a `BREAKING CHANGE:` footer present |
| Footer format | lines after the last blank line match `^[A-Za-z-]+: .+$` or `^BREAKING CHANGE: .+$` |
| Allowed footer tokens | `BREAKING CHANGE`, `Closes`, `Refs`, `ADR`, `Security-Impact`, `Reverts`, `Co-Authored-By`, `Signed-off-by` |
| Merge commits | `Merge …` headers are rejected on PR branches; rebase instead |
| Fixup/squash markers | `fixup!`/`squash!` are rejected; autosquash before opening for review |

Dependabot and release-bot commits must conform too. Dependabot's `commit-message.prefix` is set to
`build` with no scope (`deps` is deliberately not a scope — the twelve areas are product areas, and
a dependency bump is not one), giving headers like `build: bump laravel/framework to 13.1.4`;
release-bot commits use `chore(release)`.

---

## 2. Versioning

### 2.1 The product version

The **product release** is the signed, notarized DMG. It carries one version, formed per
[SemVer 2.0.0](https://semver.org/spec/v2.0.0.html): `MAJOR.MINOR.PATCH[-PRERELEASE]`.

- Tags are `v<product_version>` (`v0.1.0`, `v0.2.0-rc.1`, `v1.0.0`).
- Pre-release identifiers, in order: `alpha.N` → `beta.N` → `rc.N`. A pre-release never ships on the
  stable update channel (§6).
- SemVer build metadata (`+…`) is **not** used in tags; the build number lives in `release.json`
  and in `CFBundleVersion`.
- The product version is the **only** version shown to users (About panel, admin, `/health/ready`,
  release notes).

**What "public API" means for SemVer here** is the list in §1.3. Anything on that list changing
incompatibly is a MAJOR bump after 1.0 and a MINOR bump before 1.0.

### 2.2 Pre-1.0 semantics

While `MAJOR = 0`:

| Bump | Allowed to contain |
|---|---|
| `0.y.0` (minor) | features, fixes, **and breaking changes** — each breaking change is listed under *Changed* in the changelog with a leading **BREAKING** marker and its migration note |
| `0.y.z` (patch) | fixes and security fixes only; never a breaking change, never a schema migration that is not reversible |

Version `1.0.0` is cut when the §17 Definition of Done is met in full — not earlier, not for
marketing. From 1.0 on, standard SemVer applies.

### 2.3 The three moving parts

A product release is assembled from three independently built components. Each has its own
version string for the people who build it; the product version pins all three.

| Component | Version field | Rule |
|---|---|---|
| **Mac app** (`apps/macos/`) | `CFBundleShortVersionString` | **Must equal** the product version's `MAJOR.MINOR.PATCH`. Apple accepts only a numeric triple here, so the pre-release suffix is dropped; the suffix is carried by the tag and `release.json`. Set via `MARKETING_VERSION` in the xcconfig, never edited by hand in `Info.plist`. |
| | `CFBundleVersion` | A **monotonically increasing integer** across every build that is signed or notarized, including pre-releases and debug builds from CI. Equals `release.json.build`. Never reused, never decreased, never reset at a new product version. Set via `CURRENT_PROJECT_VERSION`. |
| **Laravel core** (repo root) | `composer.json` `version` and `config('app.version')` | The two must be identical (CI asserts it). `config('app.version')` is a literal in `config/app.php`, not an `env()` read, so a shipped bundle cannot be re-labelled by editing `.env`. The core version **equals** the product version in every release in which core files changed. It may **lag** (a Mac-app-only hotfix `0.3.1` may pin core `0.3.0`); it may never be ahead. |
| **Bundled runtime** (ADR-001, S1-06) | `runtime.version` in the ADR-001 manifest (`packaging/manifest/runtime.json`) | An exact, immutable identifier of the PHP build plus the embedded HTTP server, of the form `<php semver>-r<N>` (e.g. `8.4.12-r3`; `rN` increments when the same PHP is rebuilt with different flags, patches or server). Changes rarely and independently of the product version. §12.1 requires the installed runtime to be immutable and versioned so that **two runtime versions can coexist** on disk during an upgrade; that is what makes binary rollback (§6) possible. The exact field names inside `runtime.json` belong to ADR-001; this policy only requires that `release.json` copies `runtime.version`, `runtime.php` and `runtime.http_server` from it verbatim. |

The relationship, stated once: **one product version = one tag = one `release.json` that pins exact
versions and hashes of all three components.** Two releases may share a core version or a runtime
version; no two releases share a product version or a build number.

### 2.4 `packaging/manifest/release.json`

Generated by CI in the release workflow — never hand-written — from the tag, the committed version
fields and the built artefacts. CI **fails the release** if the tag, `CFBundleShortVersionString`,
`composer.json` `version` and `config('app.version')` disagree with each other or with this policy.

```json
{
  "manifest_version": 1,
  "product_version": "0.1.0",
  "build": 42,
  "channel": "stable",
  "git": {
    "tag": "v0.1.0",
    "commit": "0123456789abcdef0123456789abcdef01234567"
  },
  "mac_app": {
    "bundle_id": "se.stafegroup.laravel-executive-ai",
    "short_version": "0.1.0",
    "bundle_version": "42",
    "team_id": "TEAMID1234",
    "notarized": true
  },
  "core": {
    "version": "0.1.0",
    "laravel": "13.1.4",
    "sha256": "…"
  },
  "runtime": {
    "version": "8.4.12-r1",
    "php": "8.4.12",
    "http_server": { "name": "frankenphp", "version": "1.5.0" },
    "sha256": "…"
  },
  "schema_version": {
    "brief": "1.0.0",
    "ipc": 1,
    "database": "2026_10_10_000000"
  },
  "min_macos": "15.0",
  "created_at": "2026-10-10T12:00:00Z",
  "artefacts": [
    { "name": "LaravelExecutiveAI-0.1.0.dmg", "size_bytes": 0, "sha256": "…" },
    { "name": "SHA256SUMS", "size_bytes": 0, "sha256": "…" },
    { "name": "sbom.spdx.json", "size_bytes": 0, "sha256": "…" }
  ],
  "signature": {
    "algorithm": "ssh-ed25519",
    "key_id": "SHA256:…",
    "detached_file": "release.json.sig",
    "covers": "release.json"
  }
}
```

Field rules:

| Field | Rule |
|---|---|
| `manifest_version` | integer; bumped when this shape changes incompatibly. Readers reject unknown majors. |
| `product_version` | SemVer string, equals the tag without the `v`. |
| `build` | integer, equals `mac_app.bundle_version`, strictly increasing across all releases and pre-releases. |
| `channel` | `stable` in v1. `beta` is reserved (§6); the field exists so the updater never has to guess. |
| `git.commit` | full 40-hex SHA the artefacts were built from; the tag points at it. |
| `mac_app.*` | copied from the built `Info.plist`, not from source. `notarized` is `false` only on debug builds, which never reach a GitHub Release. |
| `core.*` | `version` from `config('app.version')`; `laravel` from `composer.lock`; `sha256` of the core bundle as installed. |
| `runtime.*` | copied verbatim from the ADR-001 manifest; `http_server.name` is whatever ADR-001 decides (the value above is illustrative, not a decision). |
| `schema_version.brief` | the frozen Executive Brief schema version (S0-13). `ipc` is the IPC contract version (S0-15). `database` is the latest migration name shipped, so the updater can tell a downgrade from an upgrade without opening the database. |
| `min_macos` | the support-matrix floor (S0-22); raising it is breaking (§1.3). |
| `created_at` | RFC 3339, UTC, set by CI. |
| `artefacts[]` | every file attached to the GitHub Release except `release.json` itself and the `.sig` files. `SHA256SUMS` lists the same hashes in `sha256sum -c` format. |
| `signature` | describes the detached signature of `release.json` (the manifest cannot contain its own signature). Verifiers read `release.json.sig`, check it against the public key in `packaging/signing/release-keys/`, and only then trust any other field. |

A JSON Schema for this file (`packaging/manifest/release.schema.json`) is part of S0-05's CI
validation once the release workflow exists; until then this document is the schema.

### 2.5 Where the numbers are bumped

The release PR (`chore(release): prepare vX.Y.Z`, §3.3) changes exactly four things:
`MARKETING_VERSION`/`CURRENT_PROJECT_VERSION` in the Mac app xcconfig, `composer.json` `version`,
`config/app.php` `version`, and `CHANGELOG.md`. Nothing else bumps a version. Feature PRs never
touch version fields.

---

## 3. Branching, tagging and publishing

### 3.1 Branches

- **`main`** is protected (S0-27): required CI, required reviews, CODEOWNERS, no force push, no
  direct commits. `main` is always releasable; a red `main` is an incident, not a backlog item.
- **Work branches** are named `<type>/<issue-id>-<kebab-topic>` after the commit type that
  dominates the change, e.g. `docs/s0-28-release-policy`, `feat/s1-01-health-endpoints`,
  `fix/231-menubar-empty-cache`. They are short-lived and deleted on merge.
- **Release branches** exist **only for hotfixes** to a shipped version while `main` has moved on
  with changes that cannot ship yet. They are named `release/<MAJOR>.<MINOR>.x` (e.g.
  `release/0.3.x`), are created from the shipped tag, and are protected like `main`. A hotfix is
  **fixed forward first**: the commit lands on `main` through a normal PR, then is cherry-picked
  (`git cherry-pick -x`) to the release branch through a second PR. Patch tags for that line are cut
  from the release branch. Release branches are deleted when the line is no longer supported.
- There is no `develop`, no long-lived feature branch, and no release branch created "just in
  case".

### 3.2 Merge policy

The merge method is **squash only with linear history** (S0-27,
`docs/contributing/branch-protection.md` §3), which keeps `main` consistent with §1: whatever lands
on `main` must itself pass the header regex. With squash merges that means the **PR title is the
commit header** and the PR body's first paragraph is the body — write both as if they were the
commit. Breaking PRs put `!` in the title and `BREAKING CHANGE:` in the body.

### 3.3 Release procedure

1. **Freeze.** The release manager announces the cut; only `fix`, `security`, `docs` and
   `chore(release)` commits land until the tag exists.
2. **Release PR** `chore(release): prepare vX.Y.Z` (branch `chore/release-vX.Y.Z`): version bumps
   (§2.5) and the curated changelog section (§4). Reviewed like any PR; J reviews the changelog.
3. **CI release workflow** on the merge commit: build the Mac app and core, sign, notarize and
   staple (A/I), run the full test matrix, generate the SBOM and license audit (S0-05 `sca.yml`),
   generate `release.json` and `SHA256SUMS`, and upload everything as workflow artefacts.
   The workflow fails on any version mismatch (§2.4).
4. **Clean-machine test** (I): the DMG from step 3 is installed on a Mac that has never seen the
   source, with Gatekeeper enabled, per the install-measurement protocol (§12.2). Results go into
   the test matrix publication.
5. **Migration and rollback drill** (B/A): upgrade a copy of the previous release's user data,
   then roll back per §6. Both must succeed before tagging.
6. **Sign the tag** (release manager, locally — never in CI):

   ```sh
   git fetch origin main
   git tag -s vX.Y.Z <merge-commit-sha> -m "vX.Y.Z"
   git tag -v vX.Y.Z
   git push origin vX.Y.Z
   ```

   The tag points at the exact commit CI built in step 3. An unsigned tag or a tag whose signature
   does not verify against a key in `packaging/signing/release-keys/` is rejected by the publish
   workflow and deleted.
7. **Sign the manifests** (release manager, same key): `release.json.sig` and `SHA256SUMS.sig` as
   detached signatures over the exact bytes CI produced.
8. **GitHub Release** for the tag, titled `vX.Y.Z`, body = the changelog section for this version
   verbatim, marked *pre-release* when the tag has a pre-release suffix. Assets, all mandatory:

   | Asset | Produced by |
   |---|---|
   | `LaravelExecutiveAI-X.Y.Z.dmg` | CI (signed, notarized, stapled) |
   | `release.json` | CI |
   | `release.json.sig` | release manager |
   | `SHA256SUMS` | CI |
   | `SHA256SUMS.sig` | release manager |
   | `sbom.spdx.json` | CI (`sca.yml`) |

9. **Verify from outside**: a second person downloads the assets from the public release page and
   runs `sha256sum -c SHA256SUMS`, verifies both `.sig` files and `spctl --assess` on the DMG. Only
   then is the update feed (§6) pointed at the release.
10. **Announce**: changelog link in the release discussion; `CHANGELOG.md` compare links updated
    (they were updated in step 2 — step 10 only checks them).

Sprint 1 debug builds (S1-12) run steps 3 and 4 only; they are not tagged, not released and not
fed to the updater.

### 3.4 Pulling a release

A published release is never deleted or re-uploaded under the same version. If it must not be
installed (a security defect, a broken migration), the next patch version is released and the
update feed skips the bad version; the bad release's notes get a **Yanked** marker per Keep a
Changelog. Tags are never moved.

### 3.5 Signing keys and custody

Three different signatures exist; do not conflate them.

| Signature | Key | Holder | Where used |
|---|---|---|---|
| Apple code signature + notarization | Apple Developer ID Application certificate | Apple Developer account owner (Andreas) — CI holds it as a secret for S1-12/§14 | the `.app`, the DMG |
| **Git tag signature** | dedicated release-signing key (see below) | **release manager (Andreas) — decision needed** | `git tag -s` |
| Detached manifest signatures | the same release-signing key | release manager | `release.json.sig`, `SHA256SUMS.sig` |

**Decisions Andreas must make (recorded in `docs/OPEN-QUESTIONS.md` under this item):**

1. **Key type.** Recommended: an SSH `ed25519` key used solely for signing (`git config
   gpg.format ssh`), hardware-backed (Secure Enclave via a signing agent, or a YubiKey). GPG is
   acceptable if a hardware token is used. Either way, the key is **release-only** — not the key
   used for pushing commits.
2. **Custody.** Who holds the key (default: Andreas as program owner) and who is the **second
   custodian** so a release is not blocked by one person's absence. A second custodian has a
   **separate key**; keys are never copied between people or machines.
3. **CI never signs tags or manifests.** CI only verifies. This is deliberate: a compromised CI
   can produce a bad build, but it cannot produce a bad build that *verifies*.

**Custody rules (binding once the decisions above are taken):**

- Public keys live in `packaging/signing/release-keys/<holder>-<yyyy-mm>.pub` with an
  `allowed_signers` file for `git verify-tag`; the fingerprint is also listed in `SECURITY.md`.
  Adding or removing a key is a `security(release)` PR requiring two reviewers (CODEOWNERS routes
  `packaging/signing/` to the security owners, S0-27).
- The private key never leaves the hardware token or the holder's Keychain; it is never in CI
  secrets, never in a password manager export, never in this repo.
- **Rotation** is scheduled every 24 months or immediately on suspected compromise, holder change
  or lost token. Procedure: add the new public key (the PR is signed with the **old** key); sign
  the next release with the new key; keep the old public key in the repo marked
  `# retired <date>` so historical tags still verify; mention the rotation under *Security* in the
  changelog. On compromise the old key is additionally marked `# revoked <date>` and every tag it
  signed is re-verified against the artefact hashes recorded in the corresponding GitHub Release.
- The Apple certificate follows Apple's own expiry; its renewal is tracked as a release checklist
  item (§5) and in the CI secrets inventory, never silently.

---

## 4. Changelog

`CHANGELOG.md` at the repository root follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).

- **Sections**, in this order and only these: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`,
  `Security`. Empty sections are omitted. (`Deprecated` is Keep a Changelog's; we use it for a
  setting or integration that is announced for removal in a later minor.)
- **Generated, then curated.** The release manager generates the raw list from
  `git log <previous-tag>..HEAD` using the type→section mapping in §1.1, then **edits it for a
  human reader**: merges entries that describe one change, rewrites commit-speak into what the
  user will notice, drops internal churn (`docs`, `test`, `ci`, `chore`, `refactor` without `!`).
  The generator proposes; a person decides. J reviews the result in the release PR.
- **Every entry links** to its pull request and, where one exists, its issue:
  `- Explainable customer matching for inbound mail ([#150](…/pull/150), closes [#142](…/issues/142))`.
- **Breaking entries** under *Changed* start with **BREAKING** and carry the migration note from the
  `BREAKING CHANGE:` footer.
- **Security entries** name the class of issue and the fixed version; they never contain exploit
  detail before the fix has been available for the disclosure window stated in `SECURITY.md`.
  Entries credit the reporter if they wish.
- `## [Unreleased]` is always present at the top. At release it is renamed to
  `## [X.Y.Z] - YYYY-MM-DD` and a new empty `[Unreleased]` is added above it.
- **Compare links** in the footer: `[Unreleased]: …/compare/vX.Y.Z...HEAD` and
  `[X.Y.Z]: …/compare/vX.Y.(Z-1)...vX.Y.Z`; the first release links `…/releases/tag/v0.1.0`.
- The GitHub Release body is the section copied verbatim — no second changelog is maintained.
- Yanked releases get ` [YANKED]` after the version heading (§3.4).

Feature PRs do **not** edit `CHANGELOG.md`; only the release PR does. This avoids a permanent
merge conflict on one file and keeps the curation in one place.

---

## 5. Release checklist (§14, transcribed)

Every item must be ticked in the release PR before the tag is signed. Owners are workstream
letters (§13); the release manager (I) is accountable for the list as a whole. "Evidence" is a link
in the release PR — a CI run, a document, a measurement file — not a sentence.

### Build and signing

| ✓ | Item (§14) | Owner | Evidence |
|---|---|---|---|
| [ ] | Apple Developer signing: `.app` and DMG signed with the Developer ID Application certificate; `codesign --verify --deep --strict` clean | A | CI release run |
| [ ] | Notarization accepted and ticket **stapled** to the DMG (`xcrun stapler validate`) | A | CI release run |
| [ ] | Clean-machine Gatekeeper test: DMG opens and installs on a Mac that never saw the source, Gatekeeper on, no terminal, no admin prompt until an OS-protected step needs it (§12.2) | I | protocol run record |
| [ ] | Version manifest: `release.json` generated, all three component versions pinned, consistency check green (§2.4) | I | CI release run |
| [ ] | `CFBundleVersion` strictly greater than every previously published build | A | `release.json` diff |

### Supply chain

| ✓ | Item (§14) | Owner | Evidence |
|---|---|---|---|
| [ ] | SBOM generated (`sbom.spdx.json`) and attached | I | `sca.yml` run |
| [ ] | License audit: every bundled dependency, executable and model licence compatible with MIT distribution and ADR-007; no new copyleft or no-redistribution component since the last release | J + H | audit report linked in release PR |
| [ ] | Dependency vulnerability scan has no unaccepted high/critical finding; accepted findings have a signed risk acceptance (H) | H | `sca.yml` run + risk-acceptance doc |
| [ ] | Checksums and hashes published: `SHA256SUMS` + `SHA256SUMS.sig`, `release.json` + `release.json.sig`; verified from the public download by a second person (§3.3 step 9) | I | verification note in release PR |
| [ ] | Tag signed with a key in `packaging/signing/release-keys/`; `git verify-tag` passes | release manager | publish workflow log |

### Data safety

| ✓ | Item (§14) | Owner | Evidence |
|---|---|---|---|
| [ ] | Data migration drill: previous release's user data upgraded by the real updater path; migrations ran after dry-run and backup (§6) | B | drill record |
| [ ] | Rollback drill: previous runtime + previous database backup restored and the app runs (§6); confirms **no** schema downgrade was needed or attempted | A + B | drill record |
| [ ] | Backup + restore validated on the release build (§14 security gates) | B | test run |

### Quality gates

| ✓ | Item (§14) | Owner | Evidence |
|---|---|---|---|
| [ ] | Test matrix published: full automated suite (Pest, authorization, E2E, XCTest, IPC, security fixtures, installer-interruption recovery) green on the release commit, results linked publicly | I | CI run + published matrix |
| [ ] | **§12.2 install-measurement gate**: Mac A and Mac B profiles, ≥ 30 measured installs each, `p50 < 180 s`, `p95 < 300 s` core-ready, measured with the §16.6 timer; 100 % of fresh install, re-install, upgrade, failed upgrade, interrupted install, uninstall and data-preserving reinstall covered. **Release-blocking from the first stable release**; Sprint 1 measures and records only (ROADMAP Sprint 1 gate). | I | per-phase JSON + summary |
| [ ] | Performance gates (§14): p95 API latency per endpoint within budget; idle CPU/memory within the RAM-tier budget; menu opens instantly from cached brief; no brief generation on menu open | I + B | benchmark report |
| [ ] | Security/privacy gates (§14): default-deny outbound mutating tools; `LOCAL_ONLY` packet-capture locality test; tenant-crossing negative tests; approved SMTP recipient only; no private text in push payloads; telemetry opt-in | H | gate report |
| [ ] | Accessibility + Lighthouse on iPhone and Mac PWA (§13 C DoD) | C | report |

### Documentation and communication

| ✓ | Item (§14) | Owner | Evidence |
|---|---|---|---|
| [ ] | Changelog section curated per §4; breaking changes carry migration notes | J | release PR |
| [ ] | README installation video re-recorded if any first-run screen changed (§12.3) | J | video link |
| [ ] | Security contact in `SECURITY.md` current; disclosure window and key fingerprints correct | H + J | `SECURITY.md` diff |
| [ ] | Documentation current: install guide, uninstall, backup/restore, recovery (`docs/operations/`) | J | docs diff |
| [ ] | Apple certificate and release-signing key expiry both more than 90 days away, or renewal scheduled | A + release manager | secrets inventory |

### Definition of Done cross-reference (§17)

A **`1.0.0`** tag additionally requires every §17 item ticked in `docs/ROADMAP.md`
("Definition of Done for v1.0"), with evidence, and no item waived without an ADR. Pre-1.0 releases
record which §17 items are already met so progress is visible, but are not blocked by them.
The §17 items this checklist directly evidences: signed/notarized app; verified < 5 min p95
install; auto-updater works; backups, restore, updates, uninstall tested; external developer can
build and contribute.

---

## 6. Update channel policy (§9)

### 6.1 Channels

- **v1 ships one channel: `stable`.** No beta, nightly or canary channel is exposed in the
  product. `channel` exists in `release.json` and the feed (`beta` reserved) so that adding one
  later is a manifest change, not a format change. Pre-release tags (`-rc.N`, `-beta.N`) are
  published as GitHub pre-releases for manual testing and are **never** listed in the stable feed.
- The updater (A, `app/Support/Updates`, Sprint 4) checks the feed on a schedule and on demand;
  checking is on by default, **installing is a user action** unless the user opts into
  auto-install in Settings. Telemetry rules (§10) apply: the update check sends the current
  `product_version`, `build`, `min_macos` compatibility info and nothing else.

### 6.2 Signed feed manifest

The feed is a single JSON document per channel, `feed.json`, published alongside the releases
(location decided with ADR-006/Sprint 4 — GitHub Releases or GitHub Pages; it must be an HTTPS
origin the product does not share with user data). It is signed with the release-signing key as a
detached `feed.json.sig`.

```json
{
  "feed_version": 1,
  "channel": "stable",
  "published_at": "2026-10-10T12:00:00Z",
  "releases": [
    {
      "product_version": "0.2.0",
      "build": 57,
      "min_macos": "15.0",
      "release_json_url": "https://…/v0.2.0/release.json",
      "release_json_sha256": "…",
      "dmg_url": "https://…/v0.2.0/LaravelExecutiveAI-0.2.0.dmg",
      "dmg_sha256": "…",
      "delta_from": { "0.1.0": { "url": "…", "sha256": "…" } },
      "notes_url": "https://github.com/STAFE-GROUP-AB/laravel-executive-ai/releases/tag/v0.2.0",
      "yanked": false
    }
  ]
}
```

The updater's verification order is fixed and fails closed at every step:
**feed signature → `release.json` signature → artefact SHA-256 → Apple code signature and
notarization ticket → `min_macos` and `build` greater than installed.** A release that fails any
step is reported to the user and never staged. `yanked: true` hides a release (§3.4). Delta updates
(§9 "delta om lämpligt") are optional per release; a missing or failed delta falls back to the full
DMG, never to an unsigned path.

### 6.3 Database migrations on update

§9: *"DB migration dry-run/backup"*. The updater, before switching the active runtime:

1. Takes a **consistent SQLite backup** of the user database using the online backup/checkpoint
   API (§9 — never a live-file copy), named with the installed `product_version`, `build` and
   `schema_version.database`, into the managed backup directory.
2. Runs the new core's migrations **against a copy of that backup** (`--pretend`/dry-run plus a
   real run on the copy). Any failure aborts the update before the user's data is touched; the
   previous version keeps running.
3. Only then runs the migrations on the live database and switches the runtime symlink/marker to
   the new immutable runtime version (§12.1). The previous runtime version stays on disk.

### 6.4 Rollback

§9: *"rollback av binaries, ingen automatisk rollback av inkompatibla schemaändringar utan plan"*.

- **Rollback = previous runtime version + previous database backup.** Because runtimes are
  immutable and versioned (§12.1) and two versions coexist, switching back is a pointer change;
  the database is restored from the backup taken in §6.3 step 1. Data entered between the upgrade
  and the rollback is lost and the user is told so, explicitly, before confirming.
- **Never a schema downgrade.** Migrations have no `down()` in this project (coding guidelines),
  and the updater never attempts to run one. A newer schema is not "rolled back"; the backup is
  restored.
- **Automatic rollback** happens only when the new version fails its own `/health/ready` self-test
  within the boot window after an update **and** the schema version is unchanged. If the schema
  changed, the updater stops, keeps both runtime versions and both database files, and presents
  the choice (keep new version and report; restore backup and lose recent changes). It never
  decides that alone — that is the "plan" §9 demands.
- The previous runtime version and the pre-upgrade backup are retained for at least one further
  successful release cycle (or 30 days, whichever is longer), then garbage-collected per the backup
  retention policy.

### 6.5 Compatibility windows

- The updater must support upgrading from **any** release of the current minor line and from the
  **previous** minor line (`0.2.x → 0.3.0`, `0.3.x → 0.3.y`). Older installs are offered the newest
  release they can reach first; the changelog's migration notes say so.
- A release that cannot be upgraded to from the previous minor is a MAJOR bump after 1.0 and
  requires an ADR before 1.0.

---

## 7. Related documents

- `CONTRIBUTING.md` — workflow and PR rules this policy plugs into.
- `CHANGELOG.md` — the changelog this policy governs.
- `docs/contributing/branch-protection.md` (S0-27) — the exact protection settings and merge method.
- `.github/workflows/ci.yml`, `sca.yml` (S0-05) — where the §1.5 lint and the SBOM live.
- `packaging/manifest/` — `release.json`, `runtime.json` (ADR-001), schemas.
- `packaging/signing/release-keys/` — public keys and `allowed_signers`.
- `docs/adrs/001-macos-runtime-packaging.md` — runtime version format and coexistence.
- `docs/operations/` — install-measurement protocol, uninstall, backup/restore, recovery.
