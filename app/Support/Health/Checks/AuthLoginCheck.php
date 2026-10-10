<?php

declare(strict_types=1);

namespace App\Support\Health\Checks;

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Support\Health\CheckOutcome;
use App\Support\Health\HealthCheck;
use App\Support\Health\Heartbeats;

/**
 * An owner account exists and the login self-test passed within its interval
 * (contract §2). Owner-exists is one indexed query; the self-test itself is
 * run by `health:selftest`, never on demand — this check reads its last result.
 */
final class AuthLoginCheck implements HealthCheck
{
    public function __construct(private readonly Heartbeats $heartbeats) {}

    public function name(): string
    {
        return 'auth_login';
    }

    public function dependsOn(): array
    {
        return ['database_migrations'];
    }

    public function run(): CheckOutcome
    {
        $ownerExists = Membership::query()->where('role', MembershipRole::Owner->value)->exists();

        if (! $ownerExists) {
            return CheckOutcome::fail('no owner account; CREATE_OWNER has not completed');
        }

        $selfTest = $this->heartbeats->read(Heartbeats::LOGIN_SELFTEST);

        if ($selfTest === null) {
            return CheckOutcome::fail('owner account exists; login self-test has not run yet');
        }

        $passedAt = $this->heartbeats->lastSeenAt(Heartbeats::LOGIN_SELFTEST);
        $threshold = (int) config('health.thresholds.scheduler_selftest');
        $age = $this->heartbeats->ageSeconds(Heartbeats::LOGIN_SELFTEST) ?? 0;

        if (($selfTest['passed'] ?? false) !== true) {
            return CheckOutcome::fail('owner account exists; login self-test failed '.$passedAt?->toRfc3339String());
        }

        if ($age > $threshold) {
            return CheckOutcome::fail(sprintf('owner account exists; login self-test last passed %ds ago (threshold %ds)', $age, $threshold));
        }

        return CheckOutcome::ok('owner account exists; login self-test passed '.$passedAt?->toRfc3339String());
    }
}
