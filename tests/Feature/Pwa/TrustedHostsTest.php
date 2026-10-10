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

it('serves the loopback host names, with or without a port', function (string $host): void {
    get("http://{$host}/offline")->assertOk()->assertHeader('X-Lxai-Origin', $host);
})->with(['localhost', '127.0.0.1', 'localhost:8000', '127.0.0.1:8080']);

it('ignores a forwarded host from a client that is not the loopback proxy', function (): void {
    get('http://localhost/offline', ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertHeader('X-Lxai-Origin', 'localhost');
});
