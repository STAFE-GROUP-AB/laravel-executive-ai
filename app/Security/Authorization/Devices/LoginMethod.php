<?php

declare(strict_types=1);

namespace App\Security\Authorization\Devices;

/**
 * How a session was established. There is deliberately no `password` case.
 */
enum LoginMethod: string
{
    case Otp = 'otp';

    case Passkey = 'passkey';

    case OwnerCreation = 'owner_creation';
}
