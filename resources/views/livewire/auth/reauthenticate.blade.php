{{--
    Re-authentication for one critical action (S2-05, docs/architecture/auth.md
    §7.4). Embedded by the consumer of the action; it proves presence and
    dispatches `reauth-satisfied`, and never performs the action itself.
--}}
<section aria-labelledby="reauth-heading-{{ $this->getId() }}" class="rounded-md border border-strong bg-surface p-4" data-reauth-panel data-reauth-action="{{ $criticalAction->value }}" data-reauth-step="{{ $step }}">
    <h2 id="reauth-heading-{{ $this->getId() }}" class="text-sm font-semibold text-primary">{{ __('security.reauth.heading') }}</h2>

    <p class="mt-2 max-w-readable text-sm text-secondary">{{ __('security.reauth.lead', ['action' => $criticalAction->label()]) }}</p>

    @if($notice)
        <p class="mt-3 rounded-md bg-info-surface px-3 py-2 text-sm text-info-text" role="status" data-reauth-notice>{{ $notice }}</p>
    @endif

    @error('passkey')
        <p class="mt-3 rounded-md bg-danger-surface px-3 py-2 text-sm text-danger-text" role="alert" data-reauth-error>{{ $message }}</p>
    @enderror

    @if($step === 'done')
        <p class="mt-3 rounded-md bg-success-surface px-3 py-2 text-sm text-success-text" role="status" data-reauth-satisfied>{{ __('security.reauth.satisfied') }}</p>
    @elseif($step === 'code')
        <form wire:submit="verifyCode" class="mt-4 space-y-3" novalidate>
            <div>
                <label for="reauth-code-{{ $this->getId() }}" class="block text-sm font-medium text-primary">{{ __('security.reauth.code_label') }}</label>
                <input
                    id="reauth-code-{{ $this->getId() }}"
                    type="text"
                    wire:model="code"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="{{ $codeLength }}"
                    required
                    class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 font-mono text-lg tracking-wide text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                >
                @error('code')
                    <p class="mt-1 text-sm text-danger-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="verifyCode" class="btn-primary" data-reauth-verify-code>{{ __('security.reauth.verify') }}</button>
                <button type="button" wire:click="back" class="btn-secondary">{{ __('security.reauth.back') }}</button>
            </div>
        </form>
    @else
        <div class="mt-4 flex flex-wrap gap-3">
            @if($hasPasskey)
                <button type="button" wire:click="startPasskey" wire:loading.attr="disabled" wire:target="startPasskey" class="btn-primary" data-reauth-passkey>{{ __('security.reauth.use_passkey') }}</button>
            @endif
            <button type="button" wire:click="sendCode" wire:loading.attr="disabled" wire:target="sendCode" class="btn-secondary" data-reauth-send-code>
                <span wire:loading.remove wire:target="sendCode">{{ __('security.reauth.email_code') }}</span>
                <span wire:loading wire:target="sendCode">{{ __('security.reauth.sending') }}</span>
            </button>
        </div>
    @endif
</section>
