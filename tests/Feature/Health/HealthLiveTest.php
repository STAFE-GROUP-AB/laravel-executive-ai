<?php

declare(strict_types=1);

use Tests\Support\JsonSchema;

use function Pest\Laravel\get;
use function Pest\Laravel\withServerVariables;

it('answers /health/live exactly like the live-ok fixture', function (): void {
    $fixture = json_decode((string) file_get_contents(base_path('packaging/fixtures/health/live-ok.json')), true, 512, JSON_THROW_ON_ERROR);

    $response = get('/health/live')->assertOk();

    expect($response->json())->toBe($fixture)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Type'))->toBe('application/json; charset=utf-8')
        ->and(JsonSchema::errors(JsonSchema::fixturePath('health.schema.json'), $response->json()))->toBe([]);
});

it('refuses non-loopback callers on both health routes', function (string $route): void {
    withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->get($route)->assertForbidden();
})->with(['/health/live', '/health/ready']);

it('no longer exposes the stock /up route', function (): void {
    get('/up')->assertNotFound();
});
