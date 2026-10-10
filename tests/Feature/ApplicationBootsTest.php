<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

uses(DatabaseTransactions::class);

it('serves the root route', function (): void {
    get('/')->assertOk();
});

it('runs on the ADR-002 local backend profile', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('queue.default'))->toBe('database');
});

it('opens SQLite in WAL mode with the ADR-002 connection settings', function (): void {
    expect(config('database.connections.sqlite.busy_timeout'))->toBe(5000)
        ->and(config('database.connections.sqlite.journal_mode'))->toBe('WAL')
        ->and(config('database.connections.sqlite.synchronous'))->toBe('NORMAL')
        ->and(config('database.connections.sqlite.transaction_mode'))->toBe('IMMEDIATE')
        ->and(DB::scalar('PRAGMA journal_mode'))->toBe('wal');
});
