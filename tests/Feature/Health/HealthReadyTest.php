<?php

declare(strict_types=1);

use App\Models\Brief;
use App\Support\Health\HealthService;
use App\Support\Health\Heartbeats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\CoreReadyState;
use Tests\Support\JsonSchema;

use function Pest\Laravel\get;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

uses(DatabaseTransactions::class);

function readyFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("packaging/fixtures/health/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
}

const CHECK_NAMES = ['http', 'database_migrations', 'auth_login', 'menubar_integration', 'brief_placeholder', 'queue_worker', 'scheduler_selftest'];

beforeEach(function (): void {
    Brief::query()->forceDelete();
    app(HealthService::class)->forgetCached();
});

it('reports core-ready with AI setup required, field for field like the fixture', function (): void {
    $brief = CoreReadyState::create();
    $fixture = readyFixture('ready-core-ready-no-ai');

    $response = get('/health/ready')->assertOk();
    $body = $response->json();

    expect(JsonSchema::errors(JsonSchema::fixturePath('health.schema.json'), $body))->toBe([])
        ->and(array_keys($body))->toBe(array_keys($fixture))
        ->and(array_column($body['checks'], 'name'))->toBe(CHECK_NAMES)
        ->and(array_column($body['checks'], 'status'))->toBe(array_fill(0, 7, 'ok'))
        ->and($body['ready'])->toBeTrue()
        ->and($body['ai_ready'])->toBeFalse()
        ->and($body['ai_state'])->toBe('not_configured')
        ->and($body['ai_provider'])->toBeNull()
        ->and($body['ai_last_success_at'])->toBeNull()
        ->and($body['brief_state'])->toBe('fresh')
        ->and($body['latest_brief'])->toBe([
            'id' => $brief->document_id,
            'version' => 1,
            'generated_at' => $brief->generated_at->toRfc3339String(),
            'stale_after' => $brief->stale_after->toRfc3339String(),
        ])
        ->and($body['connectivity'])->toBe('online')
        ->and($body['privacy_mode'])->toBe('LOCAL_ONLY')
        ->and($body['pending_approvals'])->toBe(0)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    foreach ($fixture['checks'] as $index => $fixtureCheck) {
        expect(array_keys($body['checks'][$index]))->toBe(array_keys($fixtureCheck), "check {$fixtureCheck['name']} key set");
    }

    expect($body['checks'][0]['detail'])->toBe('loopback HTTP responding')
        ->and($body['checks'][1]['detail'])->toMatch('/^schema at \d{4}_\d{2}_\d{2}_\d{6}_[a-z_]+; 0 pending$/')
        ->and($body['checks'][2]['detail'])->toStartWith('owner account exists; login self-test passed ')
        ->and($body['checks'][3]['detail'])->toMatch('/^last IPC heartbeat \d+s ago$/')
        ->and($body['checks'][4]['detail'])->toBe("placeholder brief {$brief->document_id} v1 present")
        ->and($body['checks'][5]['detail'])->toMatch('/^worker heartbeat \d+s ago$/')
        ->and($body['checks'][6]['detail'])->toMatch('/^health:selftest ran \d+s ago \(interval 60s\)$/')
        ->and($body['checks'][3]['threshold_seconds'])->toBe(120)
        ->and($body['checks'][5]['threshold_seconds'])->toBe(90)
        ->and($body['checks'][6]['threshold_seconds'])->toBe(180);
});

it('returns 503 with the same body shape on a fresh install before CREATE_OWNER', function (): void {
    $response = get('/health/ready')->assertStatus(503);
    $body = $response->json();
    $byName = array_column($body['checks'], null, 'name');

    expect(JsonSchema::errors(JsonSchema::fixturePath('health.schema.json'), $body))->toBe([])
        ->and($body['ready'])->toBeFalse()
        ->and($body['ai_ready'])->toBeFalse()
        ->and($body['brief_state'])->toBe('none')
        ->and($body['latest_brief'])->toBeNull()
        ->and($byName['http']['status'])->toBe('ok')
        ->and($byName['database_migrations']['status'])->toBe('ok')
        ->and($byName['auth_login']['status'])->toBe('fail')
        ->and($byName['auth_login']['detail'])->toContain('no owner account')
        ->and($byName['brief_placeholder']['status'])->toBe('fail')
        ->and($byName['menubar_integration']['status'])->toBe('fail')
        ->and($byName['menubar_integration'])->not->toHaveKey('age_seconds')
        ->and($byName['queue_worker']['status'])->toBe('fail')
        ->and($byName['scheduler_selftest']['status'])->toBe('fail');
});

it('drops ready when the scheduler heartbeat ages past its threshold, like the failing-scheduler fixture', function (): void {
    CoreReadyState::create();
    $fixture = readyFixture('not-ready-scheduler-failing');

    app(Heartbeats::class)->record(Heartbeats::SCHEDULER, at: CarbonImmutable::now()->subSeconds(1412));
    config(['lea.ai.provider' => 'openai']);

    $body = get('/health/ready')->assertStatus(503)->json();
    $scheduler = array_column($body['checks'], null, 'name')['scheduler_selftest'];

    expect(JsonSchema::errors(JsonSchema::fixturePath('health.schema.json'), $body))->toBe([])
        ->and($body['ready'])->toBeFalse()
        ->and($scheduler['status'])->toBe('fail')
        ->and($scheduler['age_seconds'])->toBeGreaterThanOrEqual(1412)
        ->and($scheduler['threshold_seconds'])->toBe(180)
        ->and($scheduler['detail'])->toStartWith('health:selftest last ran ')
        ->and(array_keys($scheduler))->toBe(array_keys($fixture['checks'][6]))
        ->and($body['ai_ready'])->toBeFalse()
        ->and($body['ai_state'])->toBe('configured_untested')
        ->and($body['ai_provider'])->toBe('openai')
        ->and(array_column($body['checks'], 'status'))->toBe(['ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'fail']);
});

it('reports a stale brief without dropping ready', function (): void {
    $brief = CoreReadyState::create();

    travelTo($brief->stale_after->addMinute());
    CoreReadyState::heartbeats();

    $body = get('/health/ready')->assertOk()->json();

    expect($body['ready'])->toBeTrue()
        ->and($body['brief_state'])->toBe('stale')
        ->and($body['latest_brief']['id'])->toBe($brief->document_id);
});

it('reports brief error when the generator recorded a failure after the latest brief', function (): void {
    CoreReadyState::create();
    app(Heartbeats::class)->record(Heartbeats::BRIEF_GENERATION_ERROR, at: CarbonImmutable::now()->addSecond());

    expect(get('/health/ready')->assertOk()->json('brief_state'))->toBe('error');
});

it('skips dependants when database_migrations fails and never counts skip as ok', function (): void {
    CoreReadyState::create();

    $pendingDir = sys_get_temp_dir().'/lea-health-pending-'.uniqid();
    mkdir($pendingDir);
    file_put_contents($pendingDir.'/2099_01_01_000000_pending_probe.php', "<?php\nreturn new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void {} };\n");
    app('migrator')->path($pendingDir);

    try {
        $body = get('/health/ready')->assertStatus(503)->json();
    } finally {
        unlink($pendingDir.'/2099_01_01_000000_pending_probe.php');
        rmdir($pendingDir);
    }

    $byName = array_column($body['checks'], null, 'name');

    expect(JsonSchema::errors(JsonSchema::fixturePath('health.schema.json'), $body))->toBe([])
        ->and($byName['database_migrations']['status'])->toBe('fail')
        ->and($byName['database_migrations']['detail'])->toEndWith('; 1 pending')
        ->and($byName['auth_login']['status'])->toBe('skip')
        ->and($byName['auth_login']['detail'])->toBe('skipped: database_migrations failed')
        ->and($byName['brief_placeholder']['status'])->toBe('skip')
        ->and($byName['queue_worker']['status'])->toBe('skip')
        ->and($byName['scheduler_selftest']['status'])->toBe('skip')
        ->and($byName['http']['status'])->toBe('ok')
        ->and($byName['menubar_integration']['status'])->toBe('ok')
        ->and($body['ready'])->toBeFalse();
});

it('serves the ready document from a five-second cache', function (): void {
    CoreReadyState::create();

    $first = get('/health/ready')->assertOk()->json();
    app(Heartbeats::class)->forget(Heartbeats::MENUBAR);

    expect(get('/health/ready')->assertOk()->json())->toBe($first);

    travel(6)->seconds();
    CoreReadyState::heartbeats();
    app(Heartbeats::class)->forget(Heartbeats::MENUBAR);

    $byName = array_column(get('/health/ready')->assertStatus(503)->json('checks'), null, 'name');

    expect($byName['menubar_integration']['status'])->toBe('fail');
});

it('reports the selected privacy mode verbatim', function (): void {
    config(['lea.privacy_mode' => 'EU_RESTRICTED']);

    expect(get('/health/ready')->json('privacy_mode'))->toBe('EU_RESTRICTED');
});
