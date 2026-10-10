<?php

declare(strict_types=1);

it('runs the ADR-002 local backend profile by default', function (): void {
    expect(config('lea.backend_profile'))->toBe('local')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(config('queue.default'))->toBe('database')
        ->and(config('queue.failed.driver'))->toBe('database-uuids');
});

it('only knows the two ADR-002 profiles', function (): void {
    expect(config('lea.backend_profile'))->toBeIn(['local', 'server']);
});

it('reports a valid privacy mode and no AI provider out of the box', function (): void {
    expect(config('lea.privacy_mode'))->toBeIn(['LOCAL_ONLY', 'EU_RESTRICTED', 'CUSTOM'])
        ->and(config('lea.ai.provider'))->toBeNull();
});
