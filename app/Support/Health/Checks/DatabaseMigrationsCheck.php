<?php

declare(strict_types=1);

namespace App\Support\Health\Checks;

use App\Support\Health\CheckOutcome;
use App\Support\Health\HealthCheck;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * The database opens and every bundled migration has run; zero pending
 * (contract §2). One query on the migrations table against the bundled list.
 */
final class DatabaseMigrationsCheck implements HealthCheck
{
    public function __construct(private readonly Migrator $migrator) {}

    public function name(): string
    {
        return 'database_migrations';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function run(): CheckOutcome
    {
        try {
            if (! $this->migrator->repositoryExists()) {
                return CheckOutcome::fail('migrations table missing; database not initialised');
            }

            $ran = $this->migrator->getRepository()->getRan();
        } catch (Throwable) {
            return CheckOutcome::fail('database cannot be opened');
        }

        $bundled = array_keys($this->migrator->getMigrationFiles($this->paths()));
        $pending = array_values(array_diff($bundled, $ran));
        $latest = $ran === [] ? 'none' : end($ran);

        if ($pending !== []) {
            return CheckOutcome::fail(sprintf('schema at %s; %d pending', $latest, count($pending)));
        }

        return CheckOutcome::ok("schema at {$latest}; 0 pending");
    }

    /** @return array<int, string> */
    private function paths(): array
    {
        return array_values(array_unique([database_path('migrations'), ...$this->migrator->paths()]));
    }
}
