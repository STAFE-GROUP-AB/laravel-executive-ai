<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use App\Security\Authorization\Devices\DeviceRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Signed-in devices (S2-05, docs/architecture/auth.md §5): every session
 * that is still alive, how it signed in and when it was last active. Revoking
 * one or all others goes through S1-02's `SessionRevoker`; the device is
 * signed out on its next request. The current device cannot revoke itself
 * here — that is what "Log out" is for.
 */
final class Devices extends Component
{
    public ?string $notice = null;

    public function revoke(DeviceRegistry $devices, string $deviceId): void
    {
        $user = $this->user();
        $current = $devices->active($user, session()->getId())->first(fn ($device): bool => $device->id === $deviceId);

        if ($current?->current === true) {
            $this->addError('device', __('security.devices.cannot_revoke_current'));

            return;
        }

        $revoked = $devices->revoke($user, $deviceId, "user:{$user->id}");

        $this->notice = $revoked ? __('security.devices.revoked_one') : __('security.devices.already_gone');
        $this->resetErrorBag();
    }

    public function revokeOthers(DeviceRegistry $devices): void
    {
        $user = $this->user();
        $count = $devices->revokeOthers($user, session()->getId(), "user:{$user->id}");

        $this->notice = __('security.devices.revoked_others', ['count' => $count]);
        $this->resetErrorBag();
    }

    public function render(DeviceRegistry $devices): View
    {
        return view('livewire.auth.devices', [
            'devices' => $devices->active($this->user(), session()->getId()),
        ]);
    }

    private function user(): User
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
