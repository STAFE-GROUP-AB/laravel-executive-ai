<?php

declare(strict_types=1);

use App\Briefs\BriefContentHash;
use App\Briefs\BriefDocumentMapper;
use App\Briefs\Render\BriefMailable;
use App\Briefs\Render\BriefMailRenderer;
use App\Briefs\Render\BriefParityPayload;
use App\Console\Commands\OwnerCreateCommand;
use App\Livewire\Auth\Login;
use App\Livewire\Install\CreateOwnerForm;
use App\Models\Brief;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\User;
use App\Security\Authorization\CreateOwner;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Health\AiCompletionSuccessMarker;
use App\Support\Health\HealthService;
use App\Support\Health\QueueWorkerHeartbeat;
use App\Support\Install\InstallState;
use App\Support\Install\InstallStateStore;
use App\Support\Json\Jcs;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\Support\BriefFixtures;
use Tests\Support\FailingMailTransport;
use Tests\Support\FreshInstance;
use Tests\Support\MenuBarParityExport;
use Tests\Support\PwaFixtures;
use Tests\Support\VerticalSliceReport;

/*
 * S1-15, blueprint §16 step 8: *Install → Create owner → Open PWA → Generate
 * seeded brief → Show same brief in menu bar → Preview exact email*, as one
 * run against a freshly installed instance. §16 step 9 makes a green run of
 * this file the gate before any external integration, Peekaboo or MCP work
 * starts (docs/operations/vertical-slice.md).
 *
 * "Freshly installed" is literal: a second application is booted through the
 * real bootstrap/app.php against a throwaway LEA_DATA_DIR and migrated from
 * nothing, the way the Supervisor's first core start does. Nothing is seeded
 * through the suite's own database. §13.1: no external integration, no model
 * call (`ai_ready` stays false), no third-party account; outbound HTTP throws.
 *
 * The brief is the frozen canonical fixture — the "single JSON source" of the
 * Sprint 1 exit (§13.1) — so its `version` and `content_hash` are known in
 * advance and the menu bar's export of it already exists: the committed
 * snapshot in the `php` lane, the live Swift export in the `parity` lane
 * (brief-parity.md §3.2). The report the run writes is read by
 * packaging/fixtures/e2e/vertical-slice.mjs.
 */

const SLICE_INSTALL_AT = '2026-10-09T05:00:00+02:00';

const SLICE_OWNER_EMAIL = 'owner@example.test';

/** The clock of the install (before the fixture's 05:30 generation) and of the brief (PwaFixtures::FIXTURE_NOW). */
function sliceClock(string $at): CarbonImmutable
{
    $now = CarbonImmutable::parse($at);
    Carbon::setTestNow($now);

    return $now;
}

/** @return array<string, mixed> */
function sliceJson(TestResponse $response): array
{
    return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

/** The owner's session: the suite helpers act on the suite's app, so the guard of the booted core is used directly. */
function sliceOwner(): User
{
    return User::query()->where('email', SLICE_OWNER_EMAIL)->firstOrFail();
}

/** @return array<string, mixed> The /health/ready body of the booted core, evaluated fresh (the 5 s cache never expires under a frozen clock). */
function sliceReady(Application $app, int $expectedStatus): array
{
    $app->make(HealthService::class)->forgetCached();

    $response = FreshInstance::ipc($app, 'GET', '/health/ready', null, ['client' => 'supervisor']);

    expect($response->getStatusCode())->toBe($expectedStatus, '/health/ready status');

    return sliceJson($response);
}

/** What BOOT_SERVICES brings up: the menu bar's heartbeat, one worker loop and the scheduler self-test. */
function sliceBootServices(Application $app): void
{
    QueueWorkerHeartbeat::resetThrottle();

    $console = $app->make(ConsoleKernel::class);

    expect($console->call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]))->toBe(0, 'queue:work --stop-when-empty')
        ->and($console->call('health:selftest'))->toBe(0, 'health:selftest');

    FreshInstance::ipc($app, 'POST', '/ipc/v1/heartbeat', [
        'client' => 'menubar',
        'client_version' => '1.0.0 (1)',
        'contract_version' => '1.0.0',
        'panel_open' => false,
        'since' => CarbonImmutable::now()->format('Y-m-d\TH:i:sP'),
    ])->assertOk();
}

/** @return array{document_id: string, version: int, content_hash: string} */
function sliceIdentity(string $parityJson): array
{
    $payload = json_decode(rtrim($parityJson, "\n"), true, 512, JSON_THROW_ON_ERROR);

    return ['document_id' => (string) $payload['id'], 'version' => (int) $payload['version'], 'content_hash' => (string) $payload['content_hash']];
}

afterEach(function (): void {
    Carbon::setTestNow();
    FailingMailTransport::reset();
});

it('installs, creates the owner, opens the PWA, seeds a brief and shows the same version in the menu bar and the exact email', function (): void {
    $instance = FreshInstance::create();
    $report = new VerticalSliceReport(MenuBarParityExport::oracle());
    $fixture = BriefFixtures::load(BriefFixtures::CANONICAL);

    try {
        $instance->boot(function (Application $app) use ($instance, $report, $fixture): void {
            // §13.1 at the process boundary: any outbound HTTP request throws, and no mail may
            // leave through anything but the in-memory mailers (asserted at the end).
            $app->make(HttpClientFactory::class)->preventStrayRequests();

            $mailers = $app->make(MailManager::class);
            $mailers->extend('failing', static fn (): FailingMailTransport => new FailingMailTransport);
            FailingMailTransport::reset();

            // ── 1. Install ────────────────────────────────────────────────────────────────
            $installedAt = sliceClock(SLICE_INSTALL_AT);

            $report->step('install', function () use ($app, $instance): array {
                expect($app->make(DatabaseManager::class)->connection()->getDatabaseName())->toBe($instance->databasePath(), 'the core runs on <LEA_DATA_DIR>/database/executive.sqlite')
                    ->and(File::exists($instance->databasePath()))->toBeTrue()
                    ->and($app->make(InstallStateStore::class)->current())->toBe(InstallState::NotInstalled)
                    ->and($app->make(CreateOwner::class)->ownerExists())->toBeFalse()
                    ->and(Brief::query()->count())->toBe(0);

                // health-contract.md §2: a migrated core without an owner is not ready, same body shape.
                $ready = sliceReady($app, 503);
                $byName = array_column($ready['checks'], null, 'name');

                expect($ready['ready'])->toBeFalse()
                    ->and($ready['ai_ready'])->toBeFalse()
                    ->and($ready['brief_state'])->toBe('none')
                    ->and($byName['database_migrations']['status'])->toBe('ok')
                    ->and($byName['auth_login']['detail'])->toContain('no owner account')
                    ->and($byName['brief_placeholder']['status'])->toBe('fail');

                // The in-app CREATE_OWNER screen is the only page; /login hands over to it.
                FreshInstance::request($app, 'GET', '/install/owner')->assertOk()->assertSee('Create the owner account')->assertDontSee('type="password"', false);
                FreshInstance::request($app, 'GET', '/login')->assertRedirect(FreshInstance::ORIGIN.'/install/owner');
                FreshInstance::request($app, 'GET', '/home')->assertRedirect(FreshInstance::ORIGIN.'/login');

                return [
                    'data_dir' => '<temp>/'.basename($instance->dataDirectory),
                    'database' => 'database/executive.sqlite',
                    'migrations_pending' => 0,
                    'install_state' => InstallState::NotInstalled->value,
                    'health_ready' => false,
                    'health_status' => 503,
                ];
            });

            // ── 2. Create owner ───────────────────────────────────────────────────────────
            $report->step('create_owner', function () use ($app, $installedAt): array {
                // §12.3 screen 4 in the PWA: the real form, which signs the installer in.
                Livewire::test(CreateOwnerForm::class)
                    ->set('name', 'Ada Owner')
                    ->set('email', SLICE_OWNER_EMAIL)
                    ->set('organization', 'Example Organization')
                    ->set('timezone', 'Europe/Stockholm')
                    ->set('language', 'sv')
                    ->call('create')
                    ->assertHasNoErrors()
                    ->assertRedirect(FreshInstance::ORIGIN.'/home');

                $owner = sliceOwner();

                expect($app->make('auth')->guard('web')->id())->toBe($owner->id, 'the installer is signed in')
                    ->and(Membership::query()->where('role', MembershipRole::Owner->value)->count())->toBe(1)
                    ->and($app->make(InstallStateStore::class)->current())->toBe(InstallState::Installing);

                // T-72: no second owner, through either path.
                FreshInstance::request($app, 'GET', '/install/owner')->assertNotFound();

                $console = $app->make(ConsoleKernel::class);
                $exitCode = $console->call('lea:owner:create', ['--name' => 'Second Person', '--email' => 'second@example.test', '--organization' => 'Another Organization', '--json' => true]);

                expect($exitCode)->toBe(OwnerCreateCommand::OWNER_EXISTS)
                    ->and(Membership::query()->where('role', MembershipRole::Owner->value)->count())->toBe(1)
                    ->and(User::query()->count())->toBe(1);

                // The PWA before any brief exists: the empty state, no claim of a brief.
                FreshInstance::request($app, 'GET', '/briefs')->assertOk()->assertSee('No briefs yet')->assertDontSee('data-brief-row', false);
                FreshInstance::request($app, 'GET', '/home')->assertOk()->assertSee('No brief yet')->assertDontSee('data-latest-brief', false);

                // INSTALL_CORE's placeholder, BOOT_SERVICES, then HEALTH_CHECK → READY (installer.md §1).
                expect($console->call('lea:brief:placeholder'))->toBe(0);

                $placeholder = Brief::query()->latestGenerated()->firstOrFail();

                expect($placeholder->isPlaceholder())->toBeTrue()
                    ->and($placeholder->generated_at->equalTo($installedAt))->toBeTrue()
                    ->and(BriefContentHash::matches($app->make(BriefDocumentMapper::class)->export($placeholder)))->toBeTrue();

                sliceBootServices($app);

                $ready = sliceReady($app, 200);

                expect($ready['ready'])->toBeTrue()
                    ->and(array_column($ready['checks'], 'status'))->toBe(array_fill(0, 7, 'ok'))
                    ->and($ready['ai_ready'])->toBeFalse()
                    ->and($ready['ai_state'])->toBe('not_configured')
                    ->and($ready['latest_brief']['id'])->toBe($placeholder->document_id)
                    ->and($ready['brief_state'])->toBe('fresh');

                expect($console->call('lea:install-state', ['state' => InstallState::CoreReady->value, '--reason' => 'HEALTH_CHECK']))->toBe(0)
                    ->and($app->make(InstallStateStore::class)->current())->toBe(InstallState::CoreReady)
                    ->and($app->make(InstallStateStore::class)->history()->map(fn ($transition): string => $transition->reason)->all())->toBe(['CREATE_OWNER', 'HEALTH_CHECK']);

                return [
                    'owner_email' => SLICE_OWNER_EMAIL,
                    'owners' => 1,
                    'second_owner_exit_code' => $exitCode,
                    'install_owner_after' => 404,
                    'placeholder_brief' => $placeholder->document_id,
                    'health_ready' => true,
                    'install_state' => InstallState::CoreReady->value,
                ];
            });

            // ── 3. Open PWA ───────────────────────────────────────────────────────────────
            $report->step('open_pwa', function () use ($app, $mailers): array {
                // The installer's session ends; the owner comes back through the one-time code.
                FreshInstance::request($app, 'POST', '/logout')->assertRedirect(FreshInstance::ORIGIN.'/login');
                FreshInstance::request($app, 'GET', '/home')->assertRedirect(FreshInstance::ORIGIN.'/login');
                FreshInstance::request($app, 'GET', '/login')->assertOk()->assertSee('Email me a code')->assertDontSee('type="password"', false);

                $transport = $mailers->mailer('array')->getSymfonyTransport();
                expect($transport)->toBeInstanceOf(ArrayTransport::class)
                    ->and($app->make('config')->get('mail.default'))->toBe('array');
                assert($transport instanceof ArrayTransport);

                $login = Livewire::test(Login::class)->set('email', SLICE_OWNER_EMAIL)->call('sendCode')->assertHasNoErrors()->assertSet('step', 'code');

                expect($transport->messages())->toHaveCount(1, 'exactly one code mail');

                $email = $transport->messages()->first()->getOriginalMessage();
                assert($email instanceof Email);

                expect($email->getTo()[0]->getAddress())->toBe(SLICE_OWNER_EMAIL)
                    ->and($email->getSubject())->not->toContain('code:')
                    ->and(preg_match('/\b(\d{6})\b/', (string) $email->getTextBody(), $matches))->toBe(1, 'the mail carries the six-digit code');

                $login->set('code', $matches[1])->call('verify')->assertHasNoErrors()->assertRedirect(FreshInstance::ORIGIN.'/home');

                $owner = sliceOwner();

                expect($app->make('auth')->guard('web')->id())->toBe($owner->id)
                    ->and($owner->login_count)->toBe(1);

                $home = FreshInstance::request($app, 'GET', '/home')->assertOk()->assertSee('Signed in as')->assertSee('data-latest-brief', false);
                $briefs = FreshInstance::request($app, 'GET', '/briefs')->assertOk();

                expect(PwaFixtures::xpath((string) $briefs->getContent())->query('//tr[@data-brief-row]')->length)->toBe(1, 'the placeholder is the only version')
                    ->and(PwaFixtures::aiOnPhoneClaims((string) $home->getContent()))->toBe([]);

                // The one page the service worker precaches, served with no session at all.
                $offline = FreshInstance::request($app, 'GET', '/offline')->assertOk()->assertSee('You are offline');

                expect($offline->headers->get('Set-Cookie'))->toBeNull();

                return ['login' => 'otp', 'code_mails' => 1, 'home' => 200, 'briefs' => 200, 'offline' => 200, 'brief_rows' => 1];
            });

            // ── 4. Generate seeded brief ──────────────────────────────────────────────────
            $generatedAt = sliceClock(PwaFixtures::FIXTURE_NOW);
            $mapper = $app->make(BriefDocumentMapper::class);

            $parityJson = '';

            $seeded = $report->step('generate_seeded_brief', function () use ($app, $mapper, $fixture, $report, &$parityJson): array {
                $organization = sliceOwner()->memberships()->firstOrFail()->organization;
                $brief = $mapper->import($fixture, $organization, Provenance::job('VerticalSlice', ProvenanceOrigin::Import));
                $brief = $brief->fresh() ?? $brief;
                $export = $mapper->export($brief);

                // Deterministic by construction: the stored brief round-trips to the fixture's own hash.
                expect($brief->content_hash)->toBe($fixture['content_hash'])
                    ->and(BriefContentHash::compute($export))->toBe($fixture['content_hash'])
                    ->and($brief->version)->toBe(1)
                    ->and(Brief::query()->count())->toBe(2);

                $page = FreshInstance::request($app, 'GET', "/briefs/{$brief->id}")->assertOk();
                $xpath = PwaFixtures::xpath((string) $page->getContent());
                $article = $xpath->query('//article[@data-brief]')->item(0);
                assert($article instanceof DOMElement);

                $parity = FreshInstance::request($app, 'GET', "/briefs/{$brief->id}/parity.json")->assertOk();
                $parityJson = (string) $parity->getContent();
                $expected = BriefParityPayload::canonicalJson(BriefParityPayload::fromDocument($fixture));

                expect($parityJson)->toBe($expected, 'the PWA exports the fixture projection')
                    ->and($article->getAttribute('data-brief-version'))->toBe('1')
                    ->and($article->getAttribute('data-brief-document-id'))->toBe($brief->document_id)
                    ->and($article->getAttribute('data-brief-content-hash'))->toBe($fixture['content_hash'])
                    ->and($article->getAttribute('data-parity-hash'))->toBe(Jcs::sha256(BriefParityPayload::fromDocument($fixture)))
                    ->and($parity->headers->get('X-LEA-Parity-Hash'))->toBe($article->getAttribute('data-parity-hash'))
                    ->and($page->getContent())->toContain($fixture['subject']);

                FreshInstance::request($app, 'GET', '/home')->assertOk()->assertSee($fixture['subject'])->assertSee('data-brief-document-id="'.$brief->document_id.'"', false);

                $identity = sliceIdentity($parityJson);
                $report->brief($brief->document_id, $identity['version'], (string) $fixture['generated_at'], $identity['content_hash'], 'packaging/fixtures/briefs/brief-canonical.json');
                $report->surface('pwa', $identity['version'], $identity['content_hash'], ['route' => "/briefs/{$brief->id}/parity.json", 'parity_hash' => $article->getAttribute('data-parity-hash')]);

                return [
                    'brief_row_id' => $brief->id,
                    'document_id' => $brief->document_id,
                    'version' => $identity['version'],
                    'content_hash' => $identity['content_hash'],
                    'parity_bytes' => strlen($parityJson),
                    'briefs_in_store' => 2,
                ];
            });

            $seeded['parity_json'] = $parityJson;

            // ── 5. Show same brief in menu bar ────────────────────────────────────────────
            $report->step('show_in_menu_bar', function () use ($app, $seeded, $report, $generatedAt): array {
                // The 30 s refresh loop (menubar.md §3): health → briefs/latest when the ETag moved.
                sliceBootServices($app);

                $ready = sliceReady($app, 200);

                expect($ready['ready'])->toBeTrue()
                    ->and($ready['ai_ready'])->toBeFalse()
                    ->and($ready['latest_brief'])->toBe([
                        'id' => $seeded['document_id'],
                        'version' => 1,
                        'generated_at' => CarbonImmutable::parse((string) $ready['latest_brief']['generated_at'])->toRfc3339String(),
                        'stale_after' => $ready['latest_brief']['stale_after'],
                    ])
                    ->and(CarbonImmutable::parse((string) $ready['latest_brief']['generated_at'])->equalTo(CarbonImmutable::parse('2026-10-09T05:30:00+02:00')))->toBeTrue()
                    ->and($ready['brief_state'])->toBe('fresh')
                    ->and($generatedAt->lessThan(CarbonImmutable::parse((string) $ready['latest_brief']['stale_after'])))->toBeTrue();

                $etag = "\"{$seeded['document_id']}:1\"";
                $latest = FreshInstance::ipc($app, 'GET', '/ipc/v1/briefs/latest')->assertOk();
                $document = sliceJson($latest)['data'];

                expect($latest->headers->get('ETag'))->toBe($etag)
                    ->and($document['id'])->toBe($seeded['document_id'])
                    ->and($document['version'])->toBe(1)
                    ->and($document['content_hash'])->toBe($seeded['content_hash']);

                // (a) In-process: the projection the menu bar renders from, over the document it
                // actually fetched, is byte-identical to what the PWA exported.
                $menuBarJson = BriefParityPayload::canonicalJson(BriefParityPayload::fromDocument($document));

                expect($menuBarJson)->toBe($seeded['parity_json'], 'the menu bar projection of /ipc/v1/briefs/latest differs from the PWA export');

                // (b) The real SwiftUI port: the committed snapshot in the php lane, the live export
                // of BriefParitySnapshotTests in the parity lane (brief-parity.md §3.2).
                $oracle = MenuBarParityExport::oracle();
                $swift = MenuBarParityExport::read(BriefFixtures::CANONICAL, $oracle);

                expect($swift)->toBe($seeded['parity_json']."\n", "the menu bar ({$oracle}) export differs from the PWA export of the seeded brief");

                FreshInstance::ipc($app, 'GET', '/ipc/v1/briefs/latest', null, ['headers' => ['If-None-Match' => $etag]])->assertStatus(304);

                $identity = sliceIdentity($swift);
                $report->surface('menu_bar', $identity['version'], $identity['content_hash'], ['oracle' => $oracle, 'ipc_etag' => $etag]);

                return [
                    'health_latest_brief' => $ready['latest_brief']['id'],
                    'ipc_etag' => $etag,
                    'ipc_not_modified' => 304,
                    'oracle' => $oracle,
                    'version' => $identity['version'],
                    'content_hash' => $identity['content_hash'],
                ];
            });

            // ── 6. Preview exact email ────────────────────────────────────────────────────
            $report->step('preview_email', function () use ($app, $mailers, $seeded, $report): array {
                // From here on the default mailer throws on send; the OTP mail already left.
                $arrayTransport = $mailers->mailer('array')->getSymfonyTransport();
                assert($arrayTransport instanceof ArrayTransport);

                $app->make('config')->set('mail.default', 'failing');
                $app->forgetInstance('mailer');

                $brief = Brief::query()->findOrFail($seeded['brief_row_id']);
                $rendered = $app->make(BriefMailRenderer::class)->renderBrief($brief);

                $html = FreshInstance::request($app, 'GET', "/briefs/{$brief->id}/preview/mail")
                    ->assertOk()
                    ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
                    ->assertHeader('X-LEA-Brief-Preview', 'nothing-sent');
                $text = FreshInstance::request($app, 'GET', "/briefs/{$brief->id}/preview/text")
                    ->assertOk()
                    ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
                    ->assertHeader('X-LEA-Brief-Preview', 'nothing-sent');

                $versionLabel = __('brief.version', ['version' => $rendered->version()], $rendered->locale);
                $hashShort = substr($rendered->contentHash(), 0, 7 + 12);

                expect($html->getContent())->toBe($rendered->html, 'GET /briefs/{brief}/preview/mail is not the renderer output')
                    ->and($text->getContent())->toBe($rendered->text, 'GET /briefs/{brief}/preview/text is not the renderer output')
                    ->and($rendered->parityJson())->toBe($seeded['parity_json'], 'the mail payload differs from the PWA export')
                    ->and($rendered->version())->toBe($seeded['version'])
                    ->and($rendered->contentHash())->toBe($seeded['content_hash'])
                    ->and($rendered->html)->toContain($versionLabel)
                    ->and($rendered->html)->toContain($hashShort)
                    ->and(preg_replace('/\s+/u', ' ', $rendered->text))->toContain($versionLabel);

                // The installer's / the slice's file preview: lea:brief:preview writes the same bytes.
                $out = storage_path('framework/testing/vertical-slice-'.bin2hex(random_bytes(4)));
                $console = $app->make(ConsoleKernel::class);

                try {
                    expect($console->call('lea:brief:preview', ['brief' => $brief->document_id, '--out' => $out]))->toBe(0)
                        ->and($console->output())->toContain('Nothing was sent.');

                    $base = "{$out}/brief-{$brief->document_id}-v1";

                    expect(File::get("{$base}.html"))->toBe($rendered->html)
                        ->and(File::get("{$base}.txt"))->toBe($rendered->text)
                        ->and(File::get("{$base}.parity.json"))->toBe($seeded['parity_json']."\n");
                } finally {
                    File::deleteDirectory($out);
                }

                // The exact message Sprint 2's delivery job will hand to the sender account, built
                // through the `preview` mailer (array transport): both parts are the renderer's bytes.
                $preview = $mailers->mailer('preview');
                $previewTransport = $preview->getSymfonyTransport();
                assert($previewTransport instanceof ArrayTransport);

                $preview->to(SLICE_OWNER_EMAIL)->send(new BriefMailable($rendered, $brief->idempotency_key, SLICE_OWNER_EMAIL));

                $captured = $previewTransport->messages()->first()->getOriginalMessage();
                assert($captured instanceof Email);

                expect($previewTransport->messages())->toHaveCount(1)
                    ->and($captured->getSubject())->toBe($rendered->subject)
                    ->and($captured->getHtmlBody())->toBe($rendered->html)
                    ->and($captured->getTextBody())->toBe($rendered->text)
                    ->and(FailingMailTransport::$attempts)->toBe(0, 'the preview path reached the failing transport')
                    ->and($arrayTransport->messages())->toHaveCount(1, 'only the sign-in code ever left through the default mailer');

                $report->surface('email', $rendered->version(), $rendered->contentHash(), ['preview_route' => "/briefs/{$brief->id}/preview/mail", 'header' => 'X-LEA-Brief-Preview: nothing-sent']);

                return [
                    'preview_mail' => 200,
                    'preview_text' => 200,
                    'nothing_sent_header' => true,
                    'console_preview' => 'lea:brief:preview',
                    'preview_mailer_captured' => 1,
                    'failing_transport_attempts' => FailingMailTransport::$attempts,
                    'version' => $rendered->version(),
                    'content_hash' => $rendered->contentHash(),
                ];
            });

            // ── §13.1 assurances over the whole run ───────────────────────────────────────
            $ready = sliceReady($app, 200);

            expect($ready['ai_ready'])->toBeFalse()
                ->and($ready['ai_state'])->toBe('not_configured')
                ->and($ready['ai_provider'])->toBeNull()
                ->and($app->make(AiCompletionSuccessMarker::class)->read())->toBeNull('a completion was recorded')
                ->and($app->make('config')->get('lea.ai.provider'))->toBeNull()
                ->and($app->make(HttpClientFactory::class)->recorded())->toBeEmpty()
                ->and(FailingMailTransport::$attempts)->toBe(0)
                ->and($app->make(InstallStateStore::class)->current())->toBe(InstallState::CoreReady);

            $report->assure('outbound_http', 'Http::preventStrayRequests() — any request throws; none recorded');
            $report->assure('ai_ready', false);
            $report->assure('ai_state', $ready['ai_state']);
            $report->assure('ai_provider', null);
            $report->assure('ai_completion_marker', null);
            $report->assure('mailers_used', ['array' => 'one sign-in code', 'preview' => 'one captured brief, never delivered']);
            $report->assure('failing_transport_attempts', FailingMailTransport::$attempts);
            $report->assure('external_accounts', 0);
            $report->assure('install_state', InstallState::CoreReady->value);
        });

        $written = $report->toArray();

        expect($written['passed'])->toBeTrue()
            ->and(array_column($written['steps'], 'id'))->toBe(array_keys(VerticalSliceReport::STEPS))
            ->and($written['surfaces']['pwa']['version'])->toBe($written['surfaces']['menu_bar']['version'])
            ->and($written['surfaces']['pwa']['version'])->toBe($written['surfaces']['email']['version'])
            ->and($written['surfaces']['pwa']['content_hash'])->toBe($written['surfaces']['menu_bar']['content_hash'])
            ->and($written['surfaces']['pwa']['content_hash'])->toBe($written['surfaces']['email']['content_hash'])
            ->and($written['surfaces']['pwa']['content_hash'])->toBe($fixture['content_hash']);
    } finally {
        $path = $report->write();
        $instance->destroy();

        fwrite(STDERR, PHP_EOL."Vertical slice report: {$path} (menu bar oracle: {$report->menuBarOracle})".PHP_EOL);
    }
});
