<?php

declare(strict_types=1);

namespace App\Security\Authorization\Devices;

use Carbon\CarbonImmutable;

/**
 * One row of the Devices page. Deliberately carries no session id.
 */
final readonly class DeviceView
{
    public function __construct(
        public string $id,
        public string $label,
        public LoginMethod $method,
        public ?string $passkeyName,
        public ?string $ipAddress,
        public CarbonImmutable $signedInAt,
        public CarbonImmutable $lastActiveAt,
        public bool $current,
    ) {}
}
