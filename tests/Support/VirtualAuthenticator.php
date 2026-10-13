<?php

declare(strict_types=1);

namespace Tests\Support;

use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

/**
 * A software authenticator for the S2-05 passkey tests: it holds an ES256
 * key pair, signs real assertions and produces the exact JSON shapes
 * `resources/js/passkeys.js` posts, so the tests drive
 * `WebAuthnAuthenticator` through the library's full verification — client
 * data, rpIdHash, flags, attestation object, COSE key, signature and sign
 * counter — rather than mocking it away.
 *
 * Deliberately tiny and self-contained: `none` attestation, user presence and
 * user verification set, no extensions. It is a test double for a security
 * key, not a FIDO implementation, and it is the only place in the suite
 * allowed to build a credential payload.
 */
final class VirtualAuthenticator
{
    /** @var array<string, mixed> OpenSSL private key resource holder */
    private array $keyDetails;

    private \OpenSSLAsymmetricKey $privateKey;

    public readonly string $credentialId;

    public readonly string $aaguid;

    private int $signCount = 0;

    public function __construct(?string $credentialId = null)
    {
        // `private_key_bits` is only the minimum-length check openssl applies before it
        // reads `curve_name`; the key is P-256 either way. Without it, a PHP build whose
        // openssl.cnf has no default refuses to generate anything at all.
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 384]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a P-256 key pair for the virtual authenticator.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('Could not read the virtual authenticator key details.');
        }

        $this->privateKey = $key;
        $this->keyDetails = $details;
        $this->credentialId = $credentialId ?? random_bytes(32);
        $this->aaguid = str_repeat("\0", 16);
    }

    /**
     * The registration response for creation options, exactly as the browser
     * would hand them to Livewire.
     *
     * @param  array<string, mixed>  $options  the WebAuthn JSON creation options
     * @return array<string, mixed>
     */
    public function register(array $options, string $origin, ?string $rpId = null): array
    {
        $clientData = $this->clientDataJson('webauthn.create', (string) $options['challenge'], $origin);
        $authData = $this->authenticatorData($rpId ?? (string) $options['rp']['id'], attested: true);

        $attestationObject = $this->cborMap([
            'fmt' => $this->cborText('none'),
            'attStmt' => $this->cborMap([]),
            'authData' => $this->cborBytes($authData),
        ]);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
                'transports' => ['internal', 'hybrid'],
            ],
        ];
    }

    /**
     * The assertion response for request options.
     *
     * @param  array<string, mixed>  $options  the WebAuthn JSON request options
     * @return array<string, mixed>
     */
    public function assert(array $options, string $origin, string $userHandle, ?string $rpId = null): array
    {
        $this->signCount++;

        $clientData = $this->clientDataJson('webauthn.get', (string) $options['challenge'], $origin);
        $authData = $this->authenticatorData($rpId ?? (string) $options['rpId'], attested: false);
        $signature = $this->sign($authData.hash('sha256', $clientData, true));

        return [
            'id' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded($userHandle),
            ],
        ];
    }

    /** The sign counter the authenticator will report next, so a test can replay an older one. */
    public function rewindCounterTo(int $value): void
    {
        $this->signCount = $value;
    }

    private function clientDataJson(string $type, string $challenge, string $origin): string
    {
        return (string) json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function authenticatorData(string $rpId, bool $attested): string
    {
        // UP | UV, plus AT on registration.
        $flags = 0b00000001 | 0b00000100 | ($attested ? 0b01000000 : 0);

        $data = hash('sha256', $rpId, true).chr($flags).pack('N', $this->signCount);

        if (! $attested) {
            return $data;
        }

        return $data.$this->aaguid.pack('n', strlen($this->credentialId)).$this->credentialId.$this->coseKey();
    }

    /** The COSE_Key (EC2, P-256, ES256) for the public half. */
    private function coseKey(): string
    {
        return $this->cborMap([
            1 => $this->cborInt(2),      // kty: EC2
            3 => $this->cborInt(-7),     // alg: ES256
            -1 => $this->cborInt(1),     // crv: P-256
            -2 => $this->cborBytes(str_pad((string) $this->keyDetails['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            -3 => $this->cborBytes(str_pad((string) $this->keyDetails['ec']['y'], 32, "\0", STR_PAD_LEFT)),
        ]);
    }

    private function sign(string $data): string
    {
        $signature = '';

        if (! openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The virtual authenticator could not sign the assertion.');
        }

        return $signature;
    }

    /** @param array<array-key, string> $entries already-encoded CBOR values keyed by their map key */
    private function cborMap(array $entries): string
    {
        $encoded = $this->cborHead(0b101, count($entries));

        foreach ($entries as $key => $value) {
            $encoded .= is_int($key) ? $this->cborInt($key) : $this->cborText($key);
            $encoded .= $value;
        }

        return $encoded;
    }

    private function cborText(string $value): string
    {
        return $this->cborHead(0b011, strlen($value)).$value;
    }

    private function cborBytes(string $value): string
    {
        return $this->cborHead(0b010, strlen($value)).$value;
    }

    private function cborInt(int $value): string
    {
        return $value >= 0
            ? $this->cborHead(0b000, $value)
            : $this->cborHead(0b001, -$value - 1);
    }

    private function cborHead(int $majorType, int $value): string
    {
        $major = $majorType << 5;

        return match (true) {
            $value < 24 => chr($major | $value),
            $value < 256 => chr($major | 24).chr($value),
            $value < 65536 => chr($major | 25).pack('n', $value),
            default => chr($major | 26).pack('N', $value),
        };
    }
}
