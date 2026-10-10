# Laravel Executive AI — Engineering Master Blueprint

> **Status:** Build-ready product specification / implementation mandate  
> **Date:** 2026-10-10  
> **Audience:** Senior engineering team, design, security, QA and release engineering  
> **Language:** Swedish product requirements; English identifiers, APIs and source code  
> **North star:** **Local First · Europe First · Open Source First**

## 0. Uppdrag och bindande beslut

Bygg **Laravel Executive AI**, en open source, självhostad, macOS-exklusiv AI-sekreterare för utvecklare, konsulter och små team. Produkten består av:

1. **En förstklassig signerad och notariserad SwiftUI Mac-app** som installerar/uppdaterar/övervakar systemet, visar status och har en `MenuBarExtra` med senaste *Executive Brief*.
2. **En riktig installbar TALL Stack PWA** (Laravel, Livewire, Alpine.js, Tailwind CSS) med AI-chat, Local CRM, uppgifter, kontakter, kommunikation, verktyg, integrationer och full egenbyggd administration — **ingen Filament**.
3. **En Laravel-native AI- och workflow-motor**, modellneutral, med lokal och valfri fjärrinferens.
4. **Peekaboo** för valfri, tillståndsstyrd macOS-GUI-automation (Safari och andra appar), utan OpenClaw och utan Playwright som obligatoriskt beroende.
5. **MCP discovery, säkerhetsgranskning, verktygsåtkomst och kontrollerad körning**, där ingen tredjepartsserver installeras tyst.
6. **Inbyggt Local CRM** som system of record för kunder, kontakter, kommunikation, projekt och todos. GitHub och valfria mejlkonton/kalendrar är integrationer.
7. **En enda versionsmärkt Executive Brief** som både visas i menyraden och skickas via mejl; exakt samma fakta, prioriteringar och referenser.
8. **Privat fjärråtkomst** till PWA via valfri Tailscale Serve; fjärråtkomst ska kunna konfigureras efter den snabba lokala installationen.

### Uttryckligen utanför scope

- **Inget Basecamp**: ingen inbyggd integration, inget onboardingval, inga referenser i produkt-UI.
- **Inget GoZero Core**: ingen leverantör, inget förval, ingen routing, ingen telemetri eller koppling.
- **Inget Filament, OpenClaw, obligatoriskt Playwright, Browserless eller beroende av en kommersiell molntjänst.**
- Inget Linux- eller Windows-stöd i den första produkten.
- Ingen generell terminalåtkomst för AI-agenten; ingen automatisk merge/deploy/sändning eller shopping.
- Inga påståenden om formell "EU AI Act-certifiering".

### Kritisk definition av femminutersmålet

**Under fem minuter = från öppnad signerad `.dmg`/installerad `.app` till en användbar lokal PWA, ett skapat administratörskonto, fungerande AI-chat med vald redan tillgänglig lokal modell eller användarens egen API-nyckel, och en fungerande menyradsapp — på en kompatibel Mac med tillräckliga resurser och fungerande nätverk för eventuella nödvändiga nedladdningar.**

Mät från första klick på **Install** till "Ready" med loggad median/p95 på rena testmaskiner. Målet är **p50 < 3 min, p95 < 5 min** för *core-ready* på testad maskinprofil **med cachade/signerade runtimes och redan installerad eller liten nedladdad modell**. Detta är ett **release-gate-mål som ska bevisas**, inte ett löfte innan mätning.

**Följande sker asynkront efter core-ready:** stora modellnedladdningar, Google OAuth, GitHub Apps, iCloud/HEY, Peekaboo-behörigheter, Tailscale-login, push på iOS och e-postverifiering. Ingen får dölja dessa moment i femminutersmätningen eller hävda att en stor modell laddats ner på fem minuter oberoende av internetanslutning. Utan tillgänglig modell/API-nyckel ska appen vara installerad men tydligt visa **AI setup required**, inte felaktigt **AI ready**.

---

## 1. Produktupplevelse och informationsarkitektur

### 1.1 Native Mac-app

SwiftUI, AppKit vid behov, `MenuBarExtra`, tydliga statusytor, egen sober visuell identitet (premium, inte kopia av CleanMyMac). Skärmar:

- Welcome / system check / privacy statement.
- Install location, resources, prerequisites, disk usage.
- `Install` med högst ett huvudsakligt godkännandeflöde; explicita OS-dialoger när Apple kräver dem.
- Choose AI: **ingen vald leverantör i förväg**. Local via Ollama/LM Studio/MLX-kompatibel endpoint, OpenAI, Anthropic, Gemini, OpenRouter, Custom. Endast alternativ som faktisk adapter stöder exponeras.
- Local CRM ready; create owner account; first conversation.
- Optional connections: obegransat antal e-postkonton (Google/Gmail/Workspace, Microsoft 365/Outlook/Hotmail, iCloud, HEY, generic IMAP/SMTP och valfri Proton Bridge), GitHub orgs, kalendrar och MCP providers. Anslutning via enklast tillgangliga sakra metod med guidad provider-autodetection.
- Optional Peekaboo with guided Screen Recording / Accessibility permission checks.
- Optional Private Remote Access via Tailscale Serve.
- Settings: Services, Local Models, Privacy/Residency, Updates, Backups, Diagnostics, Power, Uninstall.

Menyradspanelen ska visa senaste briefens datum/genereringstid, systemstatus, uppgifter som kräver godkännande, åtgärdsgenvägar, "Open full brief", "Pause AI actions", "Open PWA", "Settings" och "Quit UI" (inte samma som Stop server). Visa tydligt vid **stale brief**, offline eller fel. Notification badge endast för verkliga handlingspunkter.

### 1.2 PWA

App-shell med installability (manifest, ikoner, HTTPS för fjärråtkomst, service worker). Responsiv desktop/tablet/iPhone-layout. Information architecture:

- `/home` executive dashboard
- `/assistant` streaming chat, verktygsaktivitet, källhänvisningar, cost/tokens
- `/briefs` versionshistorik, share/export/email status
- `/approvals` pending, approved, rejected, expired, cancelled
- `/crm/customers`, `/crm/contacts`, `/crm/projects`, `/crm/tasks`, `/crm/communications`
- `/inbox` kontokopplad kommunikation och åtaganden
- `/integrations` konton, scopes, sync health
- `/mcp` discovery, security review, server tools, revoke
- `/automation` tillgängliga verktyg, Peekaboo sessions, emergency stop
- `/admin` egenbyggd TALL Stack admin för användare, orgs, säkerhetsregler, loggar, retention, updates och backup

Offline: cacha bara app-shell, valfria användargodkända ofarliga data samt oskickade utkast. **Cacha inte automatiskt kundbrev, tokens, credentials eller innehåll med hög känslighet i service worker/IndexedDB.** PWA:n får aldrig hävda att AI kör på telefonen; AI och scheduler kör på Mac.

### 1.3 Executive Brief

En brief har `id`, `version`, `generated_at`, `period_start/end`, `subject`, `summary`, `items[]`, `sources[]`, `action_ids[]`, `model_provenance`, `delivery_state`, `stale_after`. Briefen sammanställs och fryses transaktionellt en gång, renderas till **samma semantiska data** via SwiftUI och HTML-mail; innehållsskillnader förbjuds. Idempotent e-postleverans; mail får inte skickas dubbelt vid retry. HTML-mail ska vara snygg, tillgänglig och degradera väl i Apple Mail/Gmail/HEY. Lokalt preview-läge utan utgående mejl.

---

## 2. Arkitektur: modulär monolit, minimala beroenden

```text
[SwiftUI Installer + MenuBarExtra]
       | localhost authenticated IPC / loopback HTTPS
       v
[Laravel 13 TALL PWA + Admin + API]
  |-- Auth / Tenant+Organization / Policies / Approvals
  |-- Local CRM / Contacts / Projects / Communications / Tasks
  |-- AI Orchestrator / LLM Provider Registry / Memory / Briefs
  |-- MCP Registry Client / Tool Policy / MCP Broker
  |-- Integrations / Sync / Unified Inbox / Audit / Retention / Notifications
  |-- Queue + Scheduler + Health
       |             |                |
       v             v                v
[Local DB]      [Local LLM]    [Mac Automation Bridge]
[SQLite first]  [Ollama etc]    [Peekaboo only, optional]
       |
[Optional HTTPS via Tailscale Serve -> PWA]
```

**Val för snabb installation:** använd **SQLite i WAL mode + Laravel database queue** för core-ready, med en tjänst och lokal native PHP runtime. MySQL och Redis/Horizon kan erbjudas som avancerad migrations-/driftsprofil senare. Detta ersätter tidigare idé om att kräva MySQL + Redis redan i bootstrap: de är inte nödvändiga för en Mac-first personlig install och riskerar femminutersmålet. **Säkerställ migrationsväg och kontraktstester mellan backend-profiler**; om teamet efter benchmark kan bevisa en enklare underhållen MySQL-bundle inom fem minuter får ADR motivera byte. Core-app ska inte kräva Docker, Homebrew eller att användaren öppnar Terminal.

**Runtime:** paketera/leverera licenskompatibla, versionslåsta, signerade/notariserade artefakter. PHP och nödvändiga binaries ligger i appens stödjande resurser eller hämtas från verifierad release-manifest via installer-helper. Använd `launchd`/`SMAppService` för övervakade processer. Laravel server kör på loopback bakom en medföljande minimal HTTP-server eller vältestad native runtime. Undvik `php artisan serve` som produktionsserver. Säkerställ tydlig kompatibilitet med macOS 15+ och Apple Silicon; dokumentera om Intel stöds eller uttryckligen inte stöds.

**En process per funktion där det behövs, inte mikrotjänster:** core webserver, background worker/scheduler, optional local AI, optional Peekaboo bridge. Inter-process communication lokalt och autentiserat. Ingen fri container- eller root-access krävs för vanlig användning.

### 2.1 Kodbashierarki

```text
laravel-executive-ai/
  apps/macos/                    # SwiftUI, signed app, menu bar, installer
  app/                           # Laravel backend in same mono-repo
    AI/{Agents,Providers,Memory,Tools,Routing}
    Browser/{Search,Fetch,ComputerUse}
    CRM/{Customers,Contacts,Projects,Tasks,Communications}
    MCP/{Registry,Assessment,Broker,Runtime}
    Integrations/{Google,ICloud,Hey,GitHub,Mail}
    Briefs/{Generation,Render,Delivery}
    Approvals/{Policies,Actions}
    Security/{Secrets,Audit,Retention,Authorization}
    Support/{Health,Power,Install,Updates}
  resources/{views,js,css}
  routes/{web,api,console}.php
  database/{migrations,seeders,factories}
  tests/{Feature,Unit,Integration,Security,Performance,E2E}
  packaging/{manifest,signing,notarization,fixtures}
  docs/{architecture,adrs,operations,security,compliance,contributing}
  .github/workflows/
  LICENSE
  README.md
  SECURITY.md
  CONTRIBUTING.md
  CODE_OF_CONDUCT.md
```

## 3. Modulkontrakt och datamodell

Definiera versionsstabila interna interfaces och DTO:er, undvik att direkt koppla agenten till leverantörsspecifika klienter:

```php
interface AiProviderAdapter {
    public function capabilities(): ModelCapabilities;
    public function generate(AiRequest $request): AiResponse;
    public function stream(AiRequest $request): iterable;
}

interface WorkspaceRepository {
    public function customers(CustomerQuery $query): iterable;
    public function contacts(ContactQuery $query): iterable;
    public function projects(ProjectQuery $query): iterable;
    public function tasks(TaskQuery $query): iterable;
    public function timeline(ActivityQuery $query): iterable;
}

interface ComputerUseProvider {
    public function capabilities(): array;
    public function observe(AutomationSession $session): Observation;
    public function execute(AuthorizedUiAction $action): ActionResult;
}

interface TunnelProvider {
    public function inspect(): TunnelStatus;
    public function configure(TunnelConfig $config): TunnelResult;
    public function disable(): void;
}
```

Det här är **kontraktsexempel**, inte drop-in kod. Implementationen kräver explicit typning, transaktionshantering, exceptions, cancellation och policy-checks.

### 3.1 Databasminimum

`users`, `organizations`, `memberships`, `customers`, `contacts`, `customer_contact`, `projects`, `project_links`, `tasks`, `task_assignees`, `communications`, `messages`, `threads`, `external_accounts`, `sync_cursors`, `source_records`, `commitments`, `ai_providers`, `ai_models`, `ai_runs`, `tool_runs`, `mcp_servers`, `mcp_tools`, `mcp_grants`, `browser_sessions`, `action_proposals`, `action_approvals`, `briefs`, `brief_items`, `brief_deliveries`, `memories`, `memory_sources`, `audit_events`, `privacy_policies`, `data_retention_rules`, `backup_runs`, `health_events`.

Varje affärsobjekt: UUID/ULID, `organization_id` där relevant, timestamps, retention policy, `source_id/source_uri`, sensitivity class, provenance, soft-delete efter behov. Unique indexes för `provider/account/external_id`, idempotency keys och versionsnummer. Encrypt at rest känsliga attribut + disk via FileVault. **SQLite krypteras inte automatiskt av FileVault när användaren är inloggad**: lagra credentials i Keychain, gör hotbilder och dokumentera kvarstående risker; utvärdera SQLCipher separat i security ADR om databaskryptering behövs.

### 3.2 Lokalt CRM som kärna

Kunder med juridisk identitet, relationer/owner, kontakter, flera e-postadresser, samtals-/mejltrådar, anteckningar, todos, projekt, status, deadlines, bilagereferenser, aktiviteter och GitHub-länkar. Import/export CSV/JSON och GDPR-export/radering. Aktivitetstidslinje ska visa källor och komma ihåg lovade uppföljningar. Fulltext först med SQLite FTS5 / adapter; vektorsökning byggs som opt-in först efter benchmark. Ingen extern vector DB som krav.

---

## 4. AI, lokal modell, agentverktyg och minne

- Använd officiell Laravel AI SDK om kompatibilitet och API:er verifieras med låst version; bygg tunn provider-adapter och kontraktstester. `laravel/mcp` för exponering/integration där stödet finns. Se officiella länkar i slutet.
- **Neutral onboarding, inget förvalt varumärke eller modell.** Modellalternativ: Ollama, LM Studio, MLX-kompatibla endpoints, OpenAI, Anthropic, Gemini, OpenRouter, samt Custom endpoint. Lägg aldrig till GoZero Core som inbyggt alternativ.
- Local Only-läge är strikt: ingen modelltrafik, embeddings, screenshots, tool transcript eller prompt till moln; inga tysta fallbacks. En explicit internetkoppling för synkronisering mot externa mejl- eller GitHub-API:er är en separat kategori och ska beskrivas transparent.
- Europe Restricted-läge är policybaserat och kräver verifierad avtalad databehandlingsregion och tredjelandsöverföringsanalys; flagga inte leverantörer som EU-säkra utifrån domännamn.
- Capability-aware model routing: text, structured output, tools, vision, embedding, context, streaming, cost limits, concurrency; fail closed när vald modell inte stöder funktionen.
- LLM ska generera **förslag**, aldrig egna behörigheter. Server-side `PolicyGate` validerar verktygsnamn, input-schema, org/account/scope, data class, destination, budget och godkännande före exekvering.
- Konversation, minne och användarprofil är appägda. LLM kan inte uppdatera permanent minne utan annoterad, granskad operation.
- Agentkontroll: maxsteps, maxcost, maxtime, dedupe, cancellation, circuit breakers, redaction, trace-id, output provenance och replay med syntetiska testdata.

### 4.1 Rekommenderade AI-jobb

`SyncSource`, `ExtractCommitments`, `ResolveIdentity`, `SummarizeThread`, `AssessProjectRisk`, `CreateDraft`, `ComposeBrief`, `SendBrief`, `PrepareMeeting`, `GenerateWeeklyReview`, `EvaluateMcpServer`, `RunAuthorizedTool`, `PurgeExpiredData`.

### 4.2 AI-kvalitetskrav

- Alla väsentliga påståenden i brief ska bära källreferenser eller explicit osäkerhetsmarkering.
- Deduplicera samma ärende mellan mejl, CRM och GitHub; inga påhittade deadlines.
- Utvärdera hallucinationer, falska prioriteringar, språk (svenska, engelska, italienska, tyska), motstridiga källor och prompt injection på frysta fixtures.
- `AI unavailable` får inte hindra CRM, läsa tidigare brief eller admin.

---

## 5. Integrationer och kontoarkitektur

### 5.1 Google (valfritt, flera konton)

Separat OAuth per konto med minsta möjliga scopes; läs/sök Gmail, Calendar och valfria Drive-dokument. Varje konto har egen tenant/org-mappning och credential i Keychain eller välavgränsad krypterad secret store. Queue/retry med backoff, dedupe och history/sync cursor. Gmail push via Pub/Sub är valfritt för avancerad setup; polling är enklare start. Hänvisa till Googles begränsningar för OAuth-verifiering/restricted scopes innan publik lansering. Endast mejlsändning via separat beviljad scope och explicit approval.

### 5.2 iCloud Mail (valfritt)

IMAP över TLS och SMTP STARTTLS med appspecifikt lösenord. Dokumentera kontots begränsningar och att CalDAV/CardDAV kräver separata, verifierade integrationer. Credentials i Keychain; strikt certifikatverifiering. Idempotent hämtning och hantering av UIDVALIDITY.

### 5.3 HEY (valfritt)

Officiellt HEY CLI som separat signerad/versionskontrollerad valfri adapter, med användarauktorisering. Process invocation via explicit argumentlista, aldrig shellinterpolation; sandbox och loggmaskning. Faktiska CLI-funktioner måste kontrolleras mot installerad version. Varken HEY eller CLI är krav för `core-ready`.

### 5.4 GitHub (valfritt, flera organisationer)

GitHub App med installation per organisation, org/repo-allowlist, read-only scopes som standard. Issues, PR, checks, workflows, release status och relationer till Local CRM. GitHub App private key skyddas; installationstokens kortlivade. Webhook-ingång kräver signaturverifiering + replay/idempotency; vid strikt privat installation kan polling användas i stället för publik webhook. Inga merges, commits, issue edits eller workflows triggers före specifik godkänd skrivpolicy.

### 5.5 Sändning av Executive Brief

Konfigurera SMTP med verifierad mottagare och testmeddelande. Godkännande av daglig automatisk leverans ges separat. HTML + plaintext; SMTP credentials i Keychain; delivery IDs och retry guards. Ingen e-post skickas innan mottagare och sändkonto är bekräftade.


### 5.6 Multi-account Email Platform — bindande v1-krav

**Produktkrav:** En och samma användare ska kunna ansluta valfritt antal mejlkonton och kombinera olika leverantörer i **Unified Inbox**, per konto eller per organisation. Ingen hårdkodad kontogräns. Begränsningar från externa kvoter, disk, minne och prestanda ska synliggöras. Lokal CRM-tidslinje kopplar meddelanden och åtaganden till kunder/kontakter med spårbar källa och möjlighet att korrigera felaktiga matchningar. Basecamp och GoZero Core får inte återinföras via denna funktion.

**Stödda leverantörer, anslutningsmetod och förväntad UX:**

| Provider | Primär säker anslutning | Fallback / begränsning | UX |
|---|---|---|---|
| Gmail, Google Workspace | OAuth 2.0 Authorization Code + PKCE, Gmail API | Workspace-admin/Google-verifiering kan krävas; aldrig användarlösenord | **Connect Google** → välj konto → godkänn scopes |
| Microsoft 365, Outlook.com, Hotmail | Microsoft identity platform OAuth 2.0 + PKCE, Microsoft Graph | Tenant-admin consent kan krävas; skilj personal vs org account | **Connect Microsoft** → logga in → godkänn |
| HEY.com | Officiellt HEY CLI och dess stödda authorization flow | Kontrollera aktuella kommandon, funktioner och lokal CLI-session innan shipping | **Connect HEY** → guidad officiell auth |
| iCloud Mail | Stödd tredjepartsauktorisering om faktiskt tillgänglig för avsett mailflöde | Annars IMAP/SMTP med Apple appspecifikt lösenord; inte lova generell iCloud Mail OAuth | **Connect iCloud** → guidad autentisering |
| Annan mejl (egen domän) | Leverantörs-OAuth där verifierat stöd finns | IMAP TLS + SMTP STARTTLS/TLS; autodiscovery med manuell override | **Other email** → adress → rekommenderad metod |
| Proton Mail | Valfri lokal Proton Mail Bridge där installerad och licensierad | Inte direkt Proton IMAP mot molnet; Bridge-krav/abonnemang redovisas | **Connect Proton** → kontrollera Bridge |

**Onboarding under fem minuter gäller endast core-ready.** Mejlkonton ansluts efter installation utan att blockera PWA/CRM. Wizard i SwiftUI och PWA: `Add Email Account` → identifiera provider från adress/domain (Google/Microsoft domäner kan använda custom MX; användaren kan korrigera) → starta tillåten autentisering → visa minsta scopes och rättigheter → anslutningstest → val av mappar/synkintervall → klar. Ingen SMTP-portinställning när OAuth+API fungerar. För IMAP/SMTP erbjud förifyllda serverdata med TLS-verifiering och avancerad manuell override. Nya konton kan tillkomma när som helst; visa per konto status, senaste sync, scopes, synkade mappar och `Disconnect/Reauthorize`.

**Säkerhets- och tillståndsmodell:**

- Separat `connected_email_accounts` per user/organization/provider; separat credential, cursor, rate-limit och per-konto policy. Förhindra cross-account data access genom Laravel Policies på varje read, job, AI tool och send.
- OAuth via systemwebbläsare/ASWebAuthenticationSession med PKCE, `state`, redirect allowlist och explicit scope consent; inga embedded webviews som samlar lösenord. Säker tokenförnyelse, återauktorisering och återkallning.
- Macens Keychain eller annan välgranskad krypterad secret store per tjänsteidentitet; aldrig credentials i vanliga databastabeller, PWA-localStorage, loggar, verktygsbeskrivningar eller AI-prompter.
- Read-only som grundläge. Skapa lokala utkast utan sändbehörighet; begär separat provider-scope och oföränderligt explicit approval för varje faktisk `send`, `reply`, `forward`, `delete`, `move`, `archive` eller annan muterande operation (om sådan införs). Signatur/avsändare ska bindas till valt konto; en agent får aldrig byta From-identitet.
- OAuth scopes hålls så smala som möjligt. Google restricted scopes, Microsoft tenant-admin consent och HEY/Apple-metoders faktisk tillgänglighet måste dokumenteras och testas före offentlig release.
- AI-analyser klassificeras per konto och följer Local Only/EU Restricted/Custom policy. Ingen dold moln-fallback. Prompt-injection från mejlinnehåll får aldrig kunna höja verktygsbehörigheter eller trigga utgående mejl.

**Synk och normalisering:**

- Datamodell: `email_connections`, `email_folders`, `email_messages`, `email_threads`, `email_attachments` (referenser), `email_sync_cursors`, `email_delivery_attempts`, `email_account_permissions`, `email_identity_mappings`. Unika index på `(connection_id, provider_message_id)` och motsvarande thread-ID. Källhänvisning till original för alla CRM-noteringar och briefs.
- Provider-specifik inkrementell sync: Gmail history IDs, Microsoft Graph delta queries där stödda, IMAP UID+UIDVALIDITY+modseq där tillgängligt, HEY CLI-kontrakt efter verifiering. Bootstrap-synk i paginerade batcher, backpressure, retry/exponential backoff, exponential-jitter, idempotens, rate limits, quota feedback, tombstones, dedupe och konfliktlösning. Polling som standard för lokal Mac; push/webhooks är opt-in med korrekt signerad ingress.
- HTML-sanitization, MIME/encoding, Unicode, stora brev, bilagor, inline images, spam/skräp, mail aliases och trådning (`Message-ID`/`In-Reply-To`/`References`) testas. Bilagor öppnas aldrig automatiskt som program eller körbar kod.
- CRM-matchning ska vara förklarlig, stödja flera kundkontakter med samma domän och manuell merge/split; aldrig anta att alla brev från en domän tillhör en kund.
- Unified Inbox kan filtrera på konto, bolag, kund, projekt, datum, status och **Needs Attention**; separera AI-förslag från verkligt utförda åtgärder. Daglig Executive Brief får sammanfatta alla behöriga anslutningar men ska visa källkonto för varje åtgärd.
- Brief-utskick sker från ett uttryckligen valt och testat sender-konto via dess officiella send API eller SMTP där tillämpligt. Ett samtycke till schemalagd automatisk briefdistribution gäller enbart dess valda mottagare och innehållstyp; inga övriga mail får skickas utan särskilt godkännande.

**Adapterkontrakt (designförslag, granska semantik mot faktiska SDK:er):**

```php
interface EmailConnector
{
    public function authorization(EmailConnectionDraft $draft): AuthorizationFlow;
    public function verify(EmailConnection $account): ConnectionHealth;
    public function sync(EmailConnection $account, ?SyncCursor $cursor): SyncBatch;
    public function folders(EmailConnection $account): iterable;
    public function getMessage(EmailConnection $account, string $externalId): EmailMessage;
    public function send(EmailConnection $account, ApprovedOutgoingEmail $approved): SendReceipt;
    public function disconnect(EmailConnection $account): void;
}
```

**Acceptanskriterier för e-post v1:**

1. Lägg till tre Google-konton, två Microsoft-konton, ett HEY och ett iCloud-konto i samma installation utan användarkonflikter; separat test för generic IMAP. Totalt minst åtta konton testas och körs samtidigt.
2. Anslutning via OAuth ska vara maximalt guidad och mobil/native-kompatibel; dokumentera admin-consent och manuella undantag. Ingen användare behöver Terminal för Gmail eller Microsoft.
3. Sync av minst 100 000 syntetiska mejl över åtta konton kan återupptas efter avbrott utan dubbletter eller dataförlust; respektera API-kvoter.
4. Ett mejl på konto A kan aldrig hämtas eller skickas från konto B via API, Livewire-action, agent, queue eller MCP (automatiserade negativa tester).
5. Token-expiry, revoke, nätavbrott och ändrade lösenord visas i UI med fungerande `Reconnect`, utan hemligheter i loggar.
6. CRM skapar spårbara åtaganden och kontaktmatchningar; användaren kan korrigera kopplingar och se ursprungligt meddelande.
7. En testad brief skickas exakt en gång genom explicit valt konto efter samtycke; menyrad och mejl återger samma version.
8. Varje send kräver korrekt policy, låst avsändare/mottagare/body och godkännande; mejlinjektion och felaktig From-identitet blockeras.
9. Installation utan anslutet mejlkonto fungerar fortfarande inom femminutersmålet; integrationswizard kan öppnas efter `core-ready`.
10. Lokal dataskyddspolicy verifieras med nätverkstest: mejl- och AI-innehåll skickas endast till tjänster som användaren valt och som krävs för anslutningen.

**Leveransansvar:** Integrationslead äger provider-adapters och verkliga testkonton; Laravel lead äger normaliserings-/synklagret; security lead äger token-, scope- och send-policy; TALL/Swift leads äger enhetligt onboardingflöde; QA äger cross-account- och återupptagningstester. Microsoft 365/Outlook är obligatoriskt för v1, inte ett senare tillval.

---

## 6. Peekaboo och macOS-native säkerhet

Peekaboo är **valfri** och ska **inte blockera installation eller CRM/AI-chat**. Ge endast agenten ett begränsat `ComputerUseProvider` med explicit app/window allowlist, user-visible session, per-action authorization, skärmbildsredaktion, timeouts och panic-stop. Tillåt endast den verifierade versionen från definierad releasekälla.

**Mac-grafik är sessionsberoende:** Skärmsläckning, låst skärm, logout och FileVault-start påverkar förmågan att styra appar. Designa automationen som en separat synligt aktiv uppgift som kan behöva låsa upp sessionen manuellt. Försök inte kringgå macOS TCC, låsskärm eller andra systemsäkerhetskontroller. Inga lösenord eller 2FA-koder får avläsas eller kringgås automatiskt.

Native helper design:

- SwiftUI frontend process, separat godkänd `launchd`-bakgrundstjänst för Laravel core och separat user-level UI automation helper där det behövs.
- Inga osignerade nedladdningar körs med adminbehörighet; verifiera vendor checksum och signatur.
- Helper kommunicerar över skyddad lokal IPC/Unix socket eller loopback med sessionsbunden token i Keychain. All IPC har versionering, origin check och request signing där relevant.
- Visa aktiv GUI-automation i menyrad, logga vilken app/window som användes utan att spara hemliga skärminnehåll i audit log.

---

## 7. MCP Manager

- Implementera registry discovery och sök i officiell MCP Registry (och valfria användarkonfigurerade källor), med URL, publisher, version, capabilities, transport, requested scopes, health, license och provenance.
- **Discover → Assess → Explain → Approve → Install/Connect → Grant tools → Audit → Revoke**.
- Fjärrservrar: HTTPS, TLS-validering, OAuth om stöds, host allowlist, inga okontrollerade redirects, explicit datadelning per verktyg.
- Lokala stdio-MCP-servrar: kör aldrig godtyckligt paket direkt på användarens Mac med fulla rättigheter. V1 stöder endast allowlistad, manuellt granskad lokal MCP-runtime med tydligt avgränsade OS-behörigheter. Generella tredjepartsinstallationer är feature-gated tills riktig process-sandbox verifierats.
- Tool poisoning/prompt injection: modellinstruktioner inuti `tool description`, webbsidor, mejl och MCP-svar är untrusted input. Policybeslut sker i kod, inte i prompt.
- Versionspinning, hashes, tools schema, install review, revoke and kill-process, egress controls där OS stöder; förklara kvarstående risker.
- Exponera egna begränsade Laravel MCP-verktyg, t.ex. `crm.search`, `tasks.list`, `brief.read`; muterande verktyg kräver action approvals.

---

## 8. Remote PWA: säker tunnel som valfritt steg

**Standard:** Local Only. **Rekommenderad remote-adapter:** Tailscale Serve (privat tailnet), inte Funnel. Kör lokalt HTTP på `127.0.0.1`, tunnel till en enda origin med giltigt HTTPS-certifikat och korrekta Laravel `APP_URL`, `TrustProxies`, secure cookies/CSRF/sessionkonfiguration. Tailscale app måste finnas på fjärrklienten. Testa iPhone hemskärms-PWA från mobilnät med Tailscale ansluten. Bekräfta tailnet ACL:er och att inte hela datorn exponeras.

Cloudflare kan vara valfri framtida adapter bakom Zero Trust, men **inte standard** i Europe-first-profilen. Inget öppet WAN-portkrav. Automatisk tunneluppsättning får aldrig i tysthet aktivera publik exponering.

**PWA Push:** tillåt som opt-in, med tidsbegränsade/minimala notifieringspayloads och ingen kundtext i klartext i externa pushmeddelanden. iOS push kräver en installerad hemskärms-PWA och relevanta behörigheter; verifiera Apple-plattformsbeteende.

---

## 9. Ström, drift och återhämtning på Mac

- Mac får släcka skärmen och låsa sessionen; Laravel, scheduler, tunnel och lokal inferens ska fortsätta om systemet är vaket.
- Installationsappen visar status för macOS-inställningen som förhindrar automatisk vila när skärmen är av; guidar användaren att ändra den. Använd endast godkända `pmset`-/macOS-behörighetsvägar när ett explicit administratörsgodkännande krävs. Undvik obegränsad sudo-helper.
- `caffeinate` kan användas för temporära jobb men inte som enda garanti för 24/7-drift.
- `launchd`/`SMAppService`: automatic launch, crash restart, bounded retries, health probes; tydligt om användarinloggning krävs.
- Internetavbrott: lokala funktioner fungerar, synkronisering köas; ingen molnfallback i Local Only.
- Efter strömavbrott: dokumentera FileVault unlock och GUI-session som möjliga manuella steg. Ingen falsk garanti om full unattended recovery.
- Backup: lokal krypterad backup + rekommenderad extern krypterad destination, retention, restore drills. SQLite snapshot med konsekvent checkpoint/backup API, inte blind live-file-copy.
- Updates: signerad update feed, delta om lämpligt, DB migration dry-run/backup, rollback av binaries, ingen automatisk rollback av inkompatibla schemaändringar utan plan.

---

## 10. Europe First: privacy, GDPR och EU AI Act

Genomför en juridiskt granskad **compliance mapping per roll och use case**; open source-distribution är inte ett generellt undantag. Dokumentera tillämpliga regler med **giltiga datum vid release**, inklusive eventuella ändringar i EU AI Act:s implementeringstidslinje. Teamet får inte anta att produktens tekniska inställningar automatiskt gör kunders användning lagenlig.

Funktionella krav:

1. Integritetslägen: `LOCAL_ONLY`, `EU_RESTRICTED`, `CUSTOM` — inga tysta policyöverträdelser.
2. Register över AI-modeller, leverantörer, dokumenterade behandlingsregioner och eventuella underbiträden; provenance för varje körning.
3. GDPR support: dataminimering, purpose limitation, consent/legal basis notering, datakälla, export, rättelse, radering, gallring, processors, incident log och åtkomstlogg.
4. AI-interaktioner ska tydligt vara märkta som AI; AI-genererat innehåll och automatiserade åtgärder markeras där regler och sammanhang kräver.
5. Risk-klassificering av användningsfall; förbjud specifika högrisk-/förbjudna arbetsflöden utan rätt juridiskt underlag; särskild guardrail för rekrytering/anställningsbeslut.
6. DPA/transfer impact/retention review för molnleverantörer och externa MCP-servrar, inklusive vilka data som lämnar EU. EU-hosting innebär inte automatiskt efterlevnad.
7. Testade backups, secrets, secure defaults, dependency SBOM, vulnerability handling och incident response.
8. Telemetry OFF som standard; frivillig diagnostik separeras från allt kundinnehåll.
9. Lokal modell betyder inte per automatik att modellens träningsdata, licens eller användning är fri från compliance-frågor; kontrollera modelllicenser och redistribution.

---

## 11. Security Gates (blockerande)

- Auth: passkeys/WebAuthn eller MFA, secure sessions, session revocation, device management och re-auth för kritiska åtgärder.
- Laravel authorization: policies/gates i varje HTTP action, Livewire action, queued job, MCP tool, AI tool och integration call; IDOR/multi-tenant cross-access tests.
- Secrets: Keychain per tjänsteidentitet där möjligt; aldrig tokens i app logs, crash dumps, prompts, push, screenshots, mailbrief eller repo.
- Prompt injection: all externt insamlad text betraktas som data, inte instruktion; hotmodellera indirect injection/exfiltration och browser-based attacks.
- SSRF: `Browser/Fetch` med DNS/IP-kontroller, blockera loopback, RFC1918, metadata- och Unix socket targets där de inte uttryckligen behövs; revalidate redirects, ports, protocols.
- MCP: capability allowlist, trust tier, isolate untrusted processes, revoke immediately, no arbitrary shell.
- Outbound actions: frozen immutable proposal, input/recipient/hash, approver identity, expiry, nonce, idempotency, fail closed. Mail send, CRM deletion, GitHub write och externa formulär alltid approval-driven.
- Supply chain: signed/notarized Mac artifacts, pinned Composer/npm/Swift deps, SBOM, SCA, code signing verification, reproducible build where feasible.
- Security testing: OWASP ASVS-aligned web checks, SAST, dependency scanning, DAST for HTTP endpoints, native entitlement review, third-party penetration test before v1.0.

---

## 12. Under 5 minuter: native zero-terminal installer

### 12.1 Installer state machine

`WELCOME → PREFLIGHT → PACKAGE_VERIFY → INSTALL_CORE → CREATE_OWNER → SELECT_AI → BOOT_SERVICES → HEALTH_CHECK → READY`

Separate optional path: `CONNECT_EMAIL → CONNECT_GITHUB → PEEKABOO_PERMISSIONS → REMOTE_ACCESS → SCHEDULE_BRIEF`.

**Preflight**: macOS >= supported, Apple Silicon, disk/RAM checks, permissions, port collisions, network state, possible existing install, FileVault state, trusted developer signature. Never ask user to paste shell commands to finish normal setup.

**Bundle design**: native Swift app in signed/notarized DMG; bootstrap helper installs an app-owned, immutable, versioned runtime to Application Support, not user-owned random directories. Separate mutable user data directory with managed backups. Installer progress must reflect real checkpoints, support cancel/resume and rollback, and show actionable errors.

**No hidden prerequisites**: PHP/runtime included; SQLite built-in; queue database-backed; no Homebrew, Docker, MySQL, Redis or external domain required to open CRM/PWA. If local AI is requested, either reuse already installed Ollama/LM Studio or offer a tested small local model download as optional async step. Local CLI dependencies and GitHub/Google OAuth occur later. If signed packaging of PHP/runtime proves legally or technically impractical, escalate at architecture review rather than silently requiring Homebrew.

### 12.2 Mätbara gates

- Mac A (clean M4 Max, 36–64 GB), Mac B (clean M3 Ultra), both with supported macOS versions; at least 30 measured installs per profile.
- `p50 < 180s`, `p95 < 300s` **core-ready**; include DMG open, user prompts and runtime initialization, but label network-conditional AI setup separately.
- No required terminal use; no admin password until an OS-level protected operation genuinely needs it.
- Install/offline/core without external LLM account: PWA and CRM operational with "Select AI" banner; **do not count as AI-ready**.
- `ready` requires HTTP health, migrations, login, menu integration, local brief placeholder, background worker and scheduler self-test. AI-ready requires successful model completion.
- 100% fresh install, re-install, upgrade, failed upgrade, interrupted install, uninstall and data-preserving reinstall e2e coverage.

### 12.3 First-run screens (strict)

1. **Welcome**: product ethos + 1 CTA.
2. **Check**: automatic preflight + disk estimate; show blockers only.
3. **Install**: progress with substeps, no command line.
4. **Create account**: local owner + credential/passkey (where supported).
5. **Choose AI**: neutral options, detect installed local inference runtime and suggest compatible choices *without preselecting brand*.
6. **Done**: launch PWA, menu bar, follow-up checklist for optional connections.

Lägg aldrig till >6 obligatoriska interaktionssteg utan ADR + mätning.

---

## 13. Teamorganisation och parallella arbetsströmmar

**Program owner / Staff Architect**: äger scope, ADR, interfaces, threat model, release blockers och integrationsordning. Varje workstream får en DRI och minst en granskare från annat team.

| Team / DRI | Leverans | Beroenden | Definition of Done |
|---|---|---|---|
| A. macOS/Swift lead | Installer, MenuBarExtra, signed/notarized DMG, SMAppService, power, updater | Core health IPC | Fresh install + menu brief + crash recovery tested |
| B. Laravel platform lead | Laravel runtime, SQLite, job queue, scheduler, API, backup, health | Contracts from A | Core install robust with no extra package manager |
| C. TALL/PWA design lead | Design system, assistant UI, PWA, admin, offline, accessibility | Auth + briefs contracts | iPhone/Mac PWA passes Lighthouse & manual UX |
| D. AI/Agents lead | provider-neutral adapters, local models, planning, grounded brief, memory | Data models + policies | Deterministic eval set and no cloud leak in Local Only |
| E. CRM lead | customers, contacts, comms, projects, tasks, migrations/import | Auth/tenancy | CRUD + timeline + export + source traceability |
| F. Integrations lead | Google/Gmail multi-account, Microsoft 365/Outlook, iCloud, HEY, IMAP/SMTP, valfri Proton Bridge, GitHub xN, unified inbox/email sender | Secrets + normalized data | Fixtures, retries, revoke, idempotent sync |
| G. MCP/Automation lead | MCP manager, trusted runtime, Peekaboo, session UI | Policy engine + Mac helper | Approved GUI tasks only, cancel and audit |
| H. Security/privacy lead | threat model, authz, secret store, GDPR/AI Act mapping, pentest | Cross-cutting | All security gates green; signed risk acceptance |
| I. QA/release lead | CI, install performance lab, benchmarks, recovery tests | All | Signed release candidate meets acceptance metrics |
| J. Docs/community lead | README, installer guide, licensing, contribution templates, accessibility | Product state | Outside contributor can install and reproduce tests |

### 13.1 Arbetsordning / kritisk väg

**Sprint 0 — Architecture freeze:** ADRs for runtime/DB, macOS signing, secret model, tunnel, model providers, compliance boundaries, licensing; Figma/design tokens; JSON/API contracts; fixture pack; baseline threat model; working empty shell of Swift + Laravel. Exit: all DRIs approve contracts.

**Sprint 1 — Vertical slice:** signed debug Mac app installs Laravel bundle, runs SQLite migration, creates owner, launches PWA and menu bar, renders one sample brief from a single JSON source. No external integration. Exit: first clean install measured.

**Sprint 2 — Real core:** Local CRM CRUD, auth/MFA, assistant chat with local Ollama or user-selected provider, database queue/scheduler, real brief generation with fixtures, mail delivery preview. Exit: security checks and brief identity.

**Sprint 3 — Sources and automation:** multi-Google, Microsoft Graph, iCloud, HEY, generic IMAP/SMTP, GitHub App, unified inbox och source normalization; MCP discovery, read-only tools; Peekaboo sandbox/approval/session controls. Exit: no cross-account leakage; stop button works.

**Sprint 4 — Production candidate:** Tailscale, native upgrades, backups, recovery, performance tuning, EU data controls, onboarding polish, accessibility, complete docs, notarization, automated install matrix, third-party review. Exit: verified release gates.

Parallel teams ship vertical slices with shared fixtures. Do not wait to connect all integrations before running end-to-end tests.

### 13.2 GitHub execution rules

- Monorepo, protected `main`, required CI, 2 reviewers for security-critical modules, CODEOWNERS.
- Every PR references issue, ADR where architectural, includes tests, security impact, screenshot or recording for UI changes.
- Conventional commits/semantic versioning for published releases; changelog and signed tag.
- No giant "AI agent builds everything" PR. Break changes into reviewable PRs with tests and reproducible behavior.
- Public issues separated from private vulnerability reports; `SECURITY.md` disclosure instructions.

---

## 14. Tester, observability och release acceptance

**Required automated suites:** PHPUnit/Pest (choose one), Laravel feature + authorization, browser E2E (tests may use Playwright internally even if product does not depend on Playwright), Swift XCTest/UI tests, IPC tests, synthetic tool transcripts, offline/online transitions, encryption/secret tests, large-mailbox pagination, multi-account isolation, Microsoft Graph delta/consent, Google OAuth, HEY auth, iCloud app-specific-password onboarding, GitHub rate limits, IMAP retries, MCP hostile-server fixtures, prompt-injection red-team corpus, installer interrupted state recovery.

**Required performance gates:** 5-minute core install; p95 API latency defined per endpoint; bounded model context; no brief generation on every menu open; low idle CPU/memory; memory benchmark by Mac RAM tier; no battery issue (Mac desktop only); native menu opens instantly from cached brief.

**Required security/privacy gates:** default deny outbound mutating tools; no GoZero Core/Basecamp implementation; telemetry opt-in; locality test with packet capture under Local Only; generated brief no private text in push; source citations; approved SMTP recipient only; tenant-crossing negative tests; backup + restore validated.

**Observability:** minimal structured local logs, scrubbed traces, user-visible task history, health monitor, model/provider usage and cost, credentials expiry, sync lag, stale brief status, PWA reachability, Peekaboo permission status. Diagnostic export must be sanitized and require user action.

**Release checklist:** Apple Developer signing, notarization/staple, clean-machine Gatekeeper test, SBOM + license audit, version manifest, data migration & rollback drill, test matrix published, README installation video, security contact, changelog, checksum and hashes published.

---

## 15. Explicitly unresolved decisions — owners must close in Sprint 0

1. Support matrix: macOS 15/26 and supported Mac chips; actual minimum RAM/disk.
2. PHP and HTTP server packaging/update/legal constraints and native service lifecycle.
3. SQLite FTS5 vs optional MySQL; Keychain service access and whether app sandboxing is feasible given Peekaboo.
4. MCP client transport and exact officially supported APIs/version from Laravel at implementation time.
5. Peekaboo Mac permissions, supported UI-session scenarios, safe app/window allowlist and helper signing model.
6. Local inference choices and quantized model license + installation-time download size.
7. Google OAuth multi-tenant/public-app verification strategy and restricted Gmail scopes.
8. PWA origin/secure cookie/push and Tailscale Serve ACL/tested macOS Tailscale distribution.
9. Brief default schedule/timezone, SMTP sender verification and send-consent.
10. MIT vs alternative open source license; legal review of bundled executables and third-party model licenses.

Unresolved is not permission to guess. Each needs an ADR, measured prototype or vendor-doc citation.

---

## 16. Teamets första 48 timmar

1. Skapa GitHub-monorepo med license/README/security/CI/CODEOWNERS och issue labels `macos`, `laravel`, `pwa`, `ai`, `crm`, `integrations`, `mcp`, `security`, `compliance`, `release`.
2. Skriv och godkänn ADR-001 macOS runtime packaging, ADR-002 local DB/queue, ADR-003 Keychain/secrets, ADR-004 provider-neutral AI, ADR-005 Peekaboo MCP security, ADR-006 private remote access, ADR-007 license/compliance.
3. Skapa SwiftUI-menyrad med hardcoded fixture och samma fixture renderad i Laravel Blade HTML mail samt PWA; bevisa semantisk likhet genom snapshot tests.
4. Skapa minimal Laravel 13 app med SQLite, auth, `/health/live`, `/health/ready`, `briefs` migrations, database queue/scheduler och install-state marker.
5. Sätt upp signerad intern Mac build från CI; verifiera launchd process management, kill/restart och avinstallation på ren Mac.
6. Skapa install-timer runner som mäter varje obligatorisk fas och exporterar JSON-resultat.
7. Skapa testfall för negativt säkerhetsflöde: främmande mejl begär att agenten ska skicka kunddata → systemet vägrar.
8. Bygg en enda vertical slice: *Install → Create owner → Open PWA → Generate seeded brief → Show same brief in menu bar → Preview exact email*.
9. Först när denna slice är stabil får teamet parallellt aktivera externa integrationer, Peekaboo och MCP.

## 17. Definition of Done för v1.0

- [ ] Mac-only produkt distribuerad som signerad/notariserad app med UX av premiumkvalitet.
- [ ] Verifierat installerbar core på <5 minuter p95 enligt transparent testprotokoll.
- [ ] Native installer, menu bar brief, crash recovery och auto-updater fungerar.
- [ ] TALL Stack PWA och egen admin utan Filament; lokalt CRM användbart offline från externa API:er.
- [ ] Neutral AI-onboarding, fungerande lokal AI och minst två valfria externa AI-adaptrar.
- [ ] Ingen GoZero Core och ingen Basecamp i kod, UI eller defaultkonfiguration.
- [ ] Synk till relevanta valfria mejlkonton och GitHub-organisationer fungerar med rollback/revoke.
- [ ] En versionsmärkt brief visas identiskt i PWA, menyrad och e-post.
- [ ] Peekaboo och MCP installerbara som opt-in och skyddade av verifierad tillståndsmodell.
- [ ] Tailscale Serve remote-PWA testad från iPhone utanför LAN; ingen publik exponering i default.
- [ ] Local Only har inga utgående LLM-förfrågningar eller tysta fallbacks.
- [ ] GDPR/AI Act-use-case assessment och säkerhetstester dokumenterade, utan falska certifieringspåståenden.
- [ ] Backups, restore, updates, uninstall/data retention och incidentvägar testade.
- [ ] Extern utvecklare kan bygga från källkod enligt README och bidra via CONTRIBUTING.

---

## 18. Officiella referenser att låsa mot under implementation

- Laravel AI SDK: https://laravel.com/docs/13.x/ai-sdk
- Laravel MCP: https://laravel.com/ai/mcp
- Peekaboo: https://github.com/openclaw/Peekaboo
- Apple `SMAppService`: https://developer.apple.com/documentation/servicemanagement/smappservice
- Tailscale Serve: https://tailscale.com/docs/features/tailscale-serve
- Tailscale Serve CLI: https://tailscale.com/docs/reference/tailscale-cli/serve
- GDPR: https://eur-lex.europa.eu/eli/reg/2016/679/oj
- EU AI Act: https://eur-lex.europa.eu/eli/reg/2024/1689/oj
- MCP specification: https://modelcontextprotocol.io/specification

> **Final mandate:** Bygg en tillförlitlig personlig sekreterare, inte en generisk agentdemonstration. Produktens konkurrensfördel är **installera på Mac → välj AI → få användbar brief → agera tryggt**, med öppna, granskbara integrationer och lokala data. Mät installationen; kompromissa aldrig med säkerhet för att artificiellt nå fem minuter.
