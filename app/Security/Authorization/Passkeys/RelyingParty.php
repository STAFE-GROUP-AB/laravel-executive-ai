<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

use Illuminate\Http\Request;

/**
 * The WebAuthn relying party for the origin a request arrived on (ADR-006
 * D3: one process, two origins). `rp.id` is the request host, so a passkey
 * registered on the loopback origin is bound to `localhost` and one
 * registered over Tailscale Serve to the tailnet name; neither works on the
 * other origin, which is what the standard requires. A credential is only
 * ever verified against the exact origin its challenge was issued for.
 *
 * WebAuthn needs a domain name as the RP id: browsers refuse an IP literal,
 * so `http://127.0.0.1:<port>` cannot run a ceremony at all. The authenticator
 * reports that honestly instead of failing inside the browser.
 */
final readonly class RelyingParty
{
    public function __construct(
        public string $id,
        public string $origin,
        public string $name,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            id: strtolower(trim($request->getHost(), '[]')),
            origin: $request->getSchemeAndHttpHost(),
            name: (string) config('app.name', 'Laravel Executive AI'),
        );
    }

    /** Browsers only run WebAuthn in a secure context: HTTPS, or plain HTTP on `localhost`. */
    public function isSecureContext(): bool
    {
        if (str_starts_with($this->origin, 'https://')) {
            return true;
        }

        return $this->id === 'localhost' || str_ends_with($this->id, '.localhost');
    }

    public function isIpLiteral(): bool
    {
        return filter_var($this->id, FILTER_VALIDATE_IP) !== false;
    }

    public function supportsPasskeys(): bool
    {
        return $this->id !== '' && ! $this->isIpLiteral() && $this->isSecureContext();
    }
}
