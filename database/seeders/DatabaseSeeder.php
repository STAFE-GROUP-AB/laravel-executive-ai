<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Deliberately empty. The owner account is created by the installer's
 * CREATE_OWNER step (§12.1) and logs in with an emailed one-time code
 * (S1-02); there is no seeded user and no password anywhere. Development
 * fixtures live under packaging/fixtures/ and are loaded by tests.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
