<?php

declare(strict_types=1);

use App\Briefs\BriefDocumentMapper;
use App\Models\Brief;
use App\Security\Ipc\IpcClient as Client;
use App\Security\Ipc\IpcKey;
use App\Security\Ipc\IpcSigner;
use App\Security\Ipc\OAuth\OAuthCallbackHandler;
use App\Security\Ipc\OAuth\OAuthCallbackResult;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Health\HealthService;
use App\Support\Health\PendingApprovals;
use App\Support\Install\TunnelProvider;
use App\Support\Json\Jcs;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoreReadyState;
use Tests\Support\Ipc\FakeOAuthCallbackHandler;
use Tests\Support\Ipc\FakePendingApprovals;
use Tests\Support\Ipc\FakeTunnelProvider;
use Tests\Support\Ipc\IpcClient;
use Tests\Support\JsonSchema;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\call;
use function Pest\Laravel\travelTo;

/*
 * IPC contract §12 `IpcFixtureConformanceTest` (threat model T-17, T-30 –
 * T-35): every packaging/fixtures/ipc/*.json request, signed with K_test
 * and sent byte for byte, is accepted or rejected exactly as recorded, the
 * recorded response signature verifies under the PHP port, and the server's
 * own response is signed over the bytes it actually sent. For the
 * deterministic exchanges the server reproduces the recorded body and the
 * recorded X-Lxai-Signature exactly.
 */

uses(DatabaseTransactions::class);

const FIXTURE_NOW = '2026-10-10T18:00:05+02:00';

/** Exchanges whose response bytes the server reproduces exactly once the world is seeded. */
const BYTE_EXACT = ['briefs-latest', 'briefs-latest-not-modified', 'error-forbidden-scope', 'error-signature-invalid', 'error-version-unsupported', 'heartbeat', 'approvals-pending', 'oauth-callback', 'remote-access-disable'];

function loadIpcFixture(string $name): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 3)."/packaging/fixtures/ipc/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}

function fixtureNames(): array
{
    return array_map(fn (string $file): string => basename($file, '.json'), glob(dirname(__DIR__, 3).'/packaging/fixtures/ipc/*.json') ?: []);
}

function seedWorldFor(string $name, array $fixture): void
{
    $owner = fn () => PwaFixtures::owner();

    match ($name) {
        'health' => CoreReadyState::create(),
        'briefs-latest', 'briefs-latest-not-modified' => (function () use ($owner): void {
            $document = loadIpcFixture('briefs-latest')['response']['body']['data'];
            $organization = PwaFixtures::organizationOf($owner());
            app(BriefDocumentMapper::class)->import($document, $organization, Provenance::job('FixtureImport', ProvenanceOrigin::Import));
        })(),
        'approvals-pending' => (function () use ($fixture, $owner): void {
            $owner();
            app()->instance(PendingApprovals::class, FakePendingApprovals::fromFixtureItems($fixture['response']['body']['data']['items']));
        })(),
        'oauth-callback' => (function () use ($fixture, $owner): void {
            $owner();
            $data = $fixture['response']['body']['data'];
            app()->instance(OAuthCallbackHandler::class, new FakeOAuthCallbackHandler(new OAuthCallbackResult($data['status'], $data['connection_id'], $data['provider'], '/integrations/'.$data['connection_id'])));
        })(),
        'remote-access-status', 'remote-access-enable', 'remote-access-disable' => (function () use ($owner): void {
            $owner();
            app()->instance(TunnelProvider::class, new FakeTunnelProvider);
        })(),
        'error-rate-limited' => (function () use ($owner): void {
            $owner();
            $limiter = app(RateLimiter::class);

            for ($i = 0; $i < 6; $i++) {
                $limiter->hit('ipc:limit:heartbeat:'.IpcClient::SESSION, 10);
            }
        })(),
        'ai-pause', 'ai-resume', 'heartbeat', 'error-forbidden-scope', 'error-signature-invalid', 'error-version-unsupported' => $owner(),
        'health-not-ready' => null,
        default => throw new RuntimeException("No world seeded for fixture {$name}; add it to seedWorldFor()"),
    };
}

/** Envelope bodies are RFC 8785 canonical, so key sets are compared, not key order. */
function sortedKeys(array $value): array
{
    $keys = array_map(strval(...), array_keys($value));
    sort($keys);

    return $keys;
}

function sendFixtureRequest(array $request): TestResponse
{
    $server = ['REMOTE_ADDR' => '127.0.0.1'];

    foreach ($request['headers'] as $name => $value) {
        if ($name === 'Host') {
            continue;
        }

        $key = strtoupper(str_replace('-', '_', $name));
        $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] = $value;
    }

    $content = $request['body'] === null ? null : Jcs::canonicalize($request['body'] === [] ? new stdClass : $request['body']);

    return call($request['method'], 'http://'.$request['headers']['Host'].$request['path'], [], [], [], $server, $content);
}

beforeEach(function (): void {
    config(['app.timezone' => 'Europe/Stockholm', 'app.version' => '1.0.0', 'ipc.server.runtime_version' => '1.0.0', 'ipc.port' => 8787]);
    travelTo(CarbonImmutable::parse(FIXTURE_NOW));
    Brief::query()->forceDelete();
    app(HealthService::class)->forgetCached();
});

it('answers every recorded exchange with the recorded status and a valid response signature', function (string $name): void {
    $fixture = loadIpcFixture($name);
    seedWorldFor($name, $fixture);
    $request = $fixture['request'];
    $recorded = $fixture['response'];
    $client = Client::from(explode('.', substr($request['headers']['Authorization'], 7))[1]);
    $signingKey = IpcSigner::signingKey(IpcKey::forTesting(), $client, IpcClient::SESSION);
    $nonce = $request['headers']['X-Lxai-Nonce'];

    $response = sendFixtureRequest($request);

    expect($response->getStatusCode())->toBe($recorded['status'], "status for {$name}: ".$response->getContent());

    // The recorded response signature verifies under the PHP port (§4.6).
    if (isset($recorded['headers']['X-Lxai-Signature'])) {
        $recordedBody = $recorded['body'] === null ? '' : Jcs::canonicalize($recorded['body']);
        expect(IpcSigner::signResponse($signingKey, $recorded['status'], $nonce, $recordedBody))->toBe($recorded['headers']['X-Lxai-Signature']);
    }

    // The server's own response: §4.5 headers, signed over the bytes actually sent (or unsigned per §4.5).
    expect($response->headers->get('X-Lxai-Ipc-Version'))->toBe('1')
        ->and($response->headers->get('X-Lxai-Request-Id'))->toBe($request['headers']['X-Lxai-Request-Id'])
        ->and($response->headers->get('X-Lxai-Origin'))->toBe('127.0.0.1:8787')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    $errorCode = $recorded['body']['error']['code'] ?? null;

    if (in_array($errorCode, ['origin_rejected', 'version_unsupported', 'session_invalid'], true)) {
        expect($response->headers->has('X-Lxai-Signature'))->toBeFalse();
    } else {
        expect(IpcClient::responseSignatureIsValid($response, $nonce, $client->value))->toBeTrue();
    }

    if ($recorded['status'] === 304) {
        expect($response->getContent())->toBe('')
            ->and($response->headers->get('ETag'))->toBe($recorded['headers']['ETag']);
    } else {
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        expect($response->headers->get('Content-Type'))->toBe('application/json; charset=utf-8')
            ->and($body['ok'])->toBe($recorded['body']['ok'])
            ->and($body['request_id'])->toBe($recorded['body']['request_id'])
            ->and(sortedKeys($body))->toBe(sortedKeys($recorded['body']));

        if ($errorCode !== null) {
            expect($body['error']['code'])->toBe($errorCode)
                ->and(sortedKeys($body['error']))->toBe(sortedKeys($recorded['body']['error']));
        } else {
            expect(sortedKeys($body['data']))->toBe(sortedKeys($recorded['body']['data']));
        }

        // The actual exchange validates against ipc.schema.json as an exchange document.
        $exchange = [
            'schema_version' => '1.0.0',
            'operation' => $fixture['operation'],
            'description' => 'actual server response',
            'request' => [...$request, 'body' => $request['body'] === [] ? new stdClass : $request['body']],
            'response' => [
                'status' => $response->getStatusCode(),
                'headers' => array_filter([
                    'Content-Type' => $response->headers->get('Content-Type'),
                    'Cache-Control' => 'no-store',
                    'X-Lxai-Ipc-Version' => $response->headers->get('X-Lxai-Ipc-Version'),
                    'X-Lxai-Request-Id' => $response->headers->get('X-Lxai-Request-Id'),
                    'X-Lxai-Signature' => $response->headers->get('X-Lxai-Signature'),
                    'X-Lxai-Origin' => $response->headers->get('X-Lxai-Origin'),
                    'ETag' => $response->headers->get('ETag'),
                    'Retry-After' => $response->headers->get('Retry-After'),
                ], fn ($value) => $value !== null),
                'body' => $body,
            ],
        ];

        expect(JsonSchema::errors(JsonSchema::fixturePath('ipc.schema.json'), $exchange))->toBe([]);
    }

    if (in_array($name, BYTE_EXACT, true)) {
        $recordedBody = $recorded['body'] === null ? '' : Jcs::canonicalize($recorded['body']);

        expect($response->getContent())->toBe($recordedBody, "byte-exact body for {$name}")
            ->and($response->headers->get('X-Lxai-Signature'))->toBe($recorded['headers']['X-Lxai-Signature'] ?? null, "byte-exact signature for {$name}");
    }
})->with(fixtureNames());

it('rejects every fixture request when it is replayed within the nonce window', function (string $name): void {
    $fixture = loadIpcFixture($name);
    $errorCode = $fixture['response']['body']['error']['code'] ?? null;

    if (in_array($errorCode, ['origin_rejected', 'version_unsupported', 'session_invalid', 'forbidden_scope'], true)) {
        expect(true)->toBeTrue();

        return;
    }

    seedWorldFor($name, $fixture);

    sendFixtureRequest($fixture['request']);
    $replayed = sendFixtureRequest($fixture['request']);

    // A nonce is consumed only once its signature verified (§4.3 "nonces it has accepted"),
    // so the bad-signature fixture fails the same way twice and never burns the nonce.
    $expectedCode = $errorCode === 'signature_invalid' ? 'signature_invalid' : 'nonce_replayed';

    expect($replayed->getStatusCode())->toBe(401)
        ->and($replayed->json('error.code'))->toBe($expectedCode)
        ->and(IpcClient::responseSignatureIsValid($replayed, $fixture['request']['headers']['X-Lxai-Nonce']))->toBeTrue();
})->with(fixtureNames());
