<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;

use function Pest\Laravel\get;

/*
 * ADR-006 D3 / threat model T-18: the core trusts only loopback host names.
 * A request that reaches the listener with any other Host header (DNS
 * rebinding from a hostile page, another local process spoofing
 * X-Forwarded-Host) is refused before ResolveRequestOrigin would turn that
 * host into the URL root. The exact tailnet name is added by E4-1.
 *
 * The host is given as an absolute URL: Laravel's test client derives
 * HTTP_HOST from the request URL and overrides a Host header.
 */

uses(DatabaseTransactions::class);

it('refuses a request whose Host header is not a loopback name', function (string $host): void {
    get("http://{$host}/offline")->assertStatus(400);
    get("http://{$host}/home")->assertStatus(400);
})->with(['evil.example', 'localhost.evil.example', 'app.localhost', '127.0.0.1.nip.io', '10.0.0.5']);

it('serves the canonical loopback host name, with or without a port', function (string $host): void {
    get("http://{$host}/offline")->assertOk()->assertHeader('X-Lxai-Origin', $host);
})->with(['localhost', 'localhost:8000']);

/*
 * The IP literals are trusted — the listener answers on them and the IPC and
 * health channels require them (ipc-contract §1.1) — but a *browser* route on
 * one is a 302 to the same path on `localhost`, the one host a passkey can
 * exist on (ADR-006 D3, #109). CanonicalHostTest owns that behaviour; this
 * only records that trust and canonical are two different questions.
 */
it('trusts the loopback IP literals but does not serve browser routes on them', function (string $host): void {
    get("http://{$host}/offline")
        ->assertRedirect('http://localhost/offline')
        ->assertHeader('X-Lxai-Origin', $host);
})->with(['127.0.0.1', '[::1]']);

it('ignores a forwarded host from a client that is not the loopback proxy', function (): void {
    get('http://localhost/offline', ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertHeader('X-Lxai-Origin', 'localhost');
});
