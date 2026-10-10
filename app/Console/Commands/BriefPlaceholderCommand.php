<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Briefs\Generation\PlaceholderBrief;
use App\Models\Brief;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Creates the install-time placeholder brief for an organization so the
 * `brief_placeholder` health check passes before any model is configured.
 * Idempotent: an organization that already has a brief is left alone.
 */
class BriefPlaceholderCommand extends Command
{
    protected $signature = 'lea:brief:placeholder
        {--organization= : Organization ULID; defaults to the only organization}';

    protected $description = 'Create the install-time placeholder brief (health contract §2)';

    public function handle(PlaceholderBrief $placeholder): int
    {
        $organization = $this->resolveOrganization();

        if ($organization === null) {
            $this->error('No organization found. Create the owner first (CREATE_OWNER).');

            return self::FAILURE;
        }

        $existing = Brief::query()->where('organization_id', $organization->id)->latestGenerated()->first();

        if ($existing !== null) {
            $this->comment("Organization {$organization->id} already has brief {$existing->document_id} v{$existing->version}; nothing to do.");

            return self::SUCCESS;
        }

        $this->info("Creating placeholder brief for organization {$organization->id}...");

        $brief = $placeholder->create($organization);

        $this->comment("Created placeholder brief {$brief->document_id} v{$brief->version}.");

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $id = $this->option('organization');

        if (is_string($id) && $id !== '') {
            return Organization::query()->find($id);
        }

        return Organization::query()->count() === 1 ? Organization::query()->first() : null;
    }
}
