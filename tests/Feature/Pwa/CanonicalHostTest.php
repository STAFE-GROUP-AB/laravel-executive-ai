<?php

declare(strict_types=1);

use App\Http\Middleware\RedirectToCanonicalHost;
use Illuminate\Foundation\Testing\DatabaseTransactions;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * ADR-006 D3 / issue #109: `localhost` is the one browser origin on loopback,
 * because a WebAuthn relying party cannot be an IP literal, and session
 * cookies are host-only — a session on `127.0.0.1` and a passkey on
 * `localhost` would be two browser identities for one person. A browser
 * request on an IP literal is therefore sent to the same path on `localhost`.
 *
 * The other half of D3 is that `/ipc/*` is the literal `127.0.0.1` by
 * contract (ipc-contract §1.1) and `/health/*` is what the Supervisor probes.
 * Neither may ever be redirected: the Swift client treats a 3xx as a hijacked
 * port and reports the core unreachable (§5.2). Those cases are the point of
 * this file, not an afterthought.
 *
 * The host is given as an absolute URL: Laravel's test client derives
 * HTTP_HOST from the request URL.
 */

uses(DatabaseTransactions::class);

it('redirects a browser route on an IP literal to the same path on localhost', function (string $from, string $to): void {
    $response = get($from)->assertStatus(302)->assertRedirect($to);

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    ['http://127.0.0.1/offline', 'http://localhost/offline'],
    ['http://127.0.0.1:8787/offline', 'http://localhost:8787/offline'],
    ['http://127.0.0.1:8787/login', 'http://localhost:8787/login'],
    ['http://127.0.0.1:8787/briefs/01M4FJ7GY0PZH6YA3D9574XN5K', 'http://localhost:8787/briefs/01M4FJ7GY0PZH6YA3D9574XN5K'],
    ['http://127.0.0.1:8787/home?tab=approvals&v=2', 'http://localhost:8787/home?tab=approvals&v=2'],
    ['http://[::1]:8787/home', 'http://localhost:8787/home'],
]);

it('leaves a request that already arrived on localhost alone', function (): void {
    get('http://localhost:8787/offline')->assertOk()->assertHeader('X-Lxai-Origin', 'localhost:8787');
});

/*
 * A 302 on a POST would become a GET and drop the body. The redirect is for
 * the navigation that puts a person on the wrong host in the first place; a
 * non-idempotent request keeps its method so nothing is silently lost.
 */
it('keeps the method when the request is not a navigation', function (): void {
    post('http://127.0.0.1:8787/logout')->assertStatus(307)->assertRedirect('http://localhost:8787/logout');
});

/*
 * The boundary the change must not cross. `/ipc/*` and `/health/*` are
 * registered in the `api` group, which the redirect is not part of; the
 * middleware also refuses those paths itself, so registering it more widely
 * cannot break the Mac app. An unauthenticated IPC call answers the
 * contract's own 401 envelope, which is proof the request was routed rather
 * than bounced.
 */
it('never redirects the IPC channel on 127.0.0.1', function (string $method, string $path): void {
    $status = call($method, "http://127.0.0.1:8787{$path}")->getStatusCode();

    expect($status)->not->toBeIn([301, 302, 303, 307, 308])
        // 426 `version_unsupported`: these calls carry no `X-Lxai-Ipc-Version`, so the contract's
        // own §5.1 check answers — which is the proof that the request was routed, not bounced.
        ->and($status)->toBe(426);
})->with([
    ['GET', '/ipc/v1/health'],
    ['GET', '/ipc/v1/briefs/latest'],
    ['GET', '/ipc/v1/approvals'],
    ['POST', '/ipc/v1/heartbeat'],
    ['POST', '/ipc/v1/ai/pause'],
    ['GET', '/health/ready'],
]);

it('answers the Supervisor liveness probe on 127.0.0.1 without a redirect', function (): void {
    get('http://127.0.0.1:8787/health/live')->assertOk()->assertJson(['status' => 'ok']);
});

it('names localhost as the canonical host, which is what makes a passkey possible', function (): void {
    expect(RedirectToCanonicalHost::CANONICAL_HOST)->toBe('localhost');
});
