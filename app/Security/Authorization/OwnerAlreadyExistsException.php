<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use RuntimeException;

/**
 * Thrown by CreateOwner when an owner membership already exists (threat
 * model T-72): a repeated install must not silently create a second owner.
 */
final class OwnerAlreadyExistsException extends RuntimeException
{
    public function __construct(public readonly string $existingUserId)
    {
        parent::__construct('An owner account already exists; a repeated install cannot create a second one. Sign in with the existing account or run the data-preserving reinstall.');
    }
}
