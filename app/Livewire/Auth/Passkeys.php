<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Security\Authorization\Passkeys\PasskeyAuthenticator;
use App\Security\Authorization\Passkeys\PasskeyCeremonyFailed;
use App\Security\Authorization\Passkeys\PasskeysUnavailableException;
use App\Security\Authorization\Passkeys\WebAuthnAuthenticator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The owner's passkeys (S2-05, docs/architecture/auth.md §7): list, add,
 * remove. Registration is a two-step ceremony — the component hands the
 * browser creation options through the `passkey:create` event and receives
 * the credential back in `completeRegistration()`. Removing the last passkey
 * is allowed: the emailed code is always there as the fallback.
 */
final class Passkeys extends Component
{
    public string $name = '';

    public ?string $notice = null;

    public function startRegistration(PasskeyAuthenticator $passkeys): void
    {
        $this->validate(['name' => ['nullable', 'string', 'max:100']]);
        $this->notice = null;

        try {
            $this->dispatch('passkey:create', id: $this->getId(), options: $passkeys->registrationOptions($this->user()));
        } catch (PasskeysUnavailableException) {
            $this->addError('passkey', __('security.passkeys.errors.unavailable'));
        }
    }

    /** @param array<string, mixed> $credential */
    public function completeRegistration(PasskeyAuthenticator $passkeys, array $credential): void
    {
        try {
            $passkeys instanceof WebAuthnAuthenticator
                ? $passkeys->completeRegistration($this->user(), $credential, $this->name)
                : $passkeys->completeRegistration($this->user(), $credential);
        } catch (PasskeyCeremonyFailed $failure) {
            $this->addError('passkey', __('security.passkeys.errors.'.$failure->reason));

            return;
        } catch (PasskeysUnavailableException) {
            $this->addError('passkey', __('security.passkeys.errors.unavailable'));

            return;
        }

        $this->name = '';
        $this->notice = __('security.passkeys.registered');
        $this->resetErrorBag();
    }

    public function passkeyFailed(string $reason): void
    {
        $this->addError('passkey', __('security.passkeys.errors.browser', ['reason' => preg_replace('/[^A-Za-z]/', '', $reason) ?? '']));
    }

    public function remove(PasskeyAuthenticator $passkeys, string $credentialId): void
    {
        $user = $this->user();
        $credential = PasskeyCredential::query()->where('user_id', $user->id)->where('id', $credentialId)->first();

        if ($credential === null) {
            return;
        }

        if ($passkeys instanceof WebAuthnAuthenticator) {
            $passkeys->remove($user, $credential, "user:{$user->id}");
        }

        $this->notice = __('security.passkeys.removed', ['name' => $credential->name]);
        $this->resetErrorBag();
    }

    public function render(PasskeyAuthenticator $passkeys): View
    {
        return view('livewire.auth.passkeys', [
            'status' => $passkeys->status(),
            'credentials' => PasskeyCredential::query()
                ->where('user_id', $this->user()->id)
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'rp_id', 'origin', 'created_at', 'last_used_at', 'backed_up']),
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
