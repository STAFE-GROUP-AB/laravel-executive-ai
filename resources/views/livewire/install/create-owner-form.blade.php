<section class="rounded-lg border border-default bg-surface p-6 shadow-1 sm:p-8">
    <p class="text-xs font-medium uppercase tracking-wide text-muted">Step 4 of 6</p>
    <h1 class="mt-1 text-xl font-semibold text-primary">Create the owner account</h1>
    <p class="mt-2 text-sm text-secondary">The only account with full access. You sign in with a one-time code mailed to this address — there is no password to remember or lose.</p>

    <form wire:submit="create" class="mt-6 space-y-4" novalidate>
        <div>
            <label for="name" class="block text-sm font-medium text-primary">Your name</label>
            <input id="name" type="text" wire:model="name" autocomplete="name" required autofocus
                class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface">
            @error('name') <p class="mt-1 text-sm text-danger-text" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-primary">Email address</label>
            <input id="email" type="email" wire:model="email" autocomplete="email" inputmode="email" required
                class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface">
            @error('email') <p class="mt-1 text-sm text-danger-text" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="organization" class="block text-sm font-medium text-primary">Organization</label>
            <input id="organization" type="text" wire:model="organization" autocomplete="organization" required
                class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface">
            @error('organization') <p class="mt-1 text-sm text-danger-text" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="timezone" class="block text-sm font-medium text-primary">Time zone</label>
                <select id="timezone" wire:model="timezone" required
                    class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface">
                    @foreach($timezones as $zone)
                        <option value="{{ $zone }}">{{ $zone }}</option>
                    @endforeach
                </select>
                @error('timezone') <p class="mt-1 text-sm text-danger-text" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="language" class="block text-sm font-medium text-primary">Brief language</label>
                <select id="language" wire:model="language" required
                    class="mt-1 block min-h-control-lg w-full rounded-md border border-strong bg-surface px-3 text-md text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface">
                    @foreach($languages as $tag => $label)
                        <option value="{{ $tag }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('language') <p class="mt-1 text-sm text-danger-text" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="create"
            class="inline-flex min-h-touch w-full items-center justify-center rounded-md bg-accent px-4 text-md font-medium text-on-accent hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-60"
        >
            <span wire:loading.remove wire:target="create">Create owner account</span>
            <span wire:loading wire:target="create">Creating…</span>
        </button>
    </form>
</section>
