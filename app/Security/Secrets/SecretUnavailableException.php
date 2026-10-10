<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use RuntimeException;

/**
 * The broker refused or could not be reached (down, locked, not found,
 * forbidden). The message is a category, never key material.
 */
final class SecretUnavailableException extends RuntimeException {}
