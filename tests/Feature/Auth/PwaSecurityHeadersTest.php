<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveRequestOrigin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\Support\OwnerAccount;

use function Pest\Laravel\get;

/*
 * Open question A16 / threat model T-21, T-22: the browser-hardening header
 * set on every PWA response, and ADR-006 D3's cookie posture on loopback.
 * S1-04's app shell ships inside these headers.
 */

uses(DatabaseTransactions::class);

/** The host the test client sends, port included — what ADR-006 D3 says X-Lxai-Origin echoes (`127.0.0.1:<port>` on loopback). */
function requestHttpHost(): string
{
    $url = parse_url((string) config('app.url'));
    $host = $url['host'] ?? 'localhost';

    return isset($url['port']) ? "{$host}:{$url['port']}" : $host;
}

beforeEach(function (): void {
    OwnerAccount::clear();
    OwnerAccount::create();
});

it('sends the full header set on the login page', function (): void {
    $response = get('/login')->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'none'")
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("connect-src 'self'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Permissions-Policy'))->toBe(SecurityHeaders::PERMISSIONS_POLICY)
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('microphone=()')->toContain('geolocation=()')
        ->and($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get(ResolveRequestOrigin::HEADER))->toBe(requestHttpHost());
});

it('puts the per-request nonce on every script tag it renders and rotates it per request', function (): void {
    $first = get('/login')->assertOk();
    $second = get('/login')->assertOk();

    preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $first->headers->get('Content-Security-Policy'), $firstMatch);
    preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $second->headers->get('Content-Security-Policy'), $secondMatch);

    $nonce = $firstMatch[1] ?? '';
    $html = (string) $first->getContent();

    expect($nonce)->not->toBe('')
        ->and($nonce)->not->toBe($secondMatch[1] ?? '')
        ->and(preg_match_all('/<script\b[^>]*>/', $html))->toBeGreaterThan(0)
        ->and(preg_match_all('/<script\b(?![^>]*\bnonce=")[^>]*>/', $html))->toBe(0, 'every script tag carries the nonce')
        ->and($html)->toContain("nonce=\"{$nonce}\"");
});

it('hardens every web response, not only the auth pages', function (): void {
    $response = get('/')->assertOk();

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($response->headers->get(ResolveRequestOrigin::HEADER))->toBe(requestHttpHost());
});

it('is a floor: a route that sets its own stricter policy keeps it', function (): void {
    Route::middleware('web')->get('/_test/own-headers', fn () => response('<p>framed preview</p>')
        ->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'")
        ->header('X-Frame-Options', 'SAMEORIGIN')
        ->header('Referrer-Policy', 'no-referrer'));

    $response = get('/_test/own-headers')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; frame-ancestors 'self'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', 'headers the route did not set still get the default')
        ->and($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin');
});

it('issues a host-only, HttpOnly, SameSite=lax session cookie that is not Secure on loopback HTTP', function (): void {
    $cookie = get('/login')->assertOk()->getCookie((string) config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe('lax')
        ->and($cookie?->isSecure())->toBeFalse()
        ->and($cookie?->getDomain())->toBeNull()
        ->and(config('session.secure'))->toBeFalse();
});

it('marks the session cookie Secure when the request itself is HTTPS', function (): void {
    $cookie = get('https://localhost/login')->assertOk()->getCookie((string) config('session.cookie'));

    expect($cookie?->isSecure())->toBeTrue()
        ->and(config('session.secure'))->toBeTrue();
});
