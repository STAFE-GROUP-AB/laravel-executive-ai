<?php

declare(strict_types=1);

use App\Http\Middleware\LoopbackOnly;
use App\Security\Ipc\Middleware\IpcAuthenticate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\CoreReadyState;
use Tests\Support\Ipc\IpcClient;

use function Pest\Laravel\withServerVariables;

/*
 * S1-08 criterion 4 / IPC contract §12 `IpcBindTest` (threat model T-17):
 * "the endpoint is not reachable from another host on the network". The
 * Laravel app does not bind a socket itself — the bundled Caddy does
 * (ADR-001 D3, S1-06) — so this asserts the three layers that exist:
 *
 *  1. the runtime Caddyfile template binds 127.0.0.1 only, serves a plain
 *     http://127.0.0.1 site and never 0.0.0.0, [::] or TLS;
 *  2. the loopback peer is a constant in IpcAuthenticate, not configuration
 *     (ADR-006 D6.5), and a non-loopback peer is refused on every IPC and
 *     health route;
 *  3. LoopbackOnly refuses non-loopback peers on /health/* independently.
 */

uses(DatabaseTransactions::class);

const CADDYFILE_TEMPLATE = 'packaging/runtime/templates/Caddyfile';

it('ships a Caddyfile template that binds the loopback interface only', function (): void {
    $path = base_path(CADDYFILE_TEMPLATE);

    expect(file_exists($path))->toBeTrue('the S1-06 runtime Caddyfile template must exist');

    $caddyfile = (string) file_get_contents($path);
    $directives = preg_replace('/^\s*#.*$/m', '', $caddyfile) ?? $caddyfile;

    expect($directives)->toContain('bind 127.0.0.1', 'http://127.0.0.1:{$LEA_HTTP_PORT}', 'auto_https off');

    foreach (['0.0.0.0', '[::]', 'bind ::'] as $forbidden) {
        expect(str_contains($directives, $forbidden))->toBeFalse("Caddyfile must not contain {$forbidden}");
    }

    expect(preg_match('/^\s*tls\b/m', $directives))->toBe(0)
        ->and(preg_match('/^\s*https:\/\//m', $directives))->toBe(0);

    preg_match_all('/^\s*bind\s+(.+)$/m', $directives, $binds);

    expect($binds[1])->toBe(['127.0.0.1']);
});

it('hard-codes the loopback peer in the IPC middleware', function (): void {
    expect(IpcAuthenticate::LOOPBACK_PEER)->toBe('127.0.0.1')
        ->and(config('ipc'))->not->toHaveKey('bind')
        ->and(config('ipc'))->not->toHaveKey('peer');
});

it('refuses a non-loopback peer on every IPC route even with a valid signed request', function (string $path, string $peer): void {
    CoreReadyState::create();

    $response = IpcClient::send('GET', $path, null, ['remote_addr' => $peer]);

    $response->assertStatus(403);
    expect($response->headers->has('X-Lxai-Signature'))->toBeFalse();
})->with(['/ipc/v1/health', '/ipc/v1/briefs/latest', '/ipc/v1/remote-access', '/health/ready'])
    ->with(['192.168.1.20', '10.0.0.5', '100.64.0.7', '203.0.113.9']);

it('keeps LoopbackOnly on the health routes as the independent second layer', function (): void {
    withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->get('/health/ready')->assertForbidden();
    withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->get('/health/live')->assertForbidden();

    expect(app('router')->getRoutes()->getByName('health.ready')?->gatherMiddleware())->toContain(LoopbackOnly::class);
});
