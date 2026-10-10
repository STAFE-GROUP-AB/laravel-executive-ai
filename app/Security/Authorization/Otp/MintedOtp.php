<?php

declare(strict_types=1);

namespace App\Security\Authorization\Otp;

use SensitiveParameter;

/**
 * A freshly generated code together with its record. The only object in the
 * process that holds the plaintext; it is handed to the mailable (or to the
 * autofill path in `local`) and then dropped. Dumping it shows no code.
 */
final class MintedOtp
{
    public function __construct(
        public readonly OtpCode $record,
        #[SensitiveParameter] private readonly string $code,
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['record' => $this->record->id, 'code' => '[REDACTED]'];
    }
}
