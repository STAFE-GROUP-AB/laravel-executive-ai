<?php

declare(strict_types=1);

namespace App\Security\Authorization\Devices;

use App\Models\AuthDevice;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\SessionRevoker;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Device management (§11, docs/architecture/auth.md §5). A device is a
 * sign-in: the session it produced plus what the browser said about itself.
 * Listing joins `auth_devices` with the `sessions` table, so a device that
 * is no longer signed in (expired, logged out, revoked) is reported as such;
 * revoking goes through S1-02's `SessionRevoker`, which is still the only
 * code that deletes session rows. A revoked device is signed out on its next
 * request because its session row is gone, whatever cookie it still holds.
 */
final class DeviceRegistry
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SessionRevoker $revoker,
        private readonly AuditLog $audit,
        private readonly Session $session,
    ) {}

    /**
     * Record the sign-in that the current session represents. Call after
     * `session()->regenerate()`, so the row carries the id the browser will
     * actually present. The session id comes from the store rather than the
     * request, which has none in a Livewire action.
     */
    public function record(User $user, Request $request, LoginMethod $method, ?PasskeyCredential $passkey = null): AuthDevice
    {
        $sessionId = $this->session->getId();
        $userAgent = $request->userAgent();

        return AuthDevice::query()->updateOrCreate(
            ['session_id' => $sessionId],
            [
                'user_id' => $user->id,
                'method' => $method,
                'passkey_credential_id' => $passkey?->id,
                'label' => DeviceLabel::fromUserAgent($userAgent),
                'ip_address' => $request->ip(),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 1000),
                'signed_in_at' => CarbonImmutable::now('UTC'),
                'revoked_at' => null,
                'revoked_by' => null,
            ],
        );
    }

    /**
     * The user's devices that are still signed in, newest first, each with
     * the session's last activity. Devices whose session has gone are not
     * listed: they are already signed out.
     *
     * @return Collection<int, DeviceView>
     */
    public function active(User $user, ?string $currentSessionId = null): Collection
    {
        $sessions = $this->sessionsTable()
            ->where('user_id', $user->id)
            ->pluck('last_activity', 'id');

        return AuthDevice::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereIn('session_id', $sessions->keys()->all())
            ->orderByDesc('signed_in_at')
            ->get()
            ->map(fn (AuthDevice $device): DeviceView => new DeviceView(
                id: $device->id,
                label: $device->label,
                method: $device->method,
                passkeyName: $device->passkey_credential_id === null ? null : $device->passkey()->value('name'),
                ipAddress: $device->ip_address,
                signedInAt: $device->signed_in_at,
                lastActiveAt: CarbonImmutable::createFromTimestampUTC((int) $sessions->get($device->session_id, 0)),
                current: $currentSessionId !== null && hash_equals($currentSessionId, $device->session_id),
            ))
            ->values();
    }

    /** Sign one device out. Returns false when the device is not this user's or is already gone. */
    public function revoke(User $user, string $deviceId, string $actor): bool
    {
        $device = AuthDevice::query()->where('user_id', $user->id)->where('id', $deviceId)->first();

        if ($device === null || $device->revoked_at !== null) {
            return false;
        }

        $this->revoker->revokeSession($user, $device->session_id, $actor);
        $this->markRevoked($device, $actor);

        $this->audit->record('auth.device_revoked', $actor, AuditLog::OUTCOME_OK, 'auth_device', $device->id, ['method' => $device->method->value]);

        return true;
    }

    /** Sign every device out except the one making the request. */
    public function revokeOthers(User $user, string $currentSessionId, string $actor): int
    {
        $revoked = $this->revoker->revokeAll($user, $actor, exceptSessionId: $currentSessionId);

        AuthDevice::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('session_id', '!=', $currentSessionId)
            ->get()
            ->each(fn (AuthDevice $device) => $this->markRevoked($device, $actor));

        return $revoked;
    }

    private function markRevoked(AuthDevice $device, string $actor): void
    {
        $device->forceFill(['revoked_at' => CarbonImmutable::now('UTC'), 'revoked_by' => mb_substr($actor, 0, 60)])->save();
    }

    private function sessionsTable(): Builder
    {
        return $this->db->connection(config('session.connection'))->table((string) config('session.table', 'sessions'));
    }
}
