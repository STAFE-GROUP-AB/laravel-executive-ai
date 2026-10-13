<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;
use Throwable;

/**
 * An encrypted §3.1 attribute could not be decrypted with `core.app-key` or any key
 * in the envelope's `previous[]` (ADR-003 D1, D8).
 *
 * It exists so the failure is *comprehensible*. Laravel's own failure here is
 * `DecryptException: The MAC is invalid.`, which says nothing about what was lost or
 * what to do, and the alternatives are worse: a caller that swallows the exception
 * reads an empty string, and a caller that treats the stored ciphertext as the value
 * reads base64 noise. Either would be a silent data-integrity failure in the middle
 * of a brief. This fails closed and names the row, the application key and the one
 * decision (A25) that explains how the key and the data can get out of step.
 *
 * **It never carries a value.** The message is the model class, the attribute name
 * and a pointer at the documentation — no ciphertext, no plaintext, no key — because
 * an exception message reaches `storage/logs` through the ADR-003 D7 redaction points
 * and `tests/Security/ExceptionRedactionTest.php` holds that line.
 */
final class UnreadableEncryptedAttributeException extends RuntimeException
{
    public static function for(string $model, string $attribute, ?Throwable $previous = null): self
    {
        return new self(
            "{$model}::\${$attribute} is encrypted at rest and cannot be decrypted with the current application key. "
            .'The row was written under a different `core.app-key`: see docs/operations/uninstall.md §5 — a keep-data '
            .'uninstall keeps the application key, a delete-everything uninstall takes it with the data, and a key '
            .'that is gone cannot be recovered.',
            0,
            $previous,
        );
    }

    /** True when `$throwable` is the failure this exception is meant to replace. */
    public static function wraps(Throwable $throwable): bool
    {
        return $throwable instanceof DecryptException;
    }
}
