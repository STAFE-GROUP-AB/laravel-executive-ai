<?php

declare(strict_types=1);

use App\Briefs\Generation\PlaceholderBrief;
use App\Console\Commands\OwnerCreateCommand;
use App\Models\Brief;
use App\Models\CRM\Contact;
use App\Models\CRM\ContactEmail;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\User;
use App\Security\Authorization\CreateOwner;
use App\Security\Authorization\OwnerDetails;
use App\Security\Secrets\UnreadableEncryptedAttributeException;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Install\InstallState;
use App\Support\Install\InstallStateStore;
use App\Support\Runtime\RuntimeLayout;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Tests\Support\ApplicationKeys;
use Tests\Support\FreshInstance;

/*
 * S1-13, blueprint §12.2 "data-preserving reinstall", threat model T-72: a data-preserving
 * uninstall leaves <LEA_DATA_DIR> untouched (Swift side: UninstallerTests). Installing again
 * boots the core against that same directory, and the core must come up with the previous
 * owner account and the previous briefs — and refuse to create a second owner through either
 * path. The "reinstall" here is literal: a second application is booted through the real
 * bootstrap/app.php against the same data directory, nothing is carried over in memory.
 *
 * The suite's own database (database/testing.sqlite, DatabaseTransactions) is never used:
 * DB_DATABASE is lifted so the ADR-001 D4 default <LEA_DATA_DIR>/database/executive.sqlite
 * applies, exactly as it does under the Supervisor.
 *
 * Since S2-04 (#87) this file also carries the **A25** acceptance. From the first
 * `encrypted:` cast, "the kept database opens unchanged" is only true if `core.app-key`
 * was kept with it, so the second boot reads an encrypted row back — and then proves
 * what a keep-data uninstall that had deleted the key would have left behind instead.
 */

/** A temporary installer-shaped data directory and two boots against it. */
final class ReinstallFixture
{
    /** @var list<string> */
    private static array $directories = [];

    /** @var array{server: ?string, env: ?string, putenv: string|false} */
    private static array $savedDatabase = ['server' => null, 'env' => null, 'putenv' => false];

    /** The directories the Mac installer creates (InstallPaths.dataSubdirectories). */
    public static function installerShapedDataDirectory(): string
    {
        $directory = sys_get_temp_dir().'/lxai-reinstall-'.bin2hex(random_bytes(6));

        foreach (['database', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'backups'] as $subdirectory) {
            File::ensureDirectoryExists("{$directory}/{$subdirectory}");
        }

        self::$directories[] = $directory;

        return $directory;
    }

    /**
     * Points the process at the data directory the way the Supervisor does (runtime-layout.md
     * §3). The packaged runtime has no .env (ADR-001 D4), so there DB_DATABASE is unset and
     * config/database.php resolves <LEA_DATA_DIR>/database/executive.sqlite; a developer
     * checkout's .env pins DB_DATABASE and would win over that default, so the boot here is
     * pinned to exactly the path the D4 default resolves to (RuntimeLayoutTest proves the
     * default itself) and the connection is asserted to land there.
     */
    public static function pointAt(string $dataDirectory): void
    {
        $_SERVER[RuntimeLayout::DATA_DIR_ENV] = $dataDirectory;

        self::$savedDatabase = ['server' => $_SERVER['DB_DATABASE'] ?? null, 'env' => $_ENV['DB_DATABASE'] ?? null, 'putenv' => getenv('DB_DATABASE')];
        $_SERVER['DB_DATABASE'] = RuntimeLayout::databasePath($dataDirectory).'/'.RuntimeLayout::DATABASE_FILE;
        unset($_ENV['DB_DATABASE']);
        putenv('DB_DATABASE');
    }

    /**
     * One "boot": a fresh application through bootstrap/app.php with the console kernel
     * bootstrapped and pending migrations applied — what the Supervisor's first core start
     * does. The container singleton is handed back to the test application afterwards so the
     * rest of the suite is unaffected.
     *
     * @template T
     *
     * @param  callable(Application): T  $callback
     * @return T
     */
    public static function boot(callable $callback): mixed
    {
        $testApp = app();

        try {
            /** @var Application $app */
            $app = require base_path('bootstrap/app.php');
            $app->make(ConsoleKernel::class)->bootstrap();
            $app->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);

            return $callback($app);
        } finally {
            Container::setInstance($testApp);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($testApp);
        }
    }

    public static function cleanUp(): void
    {
        unset($_SERVER[RuntimeLayout::DATA_DIR_ENV], $_ENV[RuntimeLayout::DATA_DIR_ENV]);
        putenv(RuntimeLayout::DATA_DIR_ENV);

        // phpunit.xml pins DB_DATABASE in $_ENV and putenv, not $_SERVER; the $_SERVER value set
        // by pointAt() must go, or every later test in the process opens the deleted temp file.
        if (is_string(self::$savedDatabase['server'])) {
            $_SERVER['DB_DATABASE'] = self::$savedDatabase['server'];
        } else {
            unset($_SERVER['DB_DATABASE']);
        }

        if (is_string(self::$savedDatabase['env'])) {
            $_ENV['DB_DATABASE'] = self::$savedDatabase['env'];
        } else {
            unset($_ENV['DB_DATABASE']);
        }

        if (is_string(self::$savedDatabase['putenv'])) {
            putenv('DB_DATABASE='.self::$savedDatabase['putenv']);
        }

        foreach (self::$directories as $directory) {
            File::deleteDirectory($directory);
        }

        self::$directories = [];
    }
}

afterEach(fn () => ReinstallFixture::cleanUp());

it('boots a reinstall over preserved data to the previous owner and the previous briefs, and refuses a second owner', function (): void {
    $dataDir = ReinstallFixture::installerShapedDataDirectory();
    ReinstallFixture::pointAt($dataDir);

    // First install: the owner is created and a brief exists, in the data directory's database.
    $firstInstall = ReinstallFixture::boot(function (Application $app) use ($dataDir): array {
        expect($app->make(DatabaseManager::class)->connection()->getDatabaseName())->toBe("{$dataDir}/database/executive.sqlite");

        $created = $app->make(CreateOwner::class)->create(new OwnerDetails('Ada Owner', 'owner@example.test', 'Example Organization', 'Europe/Stockholm', 'sv'), 'job:test-install');
        $brief = $app->make(PlaceholderBrief::class)->create($created->organization);
        $app->make(InstallStateStore::class)->transition(InstallState::CoreReady, 'HEALTH_CHECK');

        return ['user_id' => $created->user->id, 'organization_id' => $created->organization->id, 'brief_id' => $brief->id, 'document_id' => $brief->document_id];
    });

    expect(File::exists("{$dataDir}/database/executive.sqlite"))->toBeTrue('the database lives in the preserved data directory');

    // Uninstall keeping data (the Swift side removes everything but data/), then reinstall:
    // a fresh boot of the core against the same, untouched directory.
    ReinstallFixture::boot(function (Application $app) use ($dataDir, $firstInstall): void {
        expect($app->make(DatabaseManager::class)->connection()->getDatabaseName())->toBe("{$dataDir}/database/executive.sqlite");

        $owner = User::query()->find($firstInstall['user_id']);
        $membership = Membership::query()->where('user_id', $firstInstall['user_id'])->where('role', MembershipRole::Owner->value)->first();
        $brief = Brief::query()->find($firstInstall['brief_id']);

        expect($owner)->not->toBeNull('the previous owner account is there')
            ->and($owner?->email)->toBe('owner@example.test')
            ->and($membership?->organization_id)->toBe($firstInstall['organization_id'])
            ->and($app->make(CreateOwner::class)->ownerExists())->toBeTrue()
            ->and($brief)->not->toBeNull('the previous brief is there')
            ->and($brief?->document_id)->toBe($firstInstall['document_id'])
            ->and(Brief::query()->count())->toBe(1)
            ->and($app->make(InstallStateStore::class)->current())->toBe(InstallState::CoreReady, 'the install marker survives too');

        // T-72: the in-app CREATE_OWNER screen is gone because an owner exists …
        $response = $app->make(HttpKernel::class)->handle(Request::create(FreshInstance::ORIGIN.'/install/owner'));
        expect($response->getStatusCode())->toBe(404);

        // … and the installer's command exits with the distinct owner-exists code.
        $console = $app->make(ConsoleKernel::class);
        $exitCode = $console->call('lea:owner:create', ['--name' => 'Second Person', '--email' => 'second@example.test', '--organization' => 'Another Organization', '--json' => true]);

        expect($exitCode)->toBe(OwnerCreateCommand::OWNER_EXISTS)
            ->and($console->output())->toContain('"status":"owner_exists"')
            ->and(Membership::query()->where('role', MembershipRole::Owner->value)->count())->toBe(1)
            ->and(User::query()->where('email', 'second@example.test')->exists())->toBeFalse();
    });
});

it('starts with no owner on a fresh data directory, so delete-everything really starts over', function (): void {
    $dataDir = ReinstallFixture::installerShapedDataDirectory();
    ReinstallFixture::pointAt($dataDir);

    ReinstallFixture::boot(function (Application $app): void {
        expect($app->make(CreateOwner::class)->ownerExists())->toBeFalse()
            ->and(Brief::query()->count())->toBe(0)
            ->and($app->make(InstallStateStore::class)->current())->toBe(InstallState::NotInstalled);

        $response = $app->make(HttpKernel::class)->handle(Request::create(FreshInstance::ORIGIN.'/install/owner'));
        expect($response->getStatusCode())->toBe(200);
    });
});

it('reads every encrypted row back after a keep-data uninstall, because A25 keeps core.app-key', function (): void {
    $dataDir = ReinstallFixture::installerShapedDataDirectory();
    ReinstallFixture::pointAt($dataDir);

    // First install: an owner, and a contact whose phone list and email address are
    // §3.1 sensitive attributes — encrypted at rest from S2-04 (#87) on.
    $firstInstall = ReinstallFixture::boot(function (Application $app): array {
        $created = $app->make(CreateOwner::class)->create(new OwnerDetails('Ada Owner', 'owner@example.test', 'Example Organization', 'Europe/Stockholm', 'sv'), 'job:test-install');

        $contact = Contact::query()->create([
            'organization_id' => $created->organization->id,
            'given_name' => 'Karin',
            'family_name' => 'Nordlund',
            'display_name' => 'Karin Nordlund',
            'title' => 'Platschef',
            'phones' => [['number' => '+46 70 123 45 67', 'label' => 'mobile']],
            'language' => 'sv',
            'notes' => 'Vill helst ha mejl.',
            'sensitivity_class' => 'personal',
            'provenance' => Provenance::job('ReinstallPreservesDataTest', ProvenanceOrigin::Manual),
        ]);

        $email = ContactEmail::query()->create([
            'organization_id' => $created->organization->id,
            'contact_id' => $contact->id,
            'address' => 'karin.nordlund@example.test',
            'label' => 'work',
            'is_primary' => true,
            'position' => 0,
            'sensitivity_class' => 'personal',
            'provenance' => Provenance::job('ReinstallPreservesDataTest', ProvenanceOrigin::Manual),
        ]);

        $app->make(InstallStateStore::class)->transition(InstallState::CoreReady, 'HEALTH_CHECK');

        // The address really is ciphertext in the file that is about to be kept.
        $stored = (string) $app->make(DatabaseManager::class)->connection()
            ->table('contact_emails')->where('id', $email->id)->value('address');

        expect($stored)->not->toContain('karin.nordlund@example.test');

        return ['contact_id' => $contact->id, 'email_id' => $email->id, 'key' => (string) $app['config']['app.key']];
    });

    // The keep-data uninstall: everything but `data/` goes, and — this is **A25**,
    // option (a), decided 2026-10-11 — `core.app-key` stays. The Supervisor therefore
    // hands the next boot the same `laravel_app_key` envelope, which the unchanged
    // APP_KEY models here.
    ReinstallFixture::boot(function (Application $app) use ($firstInstall): void {
        expect((string) $app['config']['app.key'])->toBe($firstInstall['key'], 'the kept install boots on the kept application key');

        $contact = Contact::query()->findOrFail($firstInstall['contact_id']);
        $email = ContactEmail::query()->findOrFail($firstInstall['email_id']);

        expect($email->address)->toBe('karin.nordlund@example.test', 'the encrypted address came back')
            ->and($contact->phones)->toBe([['number' => '+46 70 123 45 67', 'label' => 'mobile']], 'the encrypted phone list came back')
            // The lookup §5.6 depends on survives the reinstall too: the blind index
            // is keyed from the same application key, so it still resolves.
            ->and(ContactEmail::query()->forAddress('karin.nordlund@example.test')->firstOrFail()->id)->toBe($firstInstall['email_id']);

        // And what A25's rejected option (c) would have left instead: the data kept,
        // the key gone, the rows unreadable — and a failure that is loud rather than an
        // empty field in a brief.
        ApplicationKeys::with(ApplicationKeys::fresh(), [], function () use ($firstInstall): void {
            expect(fn () => ContactEmail::query()->findOrFail($firstInstall['email_id'])->address)
                ->toThrow(UnreadableEncryptedAttributeException::class);
        });
    });
});
