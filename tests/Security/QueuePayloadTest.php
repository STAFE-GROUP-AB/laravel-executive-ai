<?php

declare(strict_types=1);

use App\Security\Queue\RedactingFailedJobProvider;
use App\Security\Secrets\SecretPatterns;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Security\Support\FailsWithSecretJob;
use Tests\Support\SecretsCorpus;

use function Pest\Laravel\artisan;

uses(DatabaseTransactions::class);

/*
 * ADR-003 D7 row 3 / threat model T-11, T-12: a job that fails with a secret
 * in its exception leaves nothing in `jobs`, `failed_jobs` or the log.
 */

it('binds the redacting failed-job provider for the database-uuids driver', function (): void {
    expect(app('queue.failer'))->toBeInstanceOf(RedactingFailedJobProvider::class);
});

it('persists a failed job with the exception redacted and the payload free of secrets', function (): void {
    $directory = sys_get_temp_dir().'/lea-queue-redaction-'.uniqid();
    mkdir($directory, 0700, true);
    config(['logging.channels.single.path' => $directory.'/laravel.log', 'logging.channels.stack.channels' => ['single']]);

    $prose = SecretsCorpus::proseWithEverySecret();
    Cache::put('test-secret:ref-1', $prose, 60);

    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();

    FailsWithSecretJob::dispatch('ref-1')->onConnection('database')->onQueue('default');

    $payload = (string) DB::table('jobs')->value('payload');
    expect(SecretsCorpus::leaksIn($payload))->toBe([])
        ->and($payload)->toContain('ref-1');

    try {
        artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default', '--tries' => 1])->run();
        $written = file_exists($directory.'/laravel.log') ? (string) file_get_contents($directory.'/laravel.log') : '';
    } finally {
        File::deleteDirectory($directory);
    }

    $failed = DB::table('failed_jobs')->first();

    expect($failed)->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(SecretsCorpus::leaksIn((string) $failed->exception))->toBe([])
        ->and((string) $failed->exception)->toContain('provider rejected credentials: ')
        ->toContain(SecretPatterns::PLACEHOLDER)
        ->and(SecretsCorpus::leaksIn((string) $failed->payload))->toBe([])
        ->and(SecretsCorpus::leaksIn($written))->toBe([]);
});
