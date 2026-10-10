# Changelog

All notable changes to Laravel Executive AI are documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and this
project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html). Entries are
generated from conventional commits and curated by a human at release time; the rules are in
[`docs/operations/release-policy.md`](docs/operations/release-policy.md). Pre-1.0, a minor bump may
contain breaking changes; each one is marked **BREAKING** under *Changed*.

## [Unreleased]

Sprint 0 — architecture freeze. Nothing is installable yet; there is no runtime, no Mac app and no
Laravel application code. Everything below is repository bootstrap, governance and planning.

### Added

- Repository bootstrap: the engineering blueprint (`Mission/`), MIT license, `README.md`,
  `SECURITY.md`, `CONTRIBUTING.md` and `CODEOWNERS`
  ([96fa832](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/commit/96fa832)).
- Contributor Covenant code of conduct, pull request template and issue forms for bugs, tasks and
  ADR proposals ([778f45a](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/commit/778f45a),
  closes [#3](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/issues/3),
  [#4](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/issues/4)).
- `docs/ROADMAP.md` (gated sprints 0–4 and the §17 Definition of Done), `docs/BACKLOG.md`
  (issue-ready Sprint 0 and Sprint 1 items) and `docs/OPEN-QUESTIONS.md` (the §15 unresolved
  decisions mapped to ADRs and owners)
  ([c9d4f1c](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/commit/c9d4f1c)).
- Monorepo scaffold matching blueprint §2.1 — `apps/macos/`, the `app/` domain directories,
  `packaging/`, `tests/`, `docs/` — plus the ADR template and ADR index
  ([b302dfd](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/commit/b302dfd),
  closes [#1](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/issues/1),
  [#2](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/issues/2)).
- Release, versioning and changelog policy (`docs/operations/release-policy.md`): Conventional
  Commits with the CI-enforced header regex, SemVer for the product release pinning the Mac app,
  Laravel core and bundled runtime through `packaging/manifest/release.json`, signed tags, the §14
  release checklist and the §9 update/rollback rules; this changelog
  (closes [#28](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/issues/28)).

### Changed

- Open questions A6 and A7 resolved with verified facts about `laravel/ai` and `laravel/mcp`
  ([741ea32](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/commit/741ea32)).

[Unreleased]: https://github.com/STAFE-GROUP-AB/laravel-executive-ai/commits/main
