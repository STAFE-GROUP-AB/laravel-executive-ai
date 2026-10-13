<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Security\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\TrustPath\TrustPath;

/**
 * Passkeys over web-auth/webauthn-lib (S2-05, docs/architecture/auth.md §7).
 *
 * One relying party per origin (ADR-006 D3): `rp.id` is the host the request
 * arrived on, and every ceremony is verified against exactly the origin its
 * challenge was issued for. The challenge lives in the session for the length
 * of one ceremony and nowhere else; the assertion payload is parsed, verified
 * and dropped. Audit events carry our row id and the rp id, never the
 * credential id, the client data or a signature. The library's validators keep
 * their default null logger and null event dispatcher on purpose — they would
 * otherwise log the full response at `info`.
 *
 * Verification is `attestation: none` (the authenticator's make is not a trust
 * decision here), resident credentials with user verification required, so
 * a passkey always proves presence *and* a local unlock, which is what the
 * re-authentication primitive relies on.
 */
final class WebAuthnAuthenticator implements PasskeyAuthenticator
{
    private const int ES256 = -7;

    private const int RS256 = -257;

    /**
     * The relying-party ids the library may verify over plain HTTP. `localhost`
     * is a secure context by the fetch standard, and it is the browser origin
     * the product opens on loopback (ADR-006 D3) precisely so this list has an
     * entry at all; the tailnet origin is HTTPS and needs none.
     */
    private const array SECURED_RP_IDS = ['localhost'];

    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly Request $request,
        private readonly PendingCeremonies $pending,
        private readonly AuditLog $audit,
    ) {}

    public function relyingParty(): RelyingParty
    {
        return RelyingParty::fromRequest($this->request);
    }

    public function isAvailable(): bool
    {
        return $this->relyingParty()->supportsPasskeys();
    }

    public function status(): PasskeyStatus
    {
        $relyingParty = $this->relyingParty();

        if ($relyingParty->isIpLiteral()) {
            return new PasskeyStatus(false, __('security.passkeys.status.ip_literal', ['origin' => $relyingParty->origin]), $relyingParty->id);
        }

        if (! $relyingParty->isSecureContext()) {
            return new PasskeyStatus(false, __('security.passkeys.status.insecure', ['origin' => $relyingParty->origin]), $relyingParty->id);
        }

        return new PasskeyStatus(true, __('security.passkeys.status.available', ['rp' => $relyingParty->id]), $relyingParty->id);
    }

    public function registrationOptions(User $user): array
    {
        $relyingParty = $this->requireAvailable();

        $exclude = PasskeyCredential::query()
            ->where('user_id', $user->id)
            ->where('rp_id', $relyingParty->id)
            ->get()
            ->map(fn (PasskeyCredential $credential): PublicKeyCredentialDescriptor => $this->descriptor($credential))
            ->all();

        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create($relyingParty->name, $relyingParty->id),
            user: PublicKeyCredentialUserEntity::create($user->email, $user->id, $user->name),
            challenge: random_bytes(32),
            pubKeyCredParams: [PublicKeyCredentialParameters::createPk(self::ES256), PublicKeyCredentialParameters::createPk(self::RS256)],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $exclude,
            timeout: $this->ceremonyTtlSeconds() * 1000,
        );

        $json = $this->toJson($options);
        $this->pending->store(PendingCeremonies::REGISTRATION, $json, $relyingParty, $this->ceremonyTtlSeconds(), $user->id);

        return $json;
    }

    /**
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeyCeremonyFailed
     */
    public function completeRegistration(User $user, array $credential, ?string $name = null): void
    {
        $relyingParty = $this->requireAvailable();
        $pending = $this->pending->pull(PendingCeremonies::REGISTRATION, $relyingParty);

        if ($pending['user_id'] !== $user->id) {
            throw new PasskeyCeremonyFailed(PasskeyCeremonyFailed::NO_PENDING_CEREMONY);
        }

        $publicKeyCredential = $this->parseCredential($credential);
        $response = $publicKeyCredential->response;

        if (! $response instanceof AuthenticatorAttestationResponse) {
            throw new PasskeyCeremonyFailed(PasskeyCeremonyFailed::MALFORMED_CREDENTIAL);
        }

        $options = $this->serializer()->deserialize(json_encode($pending['options'], JSON_THROW_ON_ERROR), PublicKeyCredentialCreationOptions::class, 'json');

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies($relyingParty)->creationCeremony())
                ->check($response, $options, $relyingParty->id);
        } catch (Throwable) {
            $this->audit->record('auth.passkey_registration_failed', "user:{$user->id}", AuditLog::OUTCOME_REFUSED, 'user', $user->id, ['rp_id' => $relyingParty->id, 'reason' => PasskeyCeremonyFailed::VERIFICATION_FAILED]);

            throw new PasskeyCeremonyFailed(PasskeyCeremonyFailed::VERIFICATION_FAILED);
        }

        $credentialId = Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId);

        // Through the hash, because that is where the unique index lives (#117);
        // the database refuses a duplicate even if this check races with itself.
        if (PasskeyCredential::query()->forCredentialId($credentialId)->exists()) {
            throw new PasskeyCeremonyFailed(PasskeyCeremonyFailed::ALREADY_REGISTERED);
        }

        $stored = PasskeyCredential::query()->create([
            'user_id' => $user->id,
            'name' => $this->labelFor($name),
            'credential_id' => $credentialId,
            'public_key' => base64_encode($record->credentialPublicKey),
            'user_handle' => $record->userHandle,
            'sign_count' => $record->counter,
            'aaguid' => $record->aaguid->toRfc4122(),
            'transports' => array_values($record->transports),
            'attestation_type' => $record->attestationType,
            'trust_path' => $this->normaliseTrustPath($record->trustPath),
            'backup_eligible' => $record->backupEligible,
            'backed_up' => $record->backupStatus,
            'uv_initialized' => $record->uvInitialized,
            'rp_id' => $relyingParty->id,
            'origin' => $relyingParty->origin,
        ]);

        $this->audit->record('auth.passkey_registered', "user:{$user->id}", AuditLog::OUTCOME_OK, 'passkey_credential', $stored->id, [
            'rp_id' => $relyingParty->id,
            'name' => $stored->name,
            'backup_eligible' => $stored->backup_eligible,
        ]);
    }

    public function authenticationOptions(string $email): array
    {
        $relyingParty = $this->requireAvailable();
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        $allow = $user === null ? [] : PasskeyCredential::query()
            ->where('user_id', $user->id)
            ->where('rp_id', $relyingParty->id)
            ->get()
            ->map(fn (PasskeyCredential $credential): PublicKeyCredentialDescriptor => $this->descriptor($credential))
            ->all();

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $relyingParty->id,
            allowCredentials: $allow,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: $this->ceremonyTtlSeconds() * 1000,
        );

        $json = $this->toJson($options);
        $this->pending->store(PendingCeremonies::AUTHENTICATION, $json, $relyingParty, $this->ceremonyTtlSeconds());

        return $json;
    }

    public function completeAuthentication(array $credential): ?User
    {
        $relyingParty = $this->requireAvailable();

        try {
            $pending = $this->pending->pull(PendingCeremonies::AUTHENTICATION, $relyingParty);
            $publicKeyCredential = $this->parseCredential($credential);
        } catch (PasskeyCeremonyFailed $failure) {
            return $this->refuse($relyingParty, $failure->reason);
        }

        $response = $publicKeyCredential->response;

        if (! $response instanceof AuthenticatorAssertionResponse) {
            return $this->refuse($relyingParty, PasskeyCeremonyFailed::MALFORMED_CREDENTIAL);
        }

        $stored = PasskeyCredential::query()
            ->forCredentialId(Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId))
            ->where('rp_id', $relyingParty->id)
            ->first();

        if ($stored === null) {
            return $this->refuse($relyingParty, 'unknown_credential');
        }

        $options = $this->serializer()->deserialize(json_encode($pending['options'], JSON_THROW_ON_ERROR), PublicKeyCredentialRequestOptions::class, 'json');

        try {
            $record = AuthenticatorAssertionResponseValidator::create($this->ceremonies($relyingParty)->requestCeremony())
                ->check($this->recordFor($stored), $response, $options, $relyingParty->id, $stored->user_handle);
        } catch (Throwable) {
            return $this->refuse($relyingParty, PasskeyCeremonyFailed::VERIFICATION_FAILED, $stored);
        }

        $user = $stored->user()->first();

        if ($user === null) {
            return $this->refuse($relyingParty, 'unknown_user', $stored);
        }

        $now = CarbonImmutable::now('UTC');

        $stored->forceFill([
            'sign_count' => $record->counter,
            'backup_eligible' => $record->backupEligible,
            'backed_up' => $record->backupStatus,
            'uv_initialized' => $record->uvInitialized,
            'last_used_at' => $now,
        ])->save();

        $user->forceFill([
            'last_login_at' => $now,
            'login_count' => ((int) $user->getAttribute('login_count')) + 1,
            'email_verified_at' => $user->getAttribute('email_verified_at') ?? $now,
        ])->save();

        $this->audit->record('auth.passkey_verified', "user:{$user->id}", AuditLog::OUTCOME_OK, 'passkey_credential', $stored->id, ['rp_id' => $relyingParty->id, 'ip_address' => $this->request->ip()]);

        return $user;
    }

    public function remove(User $user, PasskeyCredential $credential, string $actor): void
    {
        if ($credential->user_id !== $user->id) {
            return;
        }

        $credential->delete();

        $this->audit->record('auth.passkey_removed', $actor, AuditLog::OUTCOME_OK, 'passkey_credential', $credential->id, ['rp_id' => $credential->rp_id, 'name' => $credential->name]);
    }

    public function ceremonyTtlSeconds(): int
    {
        return max(30, (int) config('lea.auth.passkeys.ceremony_ttl_seconds', 120));
    }

    private function requireAvailable(): RelyingParty
    {
        $relyingParty = $this->relyingParty();

        if (! $relyingParty->supportsPasskeys()) {
            throw PasskeysUnavailableException::forOrigin($relyingParty);
        }

        return $relyingParty;
    }

    /** Records why an assertion was not acceptable and answers "no user", which is all a caller learns. */
    private function refuse(RelyingParty $relyingParty, string $reason, ?PasskeyCredential $credential = null): null
    {
        $this->audit->record(
            'auth.passkey_login_failed',
            $credential === null ? 'job:PasskeyLogin' : "user:{$credential->user_id}",
            AuditLog::OUTCOME_REFUSED,
            $credential === null ? null : 'passkey_credential',
            $credential?->id,
            ['rp_id' => $relyingParty->id, 'reason' => $reason, 'ip_address' => $this->request->ip()],
        );

        return null;
    }

    /**
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeyCeremonyFailed
     */
    private function parseCredential(array $credential): PublicKeyCredential
    {
        try {
            return $this->serializer()->deserialize(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class, 'json');
        } catch (Throwable) {
            // Anything the browser or an attacker sends that is not a credential lands here;
            // the reason is a fixed code, never the payload that failed to parse.
            throw new PasskeyCeremonyFailed(PasskeyCeremonyFailed::MALFORMED_CREDENTIAL);
        }
    }

    private function recordFor(PasskeyCredential $credential): CredentialRecord
    {
        return CredentialRecord::create(
            publicKeyCredentialId: Base64UrlSafe::decodeNoPadding($credential->credential_id),
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: $credential->transports,
            attestationType: $credential->attestation_type,
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString($credential->aaguid),
            credentialPublicKey: (string) base64_decode($credential->public_key, true),
            userHandle: $credential->user_handle,
            counter: $credential->sign_count,
            backupEligible: $credential->backup_eligible,
            backupStatus: $credential->backed_up,
            uvInitialized: $credential->uv_initialized,
        );
    }

    private function descriptor(PasskeyCredential $credential): PublicKeyCredentialDescriptor
    {
        return PublicKeyCredentialDescriptor::create(
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            Base64UrlSafe::decodeNoPadding($credential->credential_id),
            $credential->transports,
        );
    }

    private function ceremonies(RelyingParty $relyingParty): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins([$relyingParty->origin]);
        $factory->setSecuredRelyingPartyId(self::SECURED_RP_IDS);
        $factory->setAttestationStatementSupportManager($this->attestationSupport());

        return $factory;
    }

    private function attestationSupport(): AttestationStatementSupportManager
    {
        return AttestationStatementSupportManager::create([NoneAttestationStatementSupport::create()]);
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory($this->attestationSupport()))->create();
    }

    /** @return array<string, mixed> */
    private function toJson(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        $json = $this->serializer()->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function normaliseTrustPath(TrustPath $trustPath): array
    {
        return ['type' => $trustPath::class];
    }

    /** The owner's own label for the device, or a dated default; never derived from the credential. */
    private function labelFor(?string $name): string
    {
        $label = trim((string) $name);

        if ($label === '') {
            $label = __('security.passkeys.default_name', ['date' => CarbonImmutable::now('UTC')->toDateString()]);
        }

        return Str::limit($label, 100, '');
    }
}
