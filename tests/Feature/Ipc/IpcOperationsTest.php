<?php

declare(strict_types=1);

use App\Briefs\BriefDocumentMapper;
use App\Briefs\DeliveryState;
use App\Models\AuditEvent;
use App\Models\Brief;
use App\Models\Membership;
use App\Models\Organization;
use App\Security\Ipc\Middleware\IpcAuthenticate;
use App\Security\Ipc\OAuth\OAuthCallbackHandler;
use App\Security\Ipc\OAuth\OAuthCallbackResult;
use App\Support\Cancellation\AiPauseSwitch;
use App\Support\Cancellation\OperationCancelledException;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Health\HealthService;
use App\Support\Health\Heartbeats;
use App\Support\Install\Tunnel\TunnelState;
use App\Support\Install\TunnelProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\Support\CoreReadyState;
use Tests\Support\Ipc\FakeOAuthCallbackHandler;
use Tests\Support\Ipc\FakeTunnelProvider;
use Tests\Support\Ipc\IpcClient;
use Tests\Support\JsonSchema;
use Tests\Support\PwaFixtures;

/*
 * The §6 operations end to end behind the real middleware stack: envelopes,
 * gates, ETag/304, idempotent pause/resume, the OAuth seam, remote access
 * against the stub and the fake provider, §4.4 steps 8–11 and the
 * envelope normalisation of framework responses.
 */

uses(DatabaseTransactions::class);

function latestAudit(string $action): ?AuditEvent
{
    return AuditEvent::query()->where('action', $action)->latest('occurred_at')->first();
}

beforeEach(function (): void {
    Brief::query()->forceDelete();
    app(HealthService::class)->forgetCached();
});

describe('health', function (): void {
    it('wraps the ready document in the success envelope with the mirrored status', function (): void {
        CoreReadyState::create();

        $ready = IpcClient::send('GET', '/ipc/v1/health')->assertOk();

        expect($ready->json('ok'))->toBeTrue()
            ->and($ready->json('data.ready'))->toBeTrue()
            ->and(JsonSchema::errors(JsonSchema::fixturePath('health.schema.json'), $ready->json('data')))->toBe([]);

        app(HealthService::class)->forgetCached();
        app(Heartbeats::class)->forget(Heartbeats::SCHEDULER);

        $notReady = IpcClient::send('GET', '/ipc/v1/health');

        expect($notReady->getStatusCode())->toBe(503)
            ->and($notReady->json('ok'))->toBeTrue()
            ->and($notReady->json('data.ready'))->toBeFalse()
            ->and($notReady->json())->not->toHaveKey('error');
    });
});

describe('briefs/latest', function (): void {
    it('answers 404 not_found before any brief exists', function (): void {
        PwaFixtures::owner();

        $response = IpcClient::send('GET', '/ipc/v1/briefs/latest');

        $response->assertStatus(404);
        expect($response->json('error.code'))->toBe('not_found');
    });

    it('returns the latest frozen brief with its ETag and honours If-None-Match', function (): void {
        $owner = PwaFixtures::owner();
        $organization = PwaFixtures::organizationOf($owner);
        $document = json_decode((string) file_get_contents(base_path('packaging/fixtures/briefs/brief-canonical.json')), true, 512, JSON_THROW_ON_ERROR);
        app(BriefDocumentMapper::class)->import($document, $organization, Provenance::job('FixtureImport', ProvenanceOrigin::Import));

        $response = IpcClient::send('GET', '/ipc/v1/briefs/latest')->assertOk();
        $etag = $response->headers->get('ETag');

        expect($etag)->toBe("\"{$document['id']}:{$document['version']}\"")
            ->and($response->json('data.id'))->toBe($document['id'])
            ->and(JsonSchema::errors(JsonSchema::fixturePath('brief.schema.json'), $response->json('data')))->toBe([]);

        $nonce = IpcClient::nonce();
        $cached = IpcClient::send('GET', '/ipc/v1/briefs/latest', null, ['nonce' => $nonce, 'headers' => ['If-None-Match' => $etag]]);

        expect($cached->getStatusCode())->toBe(304)
            ->and($cached->getContent())->toBe('')
            ->and($cached->headers->get('ETag'))->toBe($etag)
            ->and(IpcClient::responseSignatureIsValid($cached, $nonce))->toBeTrue();

        IpcClient::send('GET', '/ipc/v1/briefs/latest', null, ['headers' => ['If-None-Match' => '"'.$document['id'].':0"']])->assertOk();
    });

    it('never returns a draft', function (): void {
        $owner = PwaFixtures::owner();
        $brief = PwaFixtures::placeholder(PwaFixtures::organizationOf($owner));
        $brief->forceFill(['delivery_state' => DeliveryState::Draft])->save();

        IpcClient::send('GET', '/ipc/v1/briefs/latest')->assertStatus(404);
    });

    it('denies the brief of another organisation through the same BriefPolicy the PWA runs', function (): void {
        PwaFixtures::owner();
        $other = Organization::factory()->create();
        PwaFixtures::placeholder($other);

        $response = IpcClient::send('GET', '/ipc/v1/briefs/latest');

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('policy_denied');
    });

    it('denies every gated operation with policy_denied before an owner exists', function (string $method, string $path, ?array $body): void {
        Membership::query()->forceDelete();

        $response = IpcClient::send($method, $path, $body);

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('policy_denied');
    })->with([
        'approvals' => ['GET', '/ipc/v1/approvals?state=pending', null],
        'pause' => ['POST', '/ipc/v1/ai/pause', []],
        'resume' => ['POST', '/ipc/v1/ai/resume', []],
        'remote access' => ['GET', '/ipc/v1/remote-access', null],
        'remote access disable' => ['POST', '/ipc/v1/remote-access/disable', []],
    ]);
});

describe('approvals', function (): void {
    beforeEach(fn () => PwaFixtures::owner());

    it('returns the empty read-only list with total_pending from the health read model', function (): void {
        $response = IpcClient::send('GET', '/ipc/v1/approvals?state=pending')->assertOk();

        expect($response->json('data'))->toEqualCanonicalizing(['items' => [], 'total_pending' => 0, 'next_cursor' => null]);
    });

    it('validates the query strictly', function (string $query, string $field): void {
        $response = IpcClient::send('GET', "/ipc/v1/approvals{$query}");

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('validation_failed')
            ->and($response->json("error.details.{$field}"))->not->toBeNull();
    })->with([
        'no state' => ['', 'state'],
        'other state' => ['?state=approved', 'state'],
        'limit 0' => ['?state=pending&limit=0', 'limit'],
        'limit 51' => ['?state=pending&limit=51', 'limit'],
        'bad cursor' => ['?state=pending&cursor=a%20b', 'cursor'],
        'unknown param' => ['?state=pending&approve=1', 'approve'],
    ]);

    it('has no mutating approval route, elevated or not', function (string $method): void {
        $nonce = IpcClient::nonce();

        $response = IpcClient::send($method, '/ipc/v1/approvals/01M4FJ7XM84RV70ZSNAMPQ03HX/approve', [], [
            'nonce' => $nonce,
            'elevation' => IpcClient::elevation('approvals-approve', $nonce),
        ]);

        $response->assertStatus(404);
        expect($response->json('error.code'))->toBe('not_found')
            ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue();
    })->with(['POST', 'PUT', 'PATCH', 'DELETE']);
});

describe('ai pause and resume', function (): void {
    beforeEach(fn () => PwaFixtures::owner());

    it('flips the global cancellation token idempotently and audits both transitions', function (): void {
        $token = app(AiPauseSwitch::class)->token();

        expect($token->isCancelled())->toBeFalse();

        $paused = IpcClient::send('POST', '/ipc/v1/ai/pause', ['reason' => 'user_menubar'])->assertOk();

        expect($paused->json('data.ai_actions'))->toBe('paused')
            ->and($paused->json('data.paused_since'))->toBe($paused->json('data.changed_at'))
            ->and($paused->json('data.in_flight_runs_cancelled'))->toBe(0)
            ->and($token->isCancelled())->toBeTrue()
            ->and($token->reason())->toBe('user_menubar')
            ->and(fn () => $token->throwIfCancelled())->toThrow(OperationCancelledException::class);

        $again = IpcClient::send('POST', '/ipc/v1/ai/pause', [])->assertOk();

        expect($again->json('data.paused_since'))->toBe($paused->json('data.paused_since'))
            ->and($again->json('data.in_flight_runs_cancelled'))->toBe(0);

        $resumed = IpcClient::send('POST', '/ipc/v1/ai/resume', [])->assertOk();

        expect($resumed->json('data.ai_actions'))->toBe('running')
            ->and($resumed->json('data.paused_since'))->toBeNull()
            ->and($token->isCancelled())->toBeFalse();

        $pausedEvent = latestAudit('ai.paused');
        $resumedEvent = latestAudit('ai.resumed');

        expect($pausedEvent?->context['via'])->toBe('ipc')
            ->and($pausedEvent?->actor)->toStartWith('user:')
            ->and($pausedEvent?->context['request_id'])->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
            ->and($resumedEvent?->context['via'])->toBe('ipc')
            ->and(AuditEvent::query()->where('action', 'ai.paused')->count())->toBe(2);
    });

    it('rejects a reason over 200 characters and any other field', function (): void {
        expect(IpcClient::send('POST', '/ipc/v1/ai/pause', ['reason' => str_repeat('x', 201)])->json('error.code'))->toBe('validation_failed')
            ->and(IpcClient::send('POST', '/ipc/v1/ai/pause', ['approver' => 'me'])->json('error.code'))->toBe('validation_failed')
            ->and(IpcClient::send('POST', '/ipc/v1/ai/resume', ['reason' => 'x'])->json('error.code'))->toBe('validation_failed');
    });
});

describe('oauth/callback', function (): void {
    beforeEach(fn () => PwaFixtures::owner());

    it('answers oauth_state_invalid while no flow is pending', function (): void {
        $response = IpcClient::send('POST', '/ipc/v1/oauth/callback', ['state' => 'st_01M4G0S7X2K9P4R6T8V1W3Y5Z7', 'code' => 'synthetic-code']);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('oauth_state_invalid');
    });

    it('forwards exactly state and code to the bound handler and audits the outcome', function (): void {
        $handler = new FakeOAuthCallbackHandler(new OAuthCallbackResult('failed', null, 'google', null, 'invalid_grant'));
        app()->instance(OAuthCallbackHandler::class, $handler);

        $response = IpcClient::send('POST', '/ipc/v1/oauth/callback', ['state' => 'st_01M4G0S7X2K9P4R6T8V1W3Y5Z7', 'code' => 'synthetic-code'])->assertOk();

        expect($response->json('data'))->toEqualCanonicalizing(['status' => 'failed', 'connection_id' => null, 'provider' => 'google', 'open_url' => null, 'error_reason' => 'invalid_grant'])
            ->and($handler->calls)->toBe([['state' => 'st_01M4G0S7X2K9P4R6T8V1W3Y5Z7', 'code' => 'synthetic-code']])
            ->and(latestAudit('oauth.completed')?->outcome)->toBe('failed');
    });

    it('rejects any field beyond state and code', function (array $body, string $field): void {
        $response = IpcClient::send('POST', '/ipc/v1/oauth/callback', $body);

        $response->assertStatus(422);
        expect($response->json("error.details.{$field}"))->not->toBeNull();
    })->with([
        'provider' => [['state' => 'st_01M4G0S7X2K9P4R6T8V1W3Y5Z7', 'code' => 'c', 'provider' => 'google'], 'provider'],
        'short state' => [['state' => 'short', 'code' => 'c'], 'state'],
        'missing code' => [['state' => 'st_01M4G0S7X2K9P4R6T8V1W3Y5Z7'], 'code'],
    ]);
});

describe('remote access', function (): void {
    beforeEach(fn () => PwaFixtures::owner());

    it('reports NotInstalled from the Sprint 1 stub and refuses to enable', function (): void {
        $status = IpcClient::send('GET', '/ipc/v1/remote-access')->assertOk();

        expect($status->json('data.state'))->toBe('NotInstalled')
            ->and($status->json('data.origin'))->toBeNull()
            ->and($status->json('data.tailscale'))->toBe(['variant' => null, 'version' => null]);

        $nonce = IpcClient::nonce();
        $enable = IpcClient::send('POST', '/ipc/v1/remote-access/enable', ['confirmed_origin' => 'https://my-mac.example-tailnet.ts.net', 'replace_existing' => false], [
            'nonce' => $nonce,
            'elevation' => IpcClient::elevation('remote-access-enable', $nonce),
        ]);

        $enable->assertStatus(409);
        expect($enable->json('error.code'))->toBe('state_conflict')
            ->and($enable->json('error.details.state'))->toBe('NotInstalled');

        $disable = IpcClient::send('POST', '/ipc/v1/remote-access/disable', [])->assertOk();

        expect($disable->json('data'))->toEqualCanonicalizing(['state' => 'NotInstalled', 'origin' => null, 'remaining_handlers' => []]);
    });

    it('requires a valid elevation bound to the request nonce before the gate runs', function (string $case): void {
        app()->instance(TunnelProvider::class, $provider = new FakeTunnelProvider);
        $nonce = IpcClient::nonce();
        $body = ['confirmed_origin' => 'https://my-mac.example-tailnet.ts.net', 'replace_existing' => false];

        [$elevation, $expectedCode] = match ($case) {
            'missing' => [null, 'elevation_required'],
            'other nonce' => [IpcClient::elevation('remote-access-enable', IpcClient::nonce()), 'elevation_invalid'],
            'other operation' => [IpcClient::elevation('remote-access-disable', $nonce), 'elevation_invalid'],
            'other session' => [IpcClient::elevation('remote-access-enable', $nonce, sessionId: '01M4G0R2Y3K8F6V1N7T5B9D4HA'), 'elevation_invalid'],
            'expired' => [IpcClient::elevation('remote-access-enable', $nonce, time() - 1), 'elevation_invalid'],
            'tampered mac' => [substr(IpcClient::elevation('remote-access-enable', $nonce), 0, -4).'0000', 'elevation_invalid'],
            default => throw new LogicException("unknown case {$case}"),
        };

        $options = ['nonce' => $nonce];

        if ($elevation !== null) {
            $options['elevation'] = $elevation;
        }

        $response = IpcClient::send('POST', '/ipc/v1/remote-access/enable', $body, $options);

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe($expectedCode)
            ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue()
            ->and($provider->configured)->toBe([]);
    })->with(['missing', 'other nonce', 'other operation', 'other session', 'expired', 'tampered mac']);

    it('enables only from Ready with the exact confirmed origin, and audits it', function (): void {
        $provider = new FakeTunnelProvider;
        app()->instance(TunnelProvider::class, $provider);
        $nonce = IpcClient::nonce();

        $wrongOrigin = IpcClient::send('POST', '/ipc/v1/remote-access/enable', ['confirmed_origin' => 'https://other-mac.example-tailnet.ts.net', 'replace_existing' => false], [
            'nonce' => $nonce, 'elevation' => IpcClient::elevation('remote-access-enable', $nonce),
        ]);

        expect($wrongOrigin->getStatusCode())->toBe(409)
            ->and($provider->configured)->toBe([]);

        $nonce = IpcClient::nonce();
        $enabled = IpcClient::send('POST', '/ipc/v1/remote-access/enable', ['confirmed_origin' => 'https://my-mac.example-tailnet.ts.net', 'replace_existing' => false], [
            'nonce' => $nonce, 'elevation' => IpcClient::elevation('remote-access-enable', $nonce),
        ])->assertOk();

        expect($enabled->json('data.state'))->toBe('Active')
            ->and($enabled->json('data.origin'))->toBe('https://my-mac.example-tailnet.ts.net')
            ->and($enabled->json('data.verified_at'))->not->toBeNull()
            ->and(count($provider->configured))->toBe(1)
            ->and($provider->configured[0]->loopbackPort)->toBe(8787)
            ->and($provider->configured[0]->replaceDriftedConfiguration)->toBeFalse()
            ->and(latestAudit('remote_access.enabled')?->context['via'])->toBe('ipc');

        $disabled = IpcClient::send('POST', '/ipc/v1/remote-access/disable', [])->assertOk();

        expect($disabled->json('data.state'))->toBe('Ready')
            ->and($provider->disabled)->toBe(1)
            ->and(latestAudit('remote_access.disabled')?->context['via'])->toBe('ipc');
    });

    it('never enables from FunnelDetected or from Drifted without replace_existing', function (TunnelState $state, bool $replace): void {
        app()->instance(TunnelProvider::class, $provider = new FakeTunnelProvider($state));
        $nonce = IpcClient::nonce();

        $response = IpcClient::send('POST', '/ipc/v1/remote-access/enable', ['confirmed_origin' => 'https://my-mac.example-tailnet.ts.net', 'replace_existing' => $replace], [
            'nonce' => $nonce, 'elevation' => IpcClient::elevation('remote-access-enable', $nonce),
        ]);

        $response->assertStatus(409);
        expect($provider->configured)->toBe([]);
    })->with([
        'funnel, replace' => [TunnelState::FunnelDetected, true],
        'drifted, no replace' => [TunnelState::Drifted, false],
        'active' => [TunnelState::Active, false],
        'not logged in' => [TunnelState::NotLoggedIn, false],
    ]);

    it('enables from Drifted when the user chose Replace', function (): void {
        app()->instance(TunnelProvider::class, $provider = new FakeTunnelProvider(TunnelState::Drifted, driftDetails: ['/foo']));
        $nonce = IpcClient::nonce();

        IpcClient::send('POST', '/ipc/v1/remote-access/enable', ['confirmed_origin' => 'https://my-mac.example-tailnet.ts.net', 'replace_existing' => true], [
            'nonce' => $nonce, 'elevation' => IpcClient::elevation('remote-access-enable', $nonce),
        ])->assertOk();

        expect($provider->configured[0]->replaceDriftedConfiguration)->toBeTrue();
    });
});

describe('request hygiene after authentication', function (): void {
    beforeEach(fn () => CoreReadyState::create());

    it('rejects an unknown X-Lxai-* header with a signed 400', function (): void {
        $nonce = IpcClient::nonce();

        $response = IpcClient::send('GET', '/ipc/v1/health', null, ['nonce' => $nonce, 'headers' => ['X-Lxai-Origin' => '127.0.0.1:8787']]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('bad_request')
            ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue();
    });

    it('rejects a method override header', function (): void {
        $response = IpcClient::send('POST', '/ipc/v1/ai/pause', [], ['headers' => ['X-HTTP-Method-Override' => 'DELETE']]);

        $response->assertStatus(400);
    });

    it('rejects a body over 64 KiB with 413', function (): void {
        $response = IpcClient::send('POST', '/ipc/v1/ai/pause', ['reason' => str_repeat('x', 70_000)]);

        $response->assertStatus(413);
        expect($response->json('error.code'))->toBe('payload_too_large');
    });

    it('rejects malformed JSON and non-object bodies with 400', function (string $raw): void {
        $response = IpcClient::send('POST', '/ipc/v1/ai/pause', null, ['raw_body' => $raw]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('bad_request');
    })->with(['{"reason":', '[]', '"string"', '42']);

    it('answers reserved service routes and unknown paths with a signed 404', function (string $method, string $path): void {
        $nonce = IpcClient::nonce();

        $response = IpcClient::send($method, $path, $method === 'POST' ? [] : null, ['nonce' => $nonce]);

        $response->assertStatus(404);
        expect($response->json('error.code'))->toBe('not_found')
            ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue();
    })->with([
        ['GET', '/ipc/v1/services'],
        ['POST', '/ipc/v1/services/core/restart'],
        ['GET', '/ipc/v1/nope'],
        ['POST', '/ipc/v1/briefs/latest'],
    ]);

    it('enforces the all-routes budget of 120 a minute per session', function (): void {
        config(['ipc.rate_limits.all' => 3]);

        IpcClient::send('GET', '/ipc/v1/health')->assertOk();
        IpcClient::send('GET', '/ipc/v1/remote-access')->assertOk();
        IpcClient::send('GET', '/ipc/v1/approvals?state=pending')->assertOk();

        $limited = IpcClient::send('GET', '/ipc/v1/health');

        $limited->assertStatus(429);
        expect($limited->headers->get('Retry-After'))->not->toBeNull();

        IpcClient::send('GET', '/ipc/v1/health', null, ['session_id' => '01M4G0R2Y3K8F6V1N7T5B9D4HA'])->assertOk();
    });

    it('is never throttled per IP by the framework: 70 health requests in a minute all pass', function (): void {
        config(['ipc.rate_limits.all' => 200, 'ipc.rate_limits.health' => 200]);

        for ($i = 0; $i < 70; $i++) {
            $response = IpcClient::send('GET', '/ipc/v1/health');

            expect($response->getStatusCode())->toBe(200, "request {$i}: ".$response->getContent());
        }

        expect(true)->toBeTrue();
    });

    it('rewrites a framework exception into the signed error envelope', function (): void {
        Route::middleware(IpcAuthenticate::class.':menubar')->get('/ipc/_boom', function (): never {
            throw new RuntimeException('provider said something it should not');
        });
        config(['app.debug' => false]);
        $nonce = IpcClient::nonce();

        $response = IpcClient::send('GET', '/ipc/_boom', null, ['nonce' => $nonce]);

        $response->assertStatus(500);
        expect($response->json('error.code'))->toBe('internal')
            ->and($response->json('error.hint'))->toContain('request_id')
            ->and((string) $response->getContent())->not->toContain('provider said')
            ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue();
    });

    it('signs /health/ready over its un-enveloped body', function (): void {
        $nonce = IpcClient::nonce();

        $response = IpcClient::send('GET', '/health/ready', null, ['nonce' => $nonce])->assertOk();

        expect($response->json())->toHaveKeys(['schema_version', 'ready', 'checks'])
            ->and($response->json())->not->toHaveKey('ok')
            ->and(IpcClient::responseSignatureIsValid($response, $nonce))->toBeTrue()
            ->and($response->headers->get('X-Lxai-Origin'))->toBe('127.0.0.1:8787');
    });
});
