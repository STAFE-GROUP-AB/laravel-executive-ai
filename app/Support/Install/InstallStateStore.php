<?php

declare(strict_types=1);

namespace App\Support\Install;

use App\Models\InstallStateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Reads and writes the install-state marker in the `install_state` table.
 * Before the schema exists (a fresh file, mid-migration) the marker reads
 * as not_installed instead of throwing, so the installer can poll it.
 */
final class InstallStateStore
{
    public function current(): InstallState
    {
        $latest = $this->latest();

        return $latest === null ? InstallState::NotInstalled : $latest->state;
    }

    public function latest(): ?InstallStateTransition
    {
        try {
            return InstallStateTransition::query()
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->first();
        } catch (QueryException) {
            return null;
        }
    }

    public function transition(InstallState $to, ?string $reason = null): InstallStateTransition
    {
        return InstallStateTransition::query()->create([
            'state' => $to,
            'app_version' => (string) config('app.version'),
            'reason' => $reason,
            'changed_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    /** @return Collection<int, InstallStateTransition> */
    public function history(): Collection
    {
        return InstallStateTransition::query()->orderBy('changed_at')->orderBy('id')->get();
    }
}
