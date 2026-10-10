# Contributing

Thanks for your interest. This project is in **Sprint 0 (architecture freeze)**; the most useful contributions right now are reviews of the ADRs in `docs/adrs/` and of the open decisions listed in the blueprint §15. Code contributions open up once the Sprint 0 contracts are approved.

## Ground rules

- **Be decent.** This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md).
- **Read the blueprint first**: `Mission/Laravel_Executive_AI_Engineering_Blueprint.md`. It is the binding specification. Product requirements are in Swedish; identifiers, APIs, code and commit messages are in English.
- **Scope is deliberately closed.** Do not propose Basecamp, GoZero Core, Filament, OpenClaw, a mandatory Playwright/Browserless dependency, Linux/Windows support, or general terminal access for the agent. PRs that reintroduce these will be closed.
- **Architectural changes need an ADR** in `docs/adrs/` before code.

## Workflow

1. Open or pick an issue. Every PR must reference one.
2. Branch from `main`. `main` is protected and requires CI.
3. Keep PRs small and reviewable. **No giant "AI agent builds everything" PRs.** Break work into vertical slices with tests and reproducible behaviour.
4. Use [Conventional Commits](https://www.conventionalcommits.org/): `feat(crm): …`, `fix(mac): …`, `docs(adr): …`, `test(security): …`. Allowed types, scopes, the CI-enforced header regex, versioning and the release process are in [`docs/operations/release-policy.md`](docs/operations/release-policy.md).
5. Include in the PR description: the issue, the ADR if architectural, tests added, **security impact** (even if "none"), and a screenshot or recording for UI changes.
6. Security-critical modules (`app/Security`, `app/Approvals`, `app/MCP`, `app/Integrations`, installer/updater, IPC) require two reviewers.

## Code standards

- **PHP/Laravel**: Laravel 13, PSR-12, typed properties, short nullable syntax, early returns, no `else` where avoidable, Pest for tests. Follow Laravel conventions first; deviate only with a stated reason.
- **Swift**: SwiftUI, Swift 6 strict concurrency, XCTest. AppKit only where SwiftUI cannot do the job.
- **Frontend**: Livewire 3, Alpine.js, Tailwind CSS. No component framework beyond those.
- **Tests are not optional.** Authorization tests for every new policy surface; negative tests for cross-account access; fixtures over live services.
- **Never commit secrets, tokens, model files or `.env`.** CI scans for them and the PR will fail.

## Reporting security issues

Privately, per [`SECURITY.md`](SECURITY.md). Never in a public issue or PR.

## License

By contributing you agree your contribution is licensed under the [MIT License](LICENSE).
