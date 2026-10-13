<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Security\Support\TenantRowFactory;

uses(DatabaseTransactions::class);

/*
|--------------------------------------------------------------------------
| The sweep's engine (S2-06, issue #88)
|--------------------------------------------------------------------------
|
| `IdorTest` and `CrossAccountContractTest` claim to cover **every** table that
| carries `organization_id`, and they get the rows from `TenantRowFactory`
| rather than from a factory per table. That claim is only as good as the
| factory: a table whose shape it cannot build would be skipped, and a skipped
| table in an IDOR sweep is a table nobody is testing while the suite reports
| green.
|
| So the factory is tested on its own, against the whole schema. If a Sprint 3
| migration introduces a column shape the builder cannot satisfy — a required
| column with no usable default, a foreign-key cycle, an enum whose cases it
| cannot read — this file fails on the branch that adds it, with the table
| named, instead of the sweep quietly shrinking.
|
*/

it('builds a real row for every tenant table', function (): void {
    $factory = new TenantRowFactory(Organization::factory()->create(), User::factory()->create());
    $tables = TenantRowFactory::tenantTables();

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        $id = $factory->insert($table);
        expect($id)->toHaveLength(26, $table);
    }

    foreach (array_keys(TenantRowFactory::modelsByTable()) as $table) {
        if (! in_array($table, $tables, true)) {
            continue;
        }

        expect($factory->model($table)->getAttribute('organization_id'))->toBeString($table);
    }
})->group('security');
