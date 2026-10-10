<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every suite boots the application through Tests\TestCase so config(),
| the SQLite connection and the HTTP kernel are available. Suites that
| touch the database use DatabaseTransactions (never the refresh-database trait).
|
*/

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Integration', 'Security', 'Performance', 'E2E');
