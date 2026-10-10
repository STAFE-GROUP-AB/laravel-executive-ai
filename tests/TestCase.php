<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    private static bool $schemaBuilt = false;

    /**
     * The schema is built before the testing traits start, so it exists outside
     * the DatabaseTransactions transaction. Building it afterwards put the DDL of
     * a fresh database/testing.sqlite inside the first test's transaction, where
     * the rollback took the tables with it and every later test in the process
     * failed with "no such table" (seen when a single DatabaseTransactions file
     * is the first to run on a fresh checkout, S1-10 `parity` job).
     *
     * @return array<string, string>
     */
    protected function setUpTraits(): array
    {
        $this->buildSchemaOnce();

        return parent::setUpTraits();
    }

    /**
     * ADR-002 D10: the schema is built once per process with a plain, idempotent
     * `migrate` (pending migrations only, nothing is dropped). Tests that write
     * use DatabaseTransactions; the refresh-database trait is forbidden in this repository.
     */
    private function buildSchemaOnce(): void
    {
        if (self::$schemaBuilt) {
            return;
        }

        Artisan::call('migrate', ['--force' => true]);

        self::$schemaBuilt = true;
    }
}
