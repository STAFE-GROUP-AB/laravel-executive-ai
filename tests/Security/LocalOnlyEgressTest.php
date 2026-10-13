<?php

declare(strict_types=1);

use App\AI\Providers\Dto\AiErrorKind;
use App\AI\Providers\Exceptions\EgressBlockedException;
use App\AI\Providers\InMemoryProviderConnections;
use App\AI\Providers\ProviderConnection;
use App\AI\Providers\ProviderKind;
use App\AI\Providers\ProviderRegistry;
use App\AI\Routing\JobRequirements;
use App\AI\Routing\ModelRouter;
use App\AI\Routing\PrivacyMode;
use App\Security\Egress\AddressNormalizer;
use App\Security\Egress\EgressGuard;
use App\Security\Egress\EgressPurpose;
use App\Security\Egress\EgressRule;
use App\Security\Egress\EgressServiceProvider;
use App\Security\Egress\HostResolver;
use App\Security\Privacy\ProviderEligibility;
use App\Security\Secrets\SecretRef;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\FailoverableException;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use Tests\Integration\AI\Contract\Support\ContractHarness;
use Tests\Security\Support\FakeHostResolver;

/*
 * ADR-004 **D4**: `LOCAL_ONLY` is strict, enforced in five independent layers,
 * and each layer alone blocks. This file holds one test per layer, written so
 * that removing any single layer still leaves four tests red — which is the
 * only way "each alone is sufficient" is a claim rather than a hope.
 *
 *   1. the registry gate that never builds a non-local adapter
 *   2. the host allowlist, resolved once and pinned
 *   3. the `Http` middleware veto that throws before a socket opens, per hop
 *   4. no failover, because `EgressBlockedException` is not `FailoverableException`
 *   5. one path for every content type
 *
 * **Nothing here opens a socket.** `Http::preventStrayRequests()` is armed and
 * names resolve through a fake, which is also what makes the layer-3 test
 * possible at all: Laravel pushes global middleware *before* the stub handler
 * that `Http::fake()` installs, so the veto runs ahead of the fake and a test
 * cannot accidentally prove the guard works by faking past it.
 *
 * The other half of D4's evidence is a **lab** step and is never claimed here:
 * `docs/security/egress-guard.md` §7 records the `tcpdump` protocol and the
 * release checklist carries the pcap. CI proves the code refuses; only the
 * capture proves the machine stayed quiet.
 */

/** A cloud provider the owner deliberately configured, which Local Only must refuse. */
function cloudProvider(): ProviderConnection
{
    return new ProviderConnection(
        providerId: 'openai',
        driver: 'openai',
        kind: ProviderKind::Remote,
        keyRef: new SecretRef(SecretRef::AI_PROVIDER, 'openai'),
        modelIds: ['gpt-5-mini'],
    );
}

/** The loopback runtime that is the only thing Local Only admits. */
function localProvider(): ProviderConnection
{
    return new ProviderConnection(
        providerId: 'ollama',
        driver: 'ollama',
        kind: ProviderKind::Local,
        baseUrl: 'http://127.0.0.1:11434',
        modelIds: ['llama3.2:3b'],
    );
}

function localOnlyGuard(): EgressGuard
{
    return new EgressGuard(
        resolver: (new FakeHostResolver)
            ->answer('api.openai.com', ['93.184.216.34'])
            ->answer('models.example.test', ['93.184.216.34']),
        normalizer: new AddressNormalizer,
        ownCorePort: 8787,
    );
}

function localOnlyRegistry(ProviderConnection ...$connections): ProviderRegistry
{
    $eligibility = new ProviderEligibility(localOnlyGuard());
    $connections = new InMemoryProviderConnections(array_values($connections));

    return new ProviderRegistry(
        connections: $connections,
        factory: ContractHarness::factory(),
        router: new ModelRouter($connections, ContractHarness::capabilities(), $eligibility),
        eligibility: $eligibility,
    );
}

/**
 * Point the container's guard at a scripted resolver. The guard is a singleton,
 * so the memoised instance has to go with the resolver or the swap is silent.
 */
function useResolver(FakeHostResolver $resolver): void
{
    app()->instance(HostResolver::class, $resolver);
    app()->forgetInstance(EgressGuard::class);
}

/** The global middleware the egress provider installed — the layer-3 veto itself. */
function egressVeto(): callable
{
    $middleware = app(HttpFactory::class)->getGlobalMiddleware();

    expect($middleware)->toHaveCount(1, 'another global HTTP middleware appeared; this test must say which one is the veto');

    return $middleware[0];
}

/** Drive the veto directly with one request, the way Guzzle's redirect middleware drives its inner handler. */
function throughVeto(string $url, array $options = [], ?array &$seenOptions = null): PsrResponse
{
    $handler = egressVeto()(function (RequestInterface $request, array $options) use (&$seenOptions) {
        $seenOptions = $options;

        return Create::promiseFor(new PsrResponse(200));
    });

    return $handler(new Request('GET', $url), $options)->wait();
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake();
});

/*
 * --------------------------------------------------- layer 1: the registry gate
 */

it('layer 1 never builds a non-local adapter in Local Only, and records zero requests', function (): void {
    $registry = localOnlyRegistry(localProvider(), cloudProvider());

    // The adapter object never exists, so there is nothing on which a later
    // mistake could make a cloud call.
    try {
        $registry->adapterFor('openai', PrivacyMode::LocalOnly);
        Assert::fail('a cloud provider must not be buildable under Local Only');
    } catch (EgressBlockedException $exception) {
        expect($exception->error->kind)->toBe(AiErrorKind::EgressBlocked)
            ->and($exception->rule)->toBe(EgressRule::LocalOnly->value);
    }

    expect($registry->isBuildable('ollama', PrivacyMode::LocalOnly))->toBeTrue()
        ->and($registry->isBuildable('openai', PrivacyMode::LocalOnly))->toBeFalse()
        ->and(array_map(fn (ProviderConnection $c): string => $c->providerId, $registry->buildable(PrivacyMode::LocalOnly)))->toBe(['ollama'])
        // Shown greyed out with a reason rather than hidden (D4 layer 1).
        ->and(array_map(fn (ProviderConnection $c): string => $c->providerId, $registry->disabledBy(PrivacyMode::LocalOnly)))->toBe(['openai']);

    Http::assertNothingSent();
});

it('layer 1 refuses the whole routing decision rather than quietly dropping the cloud candidate', function (): void {
    $eligibility = new ProviderEligibility(localOnlyGuard());
    $connections = new InMemoryProviderConnections([cloudProvider()]);
    $router = new ModelRouter($connections, ContractHarness::capabilities(), $eligibility);

    expect(fn (): mixed => $router->resolve(new JobRequirements('ComposeBrief'), PrivacyMode::LocalOnly))
        ->toThrow(EgressBlockedException::class);

    Http::assertNothingSent();
});

/*
 * ------------------------------------------- layer 2: the allowlist, resolved once
 */

it('layer 2 admits the loopback runtime and refuses everything else, on the resolved address', function (): void {
    $eligibility = new ProviderEligibility(localOnlyGuard());

    $named = fn (string $url): ProviderConnection => new ProviderConnection(
        providerId: 'custom',
        driver: 'openai-compatible',
        kind: ProviderKind::Local,
        baseUrl: $url,
        modelIds: ['byo'],
    );

    expect($eligibility->allows(localProvider(), PrivacyMode::LocalOnly))->toBeTrue()
        ->and($eligibility->allows($named('http://localhost:1234/v1'), PrivacyMode::LocalOnly))->toBeTrue()
        ->and($eligibility->allows($named('http://[::1]:1234/v1'), PrivacyMode::LocalOnly))->toBeTrue()
        // A name that resolves somewhere public is not local, whatever it is called.
        ->and($eligibility->refusalFor($named('https://models.example.test/v1'), PrivacyMode::LocalOnly))->toBe(EgressRule::LocalOnly->value)
        // The LAN opt-in is off by default and widens to RFC 1918 only.
        ->and($eligibility->refusalFor($named('http://10.0.0.5:11434/v1'), PrivacyMode::LocalOnly))->toBe(EgressRule::LanNotAllowed->value)
        ->and($eligibility->allows($named('http://10.0.0.5:11434/v1'), PrivacyMode::LocalOnly, allowLan: true))->toBeTrue()
        ->and($eligibility->refusalFor($named('https://models.example.test/v1'), PrivacyMode::LocalOnly, allowLan: true))->toBe(EgressRule::LocalOnly->value);
});

it('layer 2 pins the address for the request rather than handing the client a name', function (): void {
    $target = localOnlyGuard()->authorize('http://localhost:11434/api/chat', EgressPurpose::LocalRuntime, PrivacyMode::LocalOnly);

    expect($target->address)->toBe('127.0.0.1')
        ->and($target->requestOptions()['curl'][CURLOPT_RESOLVE])->toBe(['localhost:11434:127.0.0.1']);
});

/*
 * ------------------------------------------------- layer 3: the HTTP-layer veto
 */

it('layer 3 throws before a socket opens, for any outbound request whoever built it', function (): void {
    // The veto is installed on the HTTP client factory, which is what the pinned
    // SDK uses too (`CreatesClient::createClient()` → `Http::baseUrl(...)`), so
    // it covers code that never heard of the guard.
    config(['lea.privacy_mode' => 'LOCAL_ONLY']);
    useResolver((new FakeHostResolver)->answer('api.openai.com', ['93.184.216.34']));

    expect(fn (): mixed => Http::get('https://api.openai.com/v1/chat/completions'))
        ->toThrow(EgressBlockedException::class);

    // The fake is installed *inside* the veto, so a blocked request is not even
    // recorded: there was no request.
    Http::assertNothingSent();

    // And the loopback runtime still works, which is what makes Local Only a
    // mode rather than an outage.
    Http::get('http://127.0.0.1:11434/api/tags');

    Http::assertSent(fn (Illuminate\Http\Client\Request $request): bool => $request->url() === 'http://127.0.0.1:11434/api/tags');
});

it('layer 3 re-checks every hop of a redirect, because it sits inside Guzzle\'s redirect middleware', function (): void {
    // T-24: the first URL passes and a `3xx` points at a blocked target. Laravel
    // pushes global middleware after `HandlerStack::create()` has pushed
    // `allow_redirects`, and Guzzle resolves the stack in reverse — so the
    // redirect middleware is outside ours and every hop re-enters the veto.
    //
    // Asserted on the stack itself: a handler is driven directly, first with the
    // permitted URL and then with the URL the redirect would produce, which is
    // what the redirect middleware does to its inner handler.
    config(['lea.privacy_mode' => 'CUSTOM']);
    useResolver((new FakeHostResolver)
        ->answer('public.example.test', ['93.184.216.34'])
        ->answer('redirect.attacker.test', ['127.0.0.1']));

    $fetch = [EgressServiceProvider::PURPOSE_OPTION => EgressPurpose::WebFetch];

    expect(throughVeto('https://public.example.test/x', $fetch)->getStatusCode())
        ->toBe(200, 'the first hop is permitted');

    // The hop the redirect would produce, driven through the same veto. It is
    // refused, so the chain stops at the hop and not at the first URL.
    expect(fn (): mixed => throughVeto('https://redirect.attacker.test/x', $fetch))
        ->toThrow(EgressBlockedException::class);
});

it('layer 3 pins the judged address onto the request options', function (): void {
    config(['lea.privacy_mode' => 'CUSTOM']);
    useResolver((new FakeHostResolver)->answer('public.example.test', ['93.184.216.34']));

    $seen = null;
    throughVeto('https://public.example.test/x', [], $seen);

    expect($seen['curl'][CURLOPT_RESOLVE])->toBe(['public.example.test:443:93.184.216.34']);
});

it('layer 3 falls closed to the strictest mode when the stored policy cannot be read', function (): void {
    // The one thing the middleware must never do is open the boundary because
    // the table was unreadable. `lea.privacy_mode` is the configured seed and
    // `PrivacyMode::fromConfigValue()` already falls closed, so an unparseable
    // value is `LOCAL_ONLY` and a cloud host is refused.
    config(['lea.privacy_mode' => 'something nobody can parse']);
    useResolver((new FakeHostResolver)->answer('api.openai.com', ['93.184.216.34']));

    expect(fn (): mixed => Http::get('https://api.openai.com/v1/models'))
        ->toThrow(EgressBlockedException::class);

    Http::assertNothingSent();
});

/*
 * ---------------------------------------------------------- layer 4: no failover
 */

it('layer 4 keeps egress_blocked out of the SDK\'s failover set', function (): void {
    $blocked = new EgressBlockedException('api.openai.com', EgressRule::LocalOnly->value, 'Local Only');

    expect($blocked->error->kind)->toBe(AiErrorKind::EgressBlocked)
        ->and($blocked->error->retryable)->toBeFalse()
        ->and($blocked)->not->toBeInstanceOf(FailoverableException::class);
});

/*
 * ------------------------------------------- layer 5: one path for every content
 */

it('layer 5 puts prompts, embeddings, screenshots and tool transcripts on the same path', function (): void {
    // §4 lists four content types; D4.5 says they go through the same adapter
    // and the same middleware. The property that makes that true is that the
    // veto is keyed on the *address*, not on what the body contains — so these
    // four are the same request to it, and the assertion is that none of the
    // four reaches a socket when the target is refused.
    config(['lea.privacy_mode' => 'LOCAL_ONLY']);
    useResolver((new FakeHostResolver)->answer('api.openai.com', ['93.184.216.34']));

    $payloads = [
        'prompt' => ['messages' => [['role' => 'user', 'content' => 'summarise']]],
        'embedding' => ['input' => 'customer note'],
        'screenshot' => ['image' => 'base64-bytes'],
        'tool transcript' => ['tool_calls' => [['name' => 'crm.search']]],
    ];

    foreach ($payloads as $kind => $payload) {
        expect(fn (): mixed => Http::post('https://api.openai.com/v1/anything', $payload))
            ->toThrow(EgressBlockedException::class, '', "{$kind} must take the same path");
    }

    Http::assertNothingSent();
});

/*
 * ---------------------------------------- the whole fixture suite, in Local Only
 */

it('runs every configured provider in Local Only and leaves the cloud one refused with egress_blocked', function (): void {
    // ADR-004 D4's own evidence requirement: the AI fixture providers, a cloud
    // provider deliberately configured, zero requests to a non-allowlisted host,
    // and `egress_blocked` raised for the cloud one.
    config(['lea.privacy_mode' => 'LOCAL_ONLY']);

    $connections = array_map(ContractHarness::connection(...), ContractHarness::driverNames());
    $registry = localOnlyRegistry(...$connections);
    $outcomes = [];

    foreach ($connections as $connection) {
        try {
            $registry->adapterFor($connection->providerId, PrivacyMode::LocalOnly);
            $outcomes[$connection->providerId] = 'built';
        } catch (EgressBlockedException $exception) {
            $outcomes[$connection->providerId] = $exception->error->kind->value;
        }
    }

    expect($outcomes)->toBe([
        'ollama' => 'built',
        'lmstudio' => 'built',
        'openai' => AiErrorKind::EgressBlocked->value,
        'anthropic' => AiErrorKind::EgressBlocked->value,
        'custom' => AiErrorKind::EgressBlocked->value,
    ]);

    Http::assertNothingSent();
});
