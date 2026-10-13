<section class="rounded-lg border border-default bg-surface p-6 shadow-1 sm:p-8">
    <h1 class="text-xl font-semibold text-primary">Sign in</h1>

    @if($step === 'email')
        <p class="mt-2 text-sm text-secondary">Enter the owner's email address. We will mail a one-time code; there is no password.</p>

        @error('passkey')
            <p class="mt-4 rounded-md bg-danger-surface px-3 py-2 text-sm text-danger-text" role="alert" data-login-passkey-error>{{ $message }}</p>
        @enderror

        <form wire:submit="sendCode" class="mt-6 space-y-4" novalidate>
            <div>
                <label for="email" class="block text-sm font-medium text-primary">Email address</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    wire:model="email"
                    autocomplete="username"
                    inputmode="email"
                    required
                    autofocus
                    class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                >
                @error('email')
                    <p class="mt-1 text-sm text-danger-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            @if($notice)
                <p class="rounded-md bg-warning-surface px-3 py-2 text-sm text-warning-text" role="status">{{ $notice }}</p>
            @endif

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="sendCode"
                class="inline-flex min-h-touch w-full items-center justify-center rounded-md bg-accent px-4 text-md font-medium text-on-accent hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="sendCode">Email me a code</span>
                <span wire:loading wire:target="sendCode">Sending…</span>
            </button>
        </form>

        @if($offerPasskey)
            {{-- The quick path where a passkey is registered for this origin. The code above stays the fallback. --}}
            <div class="mt-6 border-t border-default pt-5" data-login-passkey data-passkeys-rp="{{ $passkeys->rpId }}">
                <button
                    type="button"
                    wire:click="startPasskey"
                    wire:loading.attr="disabled"
                    wire:target="startPasskey"
                    class="inline-flex min-h-touch w-full items-center justify-center rounded-md border border-strong bg-surface px-4 text-md font-medium text-primary hover:bg-surface-sunken focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ __('security.passkeys.sign_in') }}
                </button>
                <p class="mt-2 text-xs text-muted">{{ __('security.passkeys.sign_in_hint') }}</p>
            </div>
        @endif
    @else
        <p class="mt-2 text-sm text-secondary">Enter the {{ $codeLength }}-digit code from the mail we sent to <span class="font-medium text-primary">{{ $email }}</span>.</p>

        @if($notice)
            <p class="mt-4 rounded-md bg-info-surface px-3 py-2 text-sm text-info-text" role="status">{{ $notice }}</p>
        @endif

        <form wire:submit="verify" class="mt-6 space-y-4" novalidate>
            <div>
                <label for="code" class="block text-sm font-medium text-primary">One-time code</label>
                <input
                    id="code"
                    type="text"
                    name="code"
                    wire:model="code"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="{{ $codeLength }}"
                    required
                    autofocus
                    class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 font-mono text-xl tracking-wide text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                >
                @error('code')
                    <p class="mt-1 text-sm text-danger-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="verify"
                class="inline-flex min-h-touch w-full items-center justify-center rounded-md bg-accent px-4 text-md font-medium text-on-accent hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="verify">Sign in</span>
                <span wire:loading wire:target="verify">Checking…</span>
            </button>
        </form>

        <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <button type="button" wire:click="sendCode" wire:loading.attr="disabled" class="min-h-touch text-accent underline-offset-2 hover:underline">Send a new code</button>
            <button type="button" wire:click="changeEmail" class="min-h-touch text-secondary underline-offset-2 hover:underline">Use a different address</button>
        </div>
    @endif

</section>
