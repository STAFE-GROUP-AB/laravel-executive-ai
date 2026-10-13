{{--
    Passkeys (S2-05, docs/architecture/auth.md §7). No inline script: the
    ceremony runs in resources/js/passkeys.js, reached through the
    `passkey:create` browser event this component dispatches.
--}}
<section aria-labelledby="passkeys-heading" class="card" data-passkeys-panel data-passkeys-available="{{ $status->available ? 'true' : 'false' }}" data-passkeys-rp="{{ $status->rpId }}">
    <h2 id="passkeys-heading" class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('security.passkeys.heading') }}</h2>

    <p class="mt-3 max-w-readable text-sm text-secondary">{{ __('security.passkeys.lead') }}</p>

    @if(! $status->available)
        <p class="mt-4 rounded-md bg-info-surface px-3 py-2 text-sm text-info-text" role="status" data-passkeys-unavailable>{{ $status->message }}</p>
    @endif

    @if($notice)
        <p class="mt-4 rounded-md bg-info-surface px-3 py-2 text-sm text-info-text" role="status" data-passkeys-notice>{{ $notice }}</p>
    @endif

    @error('passkey')
        <p class="mt-4 rounded-md bg-danger-surface px-3 py-2 text-sm text-danger-text" role="alert" data-passkeys-error>{{ $message }}</p>
    @enderror

    <ul role="list" class="mt-5 space-y-3" data-passkey-list>
        @forelse($credentials as $credential)
            <li class="rounded-md border border-default px-4 py-3" data-passkey-row="{{ $credential->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-md font-medium break-words">{{ $credential->name }}</p>
                        <p class="mt-1 text-sm text-secondary">
                            {{ __('security.passkeys.bound_to', ['rp' => $credential->rp_id]) }}
                            @if($credential->backed_up)
                                · {{ __('security.passkeys.synced') }}
                            @endif
                        </p>
                        <p class="mt-1 text-xs text-muted">
                            @if($credential->last_used_at !== null)
                                {{ __('security.passkeys.last_used', ['when' => $credential->last_used_at->toDayDateTimeString()]) }}
                            @else
                                {{ __('security.passkeys.never_used') }}
                            @endif
                        </p>
                    </div>

                    <button type="button" wire:click="remove('{{ $credential->id }}')" wire:confirm="{{ __('security.passkeys.remove_confirm', ['name' => $credential->name]) }}" class="btn-secondary" data-passkey-remove>
                        {{ __('security.passkeys.remove') }}
                    </button>
                </div>
            </li>
        @empty
            <li class="rounded-md border border-default px-4 py-3 text-sm text-secondary" data-passkey-empty>{{ __('security.passkeys.none') }}</li>
        @endforelse
    </ul>

    @if($status->available)
        <form wire:submit="startRegistration" class="mt-6 space-y-3" novalidate>
            <div>
                <label for="passkey-name" class="block text-sm font-medium text-primary">{{ __('security.passkeys.name_label') }}</label>
                <input
                    id="passkey-name"
                    type="text"
                    wire:model="name"
                    maxlength="100"
                    autocomplete="off"
                    class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                >
                @error('name')
                    <p class="mt-1 text-sm text-danger-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="startRegistration" class="btn-primary" data-passkey-add>
                <span wire:loading.remove wire:target="startRegistration">{{ __('security.passkeys.add') }}</span>
                <span wire:loading wire:target="startRegistration">{{ __('security.passkeys.adding') }}</span>
            </button>
        </form>
    @endif

    <p class="mt-5 max-w-readable text-xs text-muted">{{ __('security.passkeys.fallback_note') }}</p>
</section>
