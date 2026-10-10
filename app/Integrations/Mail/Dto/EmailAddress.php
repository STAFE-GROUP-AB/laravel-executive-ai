<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use InvalidArgumentException;

/**
 * One RFC 5322 mailbox, normalised. `address` is always lower-case, trimmed and free
 * of whitespace, angle brackets and control characters, so that the §5.6 identity
 * mapping (`email_identity_mappings`), the CRM contact match and the §11 "locked
 * recipients" comparison are plain string equality. `name` is the display name with
 * CR/LF removed: header injection through a display name is not expressible.
 *
 * Use `normalize()` for text coming from a provider or a user; the constructor is
 * strict and refuses anything that is not already canonical.
 */
final readonly class EmailAddress
{
    public function __construct(
        public string $address,
        public ?string $name = null,
    ) {
        if ($this->address === '' || mb_strtolower($this->address) !== $this->address) {
            throw new InvalidArgumentException('An email address must be lower-case and non-empty.');
        }

        if (preg_match('/^[^\s@<>,;"\\\\\x00-\x1f\x7f]+@[^\s@<>,;"\\\\\x00-\x1f\x7f]+$/u', $this->address) !== 1) {
            throw new InvalidArgumentException('An email address must be a single `local@domain` mailbox without whitespace or control characters.');
        }

        if ($this->name !== null && preg_match('/[\r\n\x00]/', $this->name) === 1) {
            throw new InvalidArgumentException('A display name may not contain line breaks or NUL.');
        }
    }

    /**
     * Canonicalise provider or user input: trims, strips a surrounding `<...>`,
     * lower-cases, collapses the display name onto one line. Throws for anything
     * that still is not a single mailbox.
     */
    public static function normalize(string $address, ?string $name = null): self
    {
        $address = trim($address);

        if (str_starts_with($address, '<') && str_ends_with($address, '>')) {
            $address = substr($address, 1, -1);
        }

        $name = $name === null ? null : trim((string) preg_replace('/[\r\n\x00]+/', ' ', $name));

        return new self(mb_strtolower($address), $name === '' ? null : $name);
    }

    public function equals(self $other): bool
    {
        return $this->address === $other->address;
    }

    public function domain(): string
    {
        return substr($this->address, (int) strrpos($this->address, '@') + 1);
    }
}
