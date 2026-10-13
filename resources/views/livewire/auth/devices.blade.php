{{--
    Signed-in devices (S2-05, docs/architecture/auth.md §5). Every row is a
    live session; revoking one goes through S1-02's SessionRevoker and the
    browser is signed out on its next request.
--}}
<section aria-labelledby="devices-heading" class="card" data-devices-panel>
    <h2 id="devices-heading" class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('security.devices.heading') }}</h2>

    <p class="mt-3 max-w-readable text-sm text-secondary">{{ __('security.devices.lead') }}</p>

    @if($notice)
        <p class="mt-4 rounded-md bg-info-surface px-3 py-2 text-sm text-info-text" role="status" data-devices-notice>{{ $notice }}</p>
    @endif

    @error('device')
        <p class="mt-4 rounded-md bg-danger-surface px-3 py-2 text-sm text-danger-text" role="alert" data-devices-error>{{ $message }}</p>
    @enderror

    <ul role="list" class="mt-5 space-y-3" data-device-list data-device-count="{{ $devices->count() }}">
        @foreach($devices as $device)
            <li class="rounded-md border border-default px-4 py-3" data-device-row="{{ $device->id }}" data-device-current="{{ $device->current ? 'true' : 'false' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-md font-medium break-words">
                            {{ $device->label }}
                            @if($device->current)
                                <x-badge tone="accent">{{ __('security.devices.this_device') }}</x-badge>
                            @endif
                        </p>
                        <p class="mt-1 text-sm text-secondary" data-device-method="{{ $device->method->value }}">
                            {{ __('security.devices.methods.'.$device->method->value) }}
                            @if($device->passkeyName !== null)
                                · {{ $device->passkeyName }}
                            @endif
                        </p>
                        <p class="mt-1 text-xs text-muted">
                            {{ __('security.devices.signed_in', ['when' => $device->signedInAt->toDayDateTimeString()]) }}
                            · {{ __('security.devices.last_active', ['when' => $device->lastActiveAt->diffForHumans()]) }}
                            @if($device->ipAddress !== null)
                                · {{ $device->ipAddress }}
                            @endif
                        </p>
                    </div>

                    @unless($device->current)
                        <button type="button" wire:click="revoke('{{ $device->id }}')" class="btn-secondary" data-device-revoke>{{ __('security.devices.revoke') }}</button>
                    @endunless
                </div>
            </li>
        @endforeach
    </ul>

    @if($devices->count() > 1)
        <button type="button" wire:click="revokeOthers" wire:confirm="{{ __('security.devices.revoke_others_confirm') }}" class="btn-secondary mt-6" data-device-revoke-others>
            {{ __('security.devices.revoke_others') }}
        </button>
    @endif
</section>
