<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Briefs\Generation\PlaceholderBrief;
use App\Models\Brief;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Health\Heartbeats;
use App\Support\Health\LoginSelfTest;

/**
 * Puts the database and cache into the §12.2 core-ready state the installer
 * reaches after INSTALL_CORE -> CREATE_OWNER -> BOOT_SERVICES: an owner, the
 * placeholder brief and fresh heartbeats from the menu bar, worker and
 * scheduler. Tests run it inside DatabaseTransactions.
 */
final class CoreReadyState
{
    public static function create(): Brief
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        Membership::factory()->owner()->for($organization)->for($owner)->create();

        $brief = app(PlaceholderBrief::class)->create($organization);

        self::heartbeats();

        return $brief;
    }

    public static function heartbeats(): void
    {
        $heartbeats = app(Heartbeats::class);
        $heartbeats->record(Heartbeats::MENUBAR, ['client_version' => '1.0.0 (1)']);
        $heartbeats->record(Heartbeats::QUEUE_WORKER);
        $heartbeats->record(Heartbeats::SCHEDULER);

        app(LoginSelfTest::class)->run();
    }
}
