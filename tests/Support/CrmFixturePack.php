<?php

declare(strict_types=1);

namespace Tests\Support;

use App\CRM\Import\CrmPackMapper;
use App\Models\Organization;
use App\Models\User;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the frozen S0-18 CRM fixture pack (`packaging/fixtures/crm/*.json`) and
 * imports it as it stands — same ULIDs, same organization, same owner — so the
 * tests measure the schema against the committed oracle rather than against
 * factories. Users are not part of the pack: the owner's ULID is read from
 * `customers[0].owner_user_id`, as `packaging/fixtures/README.md` prescribes.
 */
final class CrmFixturePack
{
    /** @return array<string, list<array<string, mixed>>> */
    public static function load(): array
    {
        $pack = [];

        foreach (['customers', 'contacts', 'projects', 'tasks', 'communications', 'commitments'] as $entity) {
            $file = base_path("packaging/fixtures/crm/{$entity}.json");
            $contents = file_get_contents($file);

            if ($contents === false) {
                throw new RuntimeException("CRM fixture {$file} cannot be read.");
            }

            /** @var list<array<string, mixed>> $records */
            $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $pack[$entity] = $records;
        }

        return $pack;
    }

    public static function organizationId(): string
    {
        /** @var string $id */
        $id = self::load()['customers'][0]['organization_id'];

        return $id;
    }

    public static function ownerUserId(): string
    {
        /** @var string $id */
        $id = self::load()['customers'][0]['owner_user_id'];

        return $id;
    }

    /**
     * Imports the pack into the current connection and returns it.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function import(): array
    {
        $pack = self::load();
        self::seedTenancy($pack);

        app(CrmPackMapper::class)->import($pack);

        return $pack;
    }

    /**
     * The corpus the `search` contract group reads, imported **committed**.
     *
     * It has to be committed: InnoDB publishes rows to a FULLTEXT index when
     * their transaction commits, so a corpus written inside the DatabaseTransactions
     * transaction is invisible to `MATCH … AGAINST` on the `server` profile
     * (measured on MySQL 9.3). To leave the pack's own ULIDs free for the
     * transactional tests, every ULID is mirrored onto one starting with `7`
     * (`mirrorId()`); the pack's ids all start with `0`, so relative order — and
     * therefore the "ids ascending" part of the contract — is unchanged.
     *
     * Because it is committed it is also short-lived: the search contract file
     * imports it before its first test and calls `forgetSearchCorpus()` after its
     * last, so no other test ever sees a workspace, an owner or a CRM row it did
     * not create. Testing environment only. Nothing is dropped, truncated or
     * refreshed — rows are deleted by organization id.
     *
     * @return array<string, list<array<string, mixed>>> the mirrored pack
     */
    public static function importSearchCorpus(): array
    {
        self::assertTestingEnvironment();

        $mirrored = self::mirror(self::load());

        self::forgetSearchCorpus();
        self::seedTenancy($mirrored);

        app(CrmPackMapper::class)->import($mirrored);

        return $mirrored;
    }

    /** Removes the search corpus, its workspace and its owner. */
    public static function forgetSearchCorpus(): void
    {
        self::assertTestingEnvironment();

        $organizationId = self::mirrorId(self::organizationId());

        self::forget($organizationId);

        DB::table('memberships')->where('organization_id', $organizationId)->delete();
        DB::table('organizations')->where('id', $organizationId)->delete();
        DB::table('users')->where('id', self::mirrorId(self::ownerUserId()))->delete();
    }

    private static function assertTestingEnvironment(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('The CRM search corpus may only be written in the testing environment.');
        }
    }

    /** The search corpus counterpart of a pack ULID. */
    public static function mirrorId(string $id): string
    {
        return '7'.mb_substr($id, 1);
    }

    /**
     * Rewrites every bare ULID — and the ULID inside a `user:<ulid>` provenance
     * actor — onto its mirrored form, leaving all other values untouched.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function mirror(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::mirror($item);

                continue;
            }

            if (! is_string($item)) {
                continue;
            }

            if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $item) === 1) {
                $value[$key] = self::mirrorId($item);

                continue;
            }

            if (preg_match('/^user:([0-7][0-9A-HJKMNP-TV-Z]{25})$/', $item, $matches) === 1) {
                $value[$key] = 'user:'.self::mirrorId($matches[1]);
            }
        }

        return $value;
    }

    /** Removes one organization's CRM rows, children first. Never a whole table. */
    private static function forget(string $organizationId): void
    {
        foreach (['commitments', 'communications', 'task_assignees', 'tasks', 'project_contact', 'project_links', 'projects', 'customer_contact', 'contact_emails', 'contacts', 'customers'] as $table) {
            DB::table($table)->where('organization_id', $organizationId)->delete();
        }
    }

    /** @param array<string, list<array<string, mixed>>> $pack */
    private static function seedTenancy(array $pack): void
    {
        /** @var string $organizationId */
        $organizationId = $pack['customers'][0]['organization_id'];
        /** @var string $ownerUserId */
        $ownerUserId = $pack['customers'][0]['owner_user_id'];

        Organization::query()->firstOrCreate(
            ['id' => $organizationId],
            [
                'name' => 'CRM fixture workspace',
                'slug' => 'crm-fixture-'.mb_strtolower($organizationId),
                'timezone' => 'Europe/Stockholm',
                'language' => 'sv',
                'sensitivity_class' => 'internal',
                'provenance' => Provenance::job('CrmFixtureImport', ProvenanceOrigin::Import),
            ],
        );

        // `id` is not fillable on User (#[Fillable(['name', 'email'])]), so the
        // pack's owner ULID has to be force-filled or Eloquent would generate one.
        // No membership is created: an owner membership is what `/health/ready`
        // reads as "the owner account exists", and this fixture is not an install.
        if (User::query()->find($ownerUserId) === null) {
            (new User)->forceFill([
                'id' => $ownerUserId,
                'name' => 'Fixture owner',
                'email' => 'owner+'.mb_strtolower($ownerUserId).'@example.com',
            ])->save();
        }
    }
}
