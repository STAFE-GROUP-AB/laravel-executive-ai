<?php

declare(strict_types=1);

namespace App\AI\Providers;

use App\Security\Secrets\SecretRef;
use InvalidArgumentException;

/**
 * One configured provider the owner set up: which catalogue row it is, where it
 * listens and which Keychain item holds its key (ADR-003). This is the input
 * ProviderRegistry builds an adapter from.
 *
 * Deliberately a DTO and not an Eloquent model: the `ai_providers` table is
 * S2-02 (#85). When it lands, its repository maps rows onto this shape and
 * nothing in the adapter, the registry or the router changes.
 *
 * It never carries a key value — only the reference the SecretStore resolves at
 * call time (ADR-004 D2 "provider construction").
 */
final readonly class ProviderConnection
{
    /** The drivers ADR-004 D1 allows; the fixture schema enforces the same list. */
    public const array DRIVERS = ['ollama', 'openai-compatible', 'openai', 'anthropic'];

    /**
     * @param  string  $providerId  catalogue id (`ollama`, `openai`, ...), not the driver
     * @param  ?string  $baseUrl  required for `openai-compatible`; defaults inside the SDK for `ollama`
     * @param  ?SecretRef  $keyRef  Keychain item holding the API key; null for a keyless local runtime
     * @param  list<string>  $modelIds  the models the owner configured on this provider
     * @param  array<string, string>  $headers  extra request headers the owner configured
     */
    public function __construct(
        public string $providerId,
        public string $driver,
        public ProviderKind $kind,
        public ?string $baseUrl = null,
        public ?SecretRef $keyRef = null,
        public array $modelIds = [],
        public array $headers = [],
        public ?RegionEvidence $regionEvidence = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $this->providerId) !== 1) {
            throw new InvalidArgumentException('A provider id must match the catalogue id pattern.');
        }

        if (! in_array($this->driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException("Driver [{$this->driver}] is not one of the ADR-004 D1 drivers.");
        }

        if ($this->driver === 'openai-compatible' && ($this->baseUrl === null || trim($this->baseUrl) === '')) {
            throw new InvalidArgumentException('An openai-compatible provider must carry its base URL.');
        }
    }

    public function isLocal(): bool
    {
        return $this->kind === ProviderKind::Local;
    }

    /**
     * Host the adapter will talk to, or null when the SDK's own default applies
     * (`ollama` without a configured URL). Used by the Local Only registry gate.
     */
    public function host(): ?string
    {
        if ($this->baseUrl === null) {
            return $this->driver === 'ollama' ? 'localhost' : null;
        }

        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
