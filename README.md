# Laravel Executive AI

**An open source, self-hosted, macOS-native AI executive assistant for developers, consultants and small teams.**

> **Local First · Europe First · Open Source First**

> **Status: pre-alpha — Sprint 0 (architecture freeze).** Nothing is installable yet. The full engineering specification is in [`Mission/Laravel_Executive_AI_Engineering_Blueprint.md`](Mission/Laravel_Executive_AI_Engineering_Blueprint.md) and the phased plan in [`docs/ROADMAP.md`](docs/ROADMAP.md). Follow progress in the [issues](../../issues).

## What it is

A personal secretary that lives on your Mac, not in someone else's cloud:

- **A signed, notarized SwiftUI Mac app** that installs, updates and monitors the system, with a `MenuBarExtra` showing your latest *Executive Brief*.
- **An installable TALL stack PWA** (Laravel, Livewire, Alpine.js, Tailwind CSS) with AI chat, a built-in local CRM, tasks, contacts, communications, integrations and a fully custom admin — **no Filament**.
- **A model-neutral, Laravel-native AI and workflow engine** built on the official [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk). Bring your own model: Ollama, LM Studio, MLX-compatible endpoints, OpenAI, Anthropic, Gemini, OpenRouter or a custom endpoint. **No brand is preselected.**
- **A built-in local CRM** as the system of record for customers, contacts, communications, projects and todos. Email accounts, calendars and GitHub are *integrations*, not the source of truth.
- **One versioned Executive Brief** shown in the menu bar *and* delivered by email — identical facts, priorities and references in both.
- **Unified multi-account inbox**: Gmail/Google Workspace, Microsoft 365/Outlook, iCloud, HEY, generic IMAP/SMTP and optional Proton Bridge, any number of accounts, read-only by default.
- **Optional** controlled macOS GUI automation via [Peekaboo](https://github.com/openclaw/Peekaboo), **optional** MCP discovery with security review before anything connects, **optional** private remote access to the PWA over [Tailscale Serve](https://tailscale.com/docs/features/tailscale-serve).

### The five-minute promise (as a measured gate, not marketing)

From opening the signed `.dmg` to a usable local PWA, an owner account, working AI chat (with an already-available local model or your own API key) and a working menu bar app: **p50 < 3 min, p95 < 5 min** on a tested Mac profile. Large model downloads, OAuth flows, Peekaboo permissions and Tailscale login happen *after* core-ready and are never hidden inside that number. Without a model or API key the app is installed but clearly shows **AI setup required**, never a false **AI ready**.

## Principles that are enforced, not aspirational

- **Local Only mode is strict**: no model traffic, embeddings, screenshots or prompts leave the Mac; no silent fallbacks.
- **The LLM proposes, it never has permissions.** A server-side `PolicyGate` validates every tool call, scope, destination, budget and approval before execution. Mail send, CRM deletion, GitHub writes and external forms are always approval-driven.
- **All externally collected text is data, not instruction.** Tool descriptions, web pages, emails and MCP responses are untrusted input; policy decisions happen in code, not in prompts.
- **Credentials live in the macOS Keychain**, never in database tables, localStorage, logs, prompts or this repo.
- **Telemetry is off by default.**
- **No Basecamp. No GoZero Core. No Filament. No OpenClaw. No mandatory Playwright/Browserless. No Linux or Windows in v1.** No claims of formal "EU AI Act certification".

## Architecture (modular monolith)

```text
[SwiftUI Installer + MenuBarExtra]
       | authenticated loopback IPC
       v
[Laravel 13 TALL PWA + Admin + API]
  |-- Auth / Organizations / Policies / Approvals
  |-- Local CRM / Contacts / Projects / Communications / Tasks
  |-- AI Orchestrator / Provider Registry / Memory / Briefs
  |-- MCP Registry Client / Tool Policy / Broker
  |-- Integrations / Sync / Unified Inbox / Audit / Retention
  |-- Queue + Scheduler + Health
       |             |                |
       v             v                v
[SQLite (WAL)]  [Local LLM]    [Mac Automation Bridge]
                [Ollama etc]    [Peekaboo, optional]
       |
[Optional HTTPS via Tailscale Serve -> PWA]
```

SQLite in WAL mode with the Laravel database queue for core-ready. No Docker, Homebrew, MySQL, Redis or Terminal required to open the CRM/PWA. Laravel lives at the repository root; the Mac app lives in `apps/macos/`. See the blueprint §2 for the full rationale and §15 for the decisions still open.

## Repository layout

```text
apps/macos/        SwiftUI app: installer, menu bar, launchd/SMAppService, updater
app/               Laravel domains: AI, Browser, CRM, MCP, Integrations, Briefs, Approvals, Security, Support
resources/ routes/ database/ tests/
packaging/         manifest, signing, notarization, fixtures
docs/              architecture, ADRs, operations, security, compliance, roadmap
.github/workflows/ CI
```

## Roadmap

Sprint 0 architecture freeze → Sprint 1 vertical slice (install → create owner → open PWA → seeded brief → same brief in menu bar → exact email preview) → Sprint 2 real core (CRM, auth/MFA, assistant, real briefs) → Sprint 3 sources and automation (multi-account email, GitHub, MCP, Peekaboo) → Sprint 4 production candidate (Tailscale, upgrades, backups, EU data controls, notarization, third-party review). Details and the current backlog: [`docs/ROADMAP.md`](docs/ROADMAP.md).

## Development

Requires PHP 8.4, Composer 2 and Node 24. No MySQL, Redis or Docker: the app runs on a single SQLite file in WAL mode with the database queue.

```sh
git clone https://github.com/STAFE-GROUP-AB/laravel-executive-ai.git
cd laravel-executive-ai
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer test
```

## Contributing

Pull requests are welcome once Sprint 0 contracts are frozen. Read [`CONTRIBUTING.md`](CONTRIBUTING.md). Report vulnerabilities privately per [`SECURITY.md`](SECURITY.md) — never in public issues.

## License

[MIT](LICENSE) © 2026 STAFE GROUP AB.
