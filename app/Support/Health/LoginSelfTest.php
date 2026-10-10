<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Models\Membership;
use App\Models\MembershipRole;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Throwable;

/**
 * The login self-test behind `auth_login` (health contract §2): an owner
 * membership exists and the web guard's user provider can resolve that
 * owner. Run by `health:selftest`, never on request. S1-02 extends it with
 * the OTP session round-trip against a synthetic request.
 */
final class LoginSelfTest
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Heartbeats $heartbeats,
    ) {}

    public function run(): bool
    {
        $passed = $this->resolveOwner();

        $this->heartbeats->record(Heartbeats::LOGIN_SELFTEST, ['passed' => $passed]);

        return $passed;
    }

    private function resolveOwner(): bool
    {
        try {
            $owner = Membership::query()->where('role', MembershipRole::Owner->value)->first();

            if ($owner === null) {
                return false;
            }

            $provider = $this->auth->guard('web')->getProvider();

            return $provider->retrieveById($owner->user_id) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
