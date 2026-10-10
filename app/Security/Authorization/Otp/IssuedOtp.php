<?php

declare(strict_types=1);

namespace App\Security\Authorization\Otp;

use SensitiveParameter;

/**
 * Result of OtpIssuer::issue(). `reused` means a still-valid code issued
 * within the cooldown was kept and no mail went out; the plaintext is then
 * unknown (only its HMAC is stored) and `code()` is null. A fresh issue
 * carries the plaintext for the `local` autofill path only.
 */
final class IssuedOtp
{
    public function __construct(
        public readonly OtpCode $record,
        public readonly bool $reused,
        public readonly bool $delivered,
        #[SensitiveParameter] private readonly ?string $code = null,
    ) {}

    public function code(): ?string
    {
        return $this->code;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['record' => $this->record->id, 'reused' => $this->reused, 'delivered' => $this->delivered, 'code' => '[REDACTED]'];
    }
}
