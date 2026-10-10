<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Install\InstallState;
use App\Support\Install\InstallStateStore;
use Illuminate\Console\Command;

/**
 * `lea:install-state` prints the marker; `lea:install-state core_ready`
 * records a transition. The installer (S1-05) drives it through the
 * Supervisor; `--json` is for machines.
 */
class InstallStateCommand extends Command
{
    protected $signature = 'lea:install-state
        {state? : New state: not_installed, installing, core_ready or ai_ready}
        {--reason= : Short reason recorded with the transition}
        {--json : Print the marker as JSON}';

    protected $description = 'Read or set the install-state marker (§12.1)';

    public function handle(InstallStateStore $store): int
    {
        $requested = $this->argument('state');

        if (is_string($requested)) {
            $state = InstallState::tryFrom($requested);

            if ($state === null) {
                $this->error("Unknown install state `{$requested}`. Use one of: ".implode(', ', array_column(InstallState::cases(), 'value')));

                return self::INVALID;
            }

            $reason = $this->option('reason');
            $store->transition($state, is_string($reason) ? $reason : null);
        }

        $latest = $store->latest();
        $marker = [
            'state' => $store->current()->value,
            'changed_at' => $latest?->changed_at->toRfc3339String(),
            'app_version' => $latest?->app_version,
            'reason' => $latest?->reason,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($marker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->comment("Install state: {$marker['state']}");

        if ($latest !== null) {
            $this->line("Changed at {$marker['changed_at']} (app {$marker['app_version']})");
        }

        return self::SUCCESS;
    }
}
