<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\CreateOwner;
use App\Security\Authorization\Otp\OtpAutofill;
use App\Security\Authorization\Otp\OtpIssuer;
use App\Security\Authorization\Otp\OtpVerification;
use App\Security\Authorization\Otp\OtpVerifier;
use App\Security\Authorization\Passkeys\PasskeyAuthenticator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /login — two steps, one credential: email, then the six-digit code that
 * was mailed (docs/architecture/auth.md §2). No password field exists. The
 * response to an unknown address is identical to a known one, the send
 * button is disabled client-side while the request is in flight and the
 * issuer reuses a live code server-side, so a double click sends one mail.
 */
#[Layout('layouts.auth')]
#[Title('Sign in')]
final class Login extends Component
{
    public string $email = '';

    public string $code = '';

    /** `email` or `code`. */
    public string $step = 'email';

    public ?string $notice = null;

    public function mount(CreateOwner $owners, OtpAutofill $autofill): void
    {
        if (! $owners->ownerExists()) {
            $this->redirectRoute('install.owner');

            return;
        }

        if ($autofill->isActive()) {
            $this->email = (string) $autofill->ownerEmail();
        }
    }

    public function sendCode(OtpIssuer $issuer, OtpAutofill $autofill): void
    {
        $this->email = Str::lower(trim($this->email));

        $this->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);

        $email = $this->email;
        $limiterKey = 'lea:otp:request:'.sha1($email.'|'.(string) request()->ip());
        $maxRequests = (int) config('lea.auth.request_rate_limit.max_attempts');

        if (RateLimiter::tooManyAttempts($limiterKey, $maxRequests)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limiterKey) / 60);
            $this->notice = "Too many codes requested for this address. Try again in {$minutes} minute(s).";

            return;
        }

        RateLimiter::hit($limiterKey, (int) config('lea.auth.request_rate_limit.decay_seconds'));

        $this->code = '';
        $this->step = 'code';
        $this->notice = "If {$email} belongs to the owner account, a one-time code is on its way. It expires in {$issuer->ttlMinutes()} minutes.";

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        $issued = $issuer->issue($user, request()->ip());

        if ($autofill->isActive() && $issued->code() !== null) {
            $this->code = $issued->code();
            $this->verify(app(OtpVerifier::class), app(AuditLog::class));
        }
    }

    public function verify(OtpVerifier $verifier, AuditLog $audit): void
    {
        $this->validate(['code' => ['required', 'digits:'.(int) config('lea.auth.otp.length')]]);

        $user = User::query()->where('email', Str::lower(trim($this->email)))->first();
        $submitted = $this->code;
        $this->code = '';

        if ($user === null) {
            $this->addError('code', OtpVerification::Invalid->message());

            return;
        }

        $result = $verifier->verify($user, $submitted, request()->ip());

        if (! $result->succeeded()) {
            $this->addError('code', $result->message());

            return;
        }

        Auth::guard('web')->login($user);
        session()->regenerate();

        $audit->record('auth.logged_in', "user:{$user->id}", AuditLog::OUTCOME_OK, 'user', $user->id, ['method' => 'otp', 'ip_address' => request()->ip()]);

        $this->redirectIntended(default: route('home'));
    }

    public function changeEmail(): void
    {
        $this->step = 'email';
        $this->code = '';
        $this->notice = null;
        $this->resetErrorBag();
    }

    public function render(PasskeyAuthenticator $passkeys): View
    {
        return view('livewire.auth.login', [
            'passkeys' => $passkeys->status(),
            'codeLength' => (int) config('lea.auth.otp.length'),
        ]);
    }
}
