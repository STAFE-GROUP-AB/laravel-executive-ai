<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    private static bool $schemaBuilt = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSchemaOnce();
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
