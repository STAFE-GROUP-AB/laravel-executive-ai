<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use RuntimeException;

/**
 * The core could not read `K` (secrets broker down or locked, ADR-003 D3.2).
 * The middleware maps it to 503 / secrets_unavailable. The message never
 * carries key material.
 */
final class IpcKeyUnavailableException extends RuntimeException {}
