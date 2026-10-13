<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\PasskeyCredential;
use App\Security\Authorization\Otp\OtpVerification;
use App\Security\Authorization\Passkeys\PasskeyAuthenticator;
use App\Security\Authorization\Passkeys\PasskeysUnavailableException;
use App\Security\Authorization\Reauthentication\CriticalAction;
use App\Security\Authorization\Reauthentication\Reauthenticator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The re-authentication card a consumer embeds next to a critical action
 * (docs/architecture/auth.md §7.4): `<livewire:auth.reauthenticate :action
 * :nonce />`. The owner proves presence with a passkey or with the emailed
 * code; on success the component dispatches `reauth-satisfied` with the
 * action and nonce, and the consumer calls `Reauthenticator::assert()`
 * before doing the thing. The component never performs the action itself.
 */
final class Reauthenticate extends Component
{
    #[Locked]
    public string $action;

    #[Locked]
    public string $nonce;

    public string $code = '';

    /** `choose`, `code` or `done`. */
    public string $step = 'choose';

    public ?string $notice = null;

    public function mount(string $action, string $nonce): void
    {
        $this->action = CriticalAction::from($action)->value;
        $this->nonce = $nonce;
    }

    public function startPasskey(Reauthenticator $reauth): void
    {
        try {
            $this->dispatch('passkey:get', id: $this->getId(), options: $reauth->passkeyOptions());
        } catch (PasskeysUnavailableException) {
            $this->addError('passkey', __('security.passkeys.errors.unavailable'));
        }
    }

    /** @param array<string, mixed> $credential */
    public function completePasskey(Reauthenticator $reauth, array $credential): void
    {
        if (! $reauth->satisfyWithPasskey($this->nonce, $credential)) {
            $this->addError('passkey', __('security.passkeys.errors.not_verified'));

            return;
        }

        $this->satisfied();
    }

    public function passkeyFailed(string $reason): void
    {
        $this->addError('passkey', __('security.passkeys.errors.browser', ['reason' => preg_replace('/[^A-Za-z]/', '', $reason) ?? '']));
    }

    public function sendCode(Reauthenticator $reauth): void
    {
        $issued = $reauth->sendCode();

        $this->code = '';
        $this->step = 'code';
        $this->notice = __('security.reauth.code_sent', ['minutes' => (int) config('lea.auth.otp.ttl_minutes')]);
        $this->resetErrorBag();

        if ($issued->reused) {
            $this->notice = __('security.reauth.code_reused');
        }
    }

    public function verifyCode(Reauthenticator $reauth): void
    {
        $this->validate(['code' => ['required', 'digits:'.(int) config('lea.auth.otp.length')]]);

        $submitted = $this->code;
        $this->code = '';

        $result = $reauth->satisfyWithOtp($this->nonce, $submitted);

        if ($result !== OtpVerification::Verified) {
            $this->addError('code', $result->message());

            return;
        }

        $this->satisfied();
    }

    public function back(): void
    {
        $this->step = 'choose';
        $this->code = '';
        $this->notice = null;
        $this->resetErrorBag();
    }

    public function render(PasskeyAuthenticator $passkeys): View
    {
        $user = Auth::guard('web')->user();
        $rpId = $passkeys->status()->rpId;

        $hasPasskey = $user !== null && $passkeys->isAvailable() && PasskeyCredential::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('rp_id', $rpId)
            ->exists();

        return view('livewire.auth.reauthenticate', [
            'criticalAction' => CriticalAction::from($this->action),
            'hasPasskey' => $hasPasskey,
            'codeLength' => (int) config('lea.auth.otp.length'),
        ]);
    }

    private function satisfied(): void
    {
        $this->step = 'done';
        $this->notice = null;
        $this->resetErrorBag();
        $this->dispatch('reauth-satisfied', action: $this->action, nonce: $this->nonce);
    }
}
