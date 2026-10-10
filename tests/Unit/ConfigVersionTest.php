<?php

declare(strict_types=1);

it('keeps config(app.version) identical to the composer.json version', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($composer)->toHaveKey('version')
        ->and(config('app.version'))->toBe($composer['version'])
        ->toMatch('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/');
});
