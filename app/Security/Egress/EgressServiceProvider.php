<?php

declare(strict_types=1);

namespace App\Security\Egress;

use App\Security\Audit\AuditLog;
use App\Security\Privacy\PrivacyModeStore;
use App\Security\Privacy\PrivacyPolicyState;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Wires the guard and installs **ADR-004 D4 layer 3**: the HTTP-layer veto that
 * every outbound request in the product passes through, whoever built it.
 *
 * The middleware is registered on `Illuminate\Http\Client\Factory`, which is
 * what both our own code and the pinned SDK use (`CreatesClient::createClient()`
 * → `Http::baseUrl(...)`). Where it sits in Guzzle's handler stack is the whole
 * reason it works, and it is worth being explicit because the two facts are not
 * obvious and both are load-bearing:
 *
 * - Laravel pushes global middleware **after** `HandlerStack::create()` has
 *   pushed `allow_redirects`, and Guzzle resolves the stack in reverse — so the
 *   redirect middleware is *outside* ours. Every hop of a followed redirect
 *   therefore re-enters this middleware and is re-checked and re-pinned from
 *   scratch (checklist row 13, threat T-24). Nothing about a first URL that
 *   passed can be used to reach a second one that would not.
 * - The stub handler that `Http::fake()` installs is pushed after global
 *   middleware, so it is *inside* ours. The veto runs before a fake answers,
 *   which is why `LocalOnlyEgressTest` can assert zero recorded requests without
 *   a socket, and why a test cannot accidentally prove the guard works by
 *   faking past it.
 *
 * The pin is applied here too, as `CURLOPT_RESOLVE` on the request options, so
 * the socket connects to the address this middleware judged rather than to a
 * second answer for the same name (threat T-25).
 *
 * `$options['lea_egress_purpose']` lets a caller declare what the request is
 * for; an unlabelled request is model traffic, the category `LOCAL_ONLY`
 * restricts hardest.
 */
final class EgressServiceProvider extends ServiceProvider
{
    /** The request option a caller sets to declare its purpose. */
    public const string PURPOSE_OPTION = 'lea_egress_purpose';

    public function register(): void
    {
        // A DNS lookup is a socket, and the repository's rule is that no test
        // opens one. Under `php artisan test` the resolver therefore answers
        // only RFC 6761's reserved loopback names and nothing else: a suite that
        // resolved a real name would assert against whatever the developer's
        // resolver happened to say, including an ISP that hijacks NXDOMAIN. A
        // test that needs a name to resolve binds its own fake.
        $this->app->singleton(
            HostResolver::class,
            fn (Application $app): HostResolver => $app->runningUnitTests()
                ? new LoopbackHostResolver
                : new SystemHostResolver,
        );

        $this->app->singleton(AddressNormalizer::class);

        $this->app->singleton(
            EgressDenialRecorder::class,
            fn (Application $app): EgressDenialRecorder => new AuditEgressDenialRecorder($app->make(AuditLog::class)),
        );

        $this->app->singleton(EgressGuard::class, fn (Application $app): EgressGuard => new EgressGuard(
            resolver: $app->make(HostResolver::class),
            recorder: $app->make(EgressDenialRecorder::class),
            normalizer: $app->make(AddressNormalizer::class),
            ownCorePort: (int) $app['config']->get('ipc.port', 8787),
        ));

        $this->app->singleton(PrivacyModeStore::class);
    }

    public function boot(): void
    {
        $this->app->make(HttpFactory::class)->globalMiddleware($this->veto());
    }

    /**
     * The veto itself. It throws `EgressBlockedException` before `$handler` is
     * called, so no socket is opened; ADR-004 D4.4 keeps that exception out of
     * the SDK's failover set, so a blocked cloud call is refused rather than
     * retried somewhere else.
     */
    private function veto(): callable
    {
        return fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler) {
            $purpose = $options[self::PURPOSE_OPTION] ?? EgressPurpose::ModelProvider;
            $purpose = $purpose instanceof EgressPurpose ? $purpose : EgressPurpose::ModelProvider;

            $policy = $this->policy();

            $target = $this->app->make(EgressGuard::class)->authorize(
                url: (string) $request->getUri(),
                purpose: $purpose,
                mode: $policy->mode,
                allowLan: $policy->allowLan,
            );

            $options['curl'][CURLOPT_RESOLVE] = array_values(array_unique([
                ...(array) ($options['curl'][CURLOPT_RESOLVE] ?? []),
                $target->resolveEntry(),
            ]));

            return $handler($request, $options);
        };
    }

    /**
     * The policy in force. A failure to read it is the strictest mode, not the
     * widest: the one thing this must never do is open the boundary because the
     * table was unreadable (§4, `PrivacyMode::fromConfigValue()`).
     */
    private function policy(): PrivacyPolicyState
    {
        try {
            return $this->app->make(PrivacyModeStore::class)->active();
        } catch (Throwable) {
            return PrivacyPolicyState::strictest();
        }
    }
}
